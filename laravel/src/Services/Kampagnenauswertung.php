<?php

namespace Peppermint\MassMailer\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\MailDispatchVersuch;
use Peppermint\MassMailer\Support\Modelle;

/**
 * Die Bilanz eines Versands — beurteilen, ohne 2500 Zeilen zu scrollen.
 *
 * Hier steht, was eine Entscheidung traegt, und nicht alles, was sich zaehlen
 * laesst. Drei Trennungen machen den Unterschied zwischen einer Auswertung und
 * einer Zahlenwand:
 *
 * 1. **`status` und `zustellung_status` sind zwei Fragen.** „Verschickt" heisst
 *    angenommen, „zugestellt" heisst angekommen. Die Luecke dazwischen ist die
 *    Zahl, nach der jemand sucht, der eine Beschwerde bearbeitet.
 * 2. **Dauerhafte und voruebergehende Rueckläufer werden nicht addiert.**
 *    Dauerhaft heisst „die Adresse ist tot", voruebergehend heisst „der Server
 *    war beschaeftigt". Wer beides zusammenzaehlt, haelt einen gesunden Versand
 *    fuer kaputt: Am 04.09.2026 meldete Mailgun 114 Fehlschlaege, von denen 96
 *    blosses Graylisting waren.
 * 3. **Sendeversuche sind kein Zustellproblem.** Ein gescheiterter Versuch
 *    liegt zwischen uns und dem Postausgang (abgerissene Verbindung,
 *    Zeitlimit), eine gescheiterte Zustellung zwischen Postausgang und
 *    Empfaenger. Beides in einen Topf zu werfen hiesse, einen Serverfehler bei
 *    uns als tote Adresse auszuweisen.
 *
 * **Oeffnungen und Klicks fehlen bewusst.** Eine „Oeffnung" ist ein geladenes
 * Zaehlbild — neben einer Zustellquote gleichrangig gezeigt, lueden sie zu
 * Schluessen ein, die die Zahl nicht hergibt.
 */
class Kampagnenauswertung
{
    public function __construct(private MailgunKonto $konto) {}

    /**
     * Alles, was die Auswertungsseite einer Kampagne braucht.
     *
     * @return array{summary: array<string, int>, zustellung: array<string, mixed>, auswertung: array<string, mixed>, versuche: array<string, mixed>}
     */
    public function fuer(Model $kampagne): array
    {
        $dispatches = $this->dispatches($kampagne);

        return [
            'summary' => $this->stand($dispatches),
            'zustellung' => $this->zustellung($dispatches),
            'auswertung' => $this->bilanz($kampagne, $dispatches),
            'versuche' => $this->versuchsbilanz($dispatches),
        ];
    }

    /**
     * Die Protokollzeilen der Kampagne — ohne Mandanten-Scope.
     *
     * Ueber {@see Modelle::protokollAbfrage()} und nicht ueber die Beziehung
     * am Modell: Die Auswertung laeuft auch in einem Kommando (Bericht,
     * Aufraeumlauf), und dort ist kein Mandant aktiv. Der Scope wuerde dann
     * alles wegfiltern und eine leere Bilanz als „nichts passiert" ausweisen.
     *
     * @return Collection<int, Model>
     */
    private function dispatches(Model $kampagne): Collection
    {
        return Modelle::protokollAbfrage()
            ->where('kampagne_id', $kampagne->getKey())
            ->get([
                'id', 'empfaenger', 'status', 'fehler', 'verschickt_am', 'fehlgeschlagen_am',
                'versuche', 'zustellung_status', 'zustellung_am', 'zustellung_grund',
                'zustellung_wiederholt',
            ]);
    }

    /**
     * @param  Collection<int, Model>  $dispatches
     * @return array<string, int>
     */
    private function stand(Collection $dispatches): array
    {
        return [
            MailDispatch::STATUS_VERSCHICKT => $dispatches->where('status', MailDispatch::STATUS_VERSCHICKT)->count(),
            MailDispatch::STATUS_IM_VERSAND => $dispatches->where('status', MailDispatch::STATUS_IM_VERSAND)->count(),
            MailDispatch::STATUS_FEHLGESCHLAGEN => $dispatches->where('status', MailDispatch::STATUS_FEHLGESCHLAGEN)->count(),
        ];
    }

    /**
     * Was der Anbieter sagt — getrennt gezaehlt von dem, was wir wissen.
     *
     * `verbunden` entscheidet, ob die Oberflaeche diese Spalte ueberhaupt
     * zeigt: Ohne verbundenes Konto bleibt sie leer, und „0 zugestellt" waere
     * dann eine Behauptung ueber etwas, das nie gemessen wurde.
     *
     * @param  Collection<int, Model>  $dispatches
     * @return array<string, mixed>
     */
    private function zustellung(Collection $dispatches): array
    {
        $zaehlen = fn (string $wert): int => $dispatches->where('zustellung_status', $wert)->count();

        return [
            'gemessen' => $dispatches->whereNotNull('zustellung_status')->count(),
            MailDispatch::ZUSTELLUNG_ZUGESTELLT => $zaehlen(MailDispatch::ZUSTELLUNG_ZUGESTELLT),
            MailDispatch::ZUSTELLUNG_VERZOEGERT => $zaehlen(MailDispatch::ZUSTELLUNG_VERZOEGERT),
            MailDispatch::ZUSTELLUNG_UNZUSTELLBAR => $zaehlen(MailDispatch::ZUSTELLUNG_UNZUSTELLBAR),
            MailDispatch::ZUSTELLUNG_ABGEWIESEN => $zaehlen(MailDispatch::ZUSTELLUNG_ABGEWIESEN),
            MailDispatch::ZUSTELLUNG_BESCHWERDE => $zaehlen(MailDispatch::ZUSTELLUNG_BESCHWERDE),
            'verbunden' => $this->konto->istVerbunden(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $dispatches
     * @return array<string, mixed>
     */
    private function bilanz(Model $kampagne, Collection $dispatches): array
    {
        $verschickt = $dispatches->where('status', MailDispatch::STATUS_VERSCHICKT)->count();
        $zugestellt = $dispatches->where('zustellung_status', MailDispatch::ZUSTELLUNG_ZUGESTELLT)->count();

        $dauerhaft = $dispatches->whereIn('zustellung_status', [
            MailDispatch::ZUSTELLUNG_UNZUSTELLBAR,
            MailDispatch::ZUSTELLUNG_ABGEWIESEN,
            MailDispatch::ZUSTELLUNG_BESCHWERDE,
        ])->count();

        $minuten = $this->laufzeit($kampagne, $dispatches);

        return [
            'verschickt' => $verschickt,
            'zugestellt' => $zugestellt,
            // Anteil an den TATSAECHLICH verschickten und nicht an den
            // eingereihten: Was noch in der Warteschlange liegt, ist weder
            // gelungen noch gescheitert und wuerde die Quote nur druecken.
            'zustellquote' => $verschickt > 0 ? round($zugestellt / $verschickt * 100, 1) : null,
            'ruecklaeufer_dauerhaft' => $dauerhaft,
            'ruecklaeufer_voruebergehend' => $dispatches->where('zustellung_status', MailDispatch::ZUSTELLUNG_VERZOEGERT)->count(),
            // Empfaenger, deren Server erst abgelehnt und spaeter angenommen
            // hat — meist Graylisting. Sie stehen am Ende als „zugestellt" da,
            // tauchen beim Anbieter aber als Fehlschlag auf. Ohne diese Zahl
            // sieht die Anbieter-Statistik nach einem Problem aus, das keines
            // war.
            'graylisting' => $dispatches->where('zustellung_wiederholt', true)->count(),
            'graylisting_zugestellt' => $dispatches
                ->where('zustellung_wiederholt', true)
                ->where('zustellung_status', MailDispatch::ZUSTELLUNG_ZUGESTELLT)
                ->count(),
            'versuche_gesamt' => (int) $dispatches->sum('versuche'),
            'mit_wiederholung' => $dispatches->filter(fn (Model $d): bool => (int) ($d->versuche ?? 1) > 1)->count(),
            'laufzeit_minuten' => $minuten,
            'rate_je_stunde' => $minuten !== null && $minuten > 0
                ? (int) round($verschickt / ($minuten / 60))
                : null,
            'haeufigste_gruende' => $this->haeufigsteGruende($dispatches),
        ];
    }

    /**
     * Wie lange der Versand lief — bis zur letzten Mail, nicht bis jetzt.
     *
     * Bei einem Versand, der gestern endete, waere „Laufzeit" sonst die Zeit
     * seit gestern — und das Tempo entsprechend erfunden.
     *
     * @param  Collection<int, Model>  $dispatches
     */
    private function laufzeit(Model $kampagne, Collection $dispatches): ?int
    {
        $begonnen = $kampagne->getAttribute('gestartet_am');
        $zuletzt = $dispatches->max('verschickt_am');

        if ($begonnen === null || $zuletzt === null) {
            return null;
        }

        return max(1, (int) $begonnen->diffInMinutes($zuletzt));
    }

    /**
     * Die haeufigsten Wortlaute statt einer Prozentzahl.
     *
     * „4x 550 5.7.1 Command rejected" sagt jemandem, der nachsieht, was zu tun
     * ist. NUR Problemfaelle: Der Anbieter quittiert auch den Erfolg im
     * Klartext („250 2.0.0 Queued email for delivery"), und weil das der
     * haeufigste Fall ist, stuenden die Erfolgsmeldungen sonst ganz oben — und
     * verdraengten genau die Zeilen, wegen derer jemand hier nachsieht.
     *
     * @param  Collection<int, Model>  $dispatches
     * @return array<int, array{grund: string, anzahl: int}>
     */
    private function haeufigsteGruende(Collection $dispatches): array
    {
        return $dispatches
            ->filter(fn (Model $d): bool => $d->zustellung_status !== MailDispatch::ZUSTELLUNG_ZUGESTELLT)
            ->filter(fn (Model $d): bool => filled($d->fehler) || filled($d->zustellung_grund))
            ->groupBy(fn (Model $d): string => mb_substr((string) ($d->fehler ?? $d->zustellung_grund), 0, 90))
            ->map(fn (Collection $gruppe, string $grund): array => ['grund' => $grund, 'anzahl' => $gruppe->count()])
            ->sortByDesc('anzahl')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Woran die Sendeversuche gescheitert sind.
     *
     * Sichtbar wird damit das Muster, das der Versand vom 03.–05.09.2026
     * gezeigt hat: alle rund siebzehn Minuten eine abgerissene SMTP-Verbindung,
     * jedes Mal aufgefangen durch einen Wiederholungsversuch.
     *
     * @param  Collection<int, Model>  $dispatches
     * @return array<string, mixed>
     */
    private function versuchsbilanz(Collection $dispatches): array
    {
        $klasse = Modelle::versuch();

        $versuche = $klasse::query()
            ->withoutGlobalScopes()
            ->whereIn('mail_dispatch_id', $dispatches->pluck('id'))
            ->get(['ergebnis', 'fehler']);

        return [
            'gesamt' => $versuche->count(),
            'gescheitert' => $versuche->where('ergebnis', MailDispatchVersuch::ERGEBNIS_FEHLSCHLAG)->count(),
            // Ohne Ergebnis heisst „abgebrochen": Der Worker wurde beendet,
            // bevor er berichten konnte — typisch bei einem Deploy mitten im
            // Versand. Ein eigener Zustand, kein Fehlschlag.
            'abgebrochen' => $versuche->whereNull('ergebnis')->count(),
            'gruende' => $versuche
                ->where('ergebnis', MailDispatchVersuch::ERGEBNIS_FEHLSCHLAG)
                ->filter(fn (Model $v): bool => filled($v->fehler))
                ->groupBy(fn (Model $v): string => mb_substr((string) $v->fehler, 0, 90))
                ->map(fn (Collection $gruppe, string $grund): array => ['grund' => $grund, 'anzahl' => $gruppe->count()])
                ->sortByDesc('anzahl')
                ->take(5)
                ->values()
                ->all(),
        ];
    }
}
