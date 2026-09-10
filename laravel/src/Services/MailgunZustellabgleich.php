<?php

namespace Peppermint\MassMailer\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Peppermint\MassMailer\Contracts\Sperrliste;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Support\Zustellfehler;
use Throwable;

/**
 * Holt bei Mailgun nach, was aus unseren Mails geworden ist (#4834).
 *
 * ## Warum abfragen und nicht auf Meldungen warten
 *
 * Mailgun kann uns bei jedem Ereignis anrufen (Webhook). Der Weg ist schneller,
 * hat aber eine Eigenschaft, die hier schwerer wiegt: Er kennt keine
 * Vergangenheit. Eine Meldung, die waehrend eines Ausfalls oder vor der
 * Einrichtung entstand, ist verloren.
 *
 * Die Abfrage kann nachholen. Sie laeuft im Takt des Zeitplans, deckt jedes
 * Mal ein ueberlappendes Fenster ab und darf deshalb auch mal ausfallen. Fuer
 * ein Protokoll, dessen Zweck das nachtraegliche Nachsehen ist, ist das die
 * richtige Eigenschaft.
 *
 * Die Grenze davon ist Mailguns Gedaechtnis: Ereignisse werden dort nur
 * begrenzt aufbewahrt (bei kleinen Tarifen teils nur einen Tag). Was aelter
 * ist, ist endgueltig weg — ein dauerhaft ausgefallener Abgleich ist deshalb
 * echter Datenverlust und nicht bloss eine Verzoegerung.
 *
 * ## Was hier NICHT passiert
 *
 * Oeffnungen und Klicks. Die misst die Anwendung selbst ueber Zaehlpixel und
 * Klick-Umleitung ({@see Mailmessung}), und zwar nur dort, wo die
 * Veranstaltung es eingeschaltet hat. Diese Entscheidung ueber Mailgun zu
 * umgehen waere eine stille Messung hinter dem Ruecken derer, die sie
 * ausgeschaltet haben.
 */
class MailgunZustellabgleich
{
    /**
     * Die Ereignisse, die etwas ueber die Zustellung aussagen.
     *
     * `accepted` fehlt mit Absicht: Dass Mailgun die Mail angenommen hat,
     * wissen wir schon — das ist genau die Aussage von `verschickt`. Es
     * mitzuholen hiesse, die Antwortmenge zu vervielfachen, ohne eine Frage zu
     * beantworten.
     *
     * @var array<int, string>
     */
    private const ARTEN = ['delivered', 'failed', 'rejected', 'complained'];

    /**
     * Wie weit ein Ereignis von einer Protokollzeile entfernt liegen darf,
     * damit die Rueckfall-Zuordnung ueber die Adresse noch gilt.
     */
    private const ZUORDNUNGSFENSTER_MINUTEN = 90;

    public function __construct(
        private readonly MailgunKonto $konto,
        private readonly ?Sperrliste $sperrliste = null,
    ) {}

    /**
     * Gleicht einen Zeitraum ab.
     *
     * @return array{ereignisse: int, zugeordnet: int, gesperrt: int, ohne_zuordnung: int}
     */
    public function fuerZeitraum(CarbonImmutable $von, ?CarbonImmutable $bis = null): array
    {
        $bilanz = ['ereignisse' => 0, 'zugeordnet' => 0, 'gesperrt' => 0, 'ohne_zuordnung' => 0];

        foreach ($this->konto->ereignisse(self::ARTEN, $von, $bis) as $ereignis) {
            $bilanz['ereignisse']++;

            $eintrag = $this->zeileZu($ereignis);

            if ($eintrag === null) {
                $bilanz['ohne_zuordnung']++;

                continue;
            }

            $bilanz['zugeordnet']++;

            if ($this->uebernehmen($eintrag, $ereignis)) {
                $bilanz['gesperrt']++;
            }
        }

        return $bilanz;
    }

    /**
     * Die Protokollzeile zu einem Ereignis.
     *
     * Zwei Wege, und der erste ist der einzige verlaessliche: Beim Versand
     * reist die Nummer der Zeile als Mailgun-Variable mit
     * ({@see Versandprotokoll::mailgunSchluessel()}).
     *
     * Der zweite Weg ist fuer Mails von VOR dieser Aenderung — die tragen die
     * Variable nicht. Er ordnet ueber Adresse und Zeitpunkt zu und tut das nur,
     * wenn dabei GENAU EINE Zeile herauskommt. Bei zwei gleichen Mails an
     * dieselbe Person waere jede Wahl geraten, und ein falsch zugeordneter
     * Rueckläufer sperrt am Ende die Adresse eines Unbeteiligten.
     *
     * @param  array<string, mixed>  $ereignis
     */
    private function zeileZu(array $ereignis): ?MailDispatch
    {
        $nummer = $ereignis['user-variables'][app(Versandprotokoll::class)->mailgunSchluessel()] ?? null;

        if ($nummer !== null && ctype_digit((string) $nummer)) {
            return MailDispatch::query()->find((int) $nummer);
        }

        $empfaenger = trim((string) ($ereignis['recipient'] ?? ''));
        $zeitpunkt = $this->zeitpunkt($ereignis);

        if ($empfaenger === '' || $zeitpunkt === null) {
            return null;
        }

        $treffer = MailDispatch::query()
            ->where('empfaenger', $empfaenger)
            ->whereNull('zustellung_status')
            ->whereBetween('created_at', [
                $zeitpunkt->subMinutes(self::ZUORDNUNGSFENSTER_MINUTEN),
                $zeitpunkt->addMinutes(self::ZUORDNUNGSFENSTER_MINUTEN),
            ])
            ->limit(2)
            ->get();

        return $treffer->count() === 1 ? $treffer->first() : null;
    }

    /**
     * Traegt das Ereignis in die Zeile ein.
     *
     * @param  array<string, mixed>  $ereignis
     * @return bool ob dabei eine Adresse gesperrt wurde
     */
    private function uebernehmen(MailDispatch $eintrag, array $ereignis): bool
    {
        $zustand = $this->zustandAus($ereignis);
        $grund = $this->grundAus($ereignis);
        $zeitpunkt = $this->zeitpunkt($ereignis) ?? CarbonImmutable::now();

        $werte = [
            'zustellung_status' => $zustand,
            'zustellung_am' => $zeitpunkt,
            'zustellung_grund' => $grund !== '' ? $grund : null,
            'zustellung_geprueft_am' => CarbonImmutable::now(),
        ];

        // Der voruebergehende Fehlschlag verschwindet gleich wieder aus
        // `zustellung_status`, sobald die Mail doch ankommt — deshalb wird er
        // hier zusaetzlich vermerkt und nie zurueckgenommen. Ohne diesen
        // Merker steht am Ende „zugestellt", waehrend beim Anbieter ein
        // Fehlschlag verbucht ist, und die beiden Zahlen widersprechen sich
        // scheinbar. Gesetzt, nicht gezaehlt: ueberlappende Abgleichsfenster
        // liefern dasselbe Ereignis mehrfach.
        if ($zustand === MailDispatch::ZUSTELLUNG_VERZOEGERT) {
            $werte['zustellung_wiederholt'] = true;
        }

        if (($kennung = $ereignis['message']['headers']['message-id'] ?? null) !== null) {
            $werte['anbieter_message_id'] = (string) $kennung;
        }

        $werte += $this->statusNachzug($eintrag, $zustand, $zeitpunkt, $grund);

        $eintrag->update($werte);

        return $this->adresseSperren($eintrag, $zustand, $grund);
    }

    /**
     * Zieht `status` nach, wenn der Anbieter mehr weiss als wir.
     *
     * Hier loesen sich die Zeilen auf, die auf `im_versand` haengengeblieben
     * sind: Der Job wurde abgebrochen, bevor er Erfolg oder Fehlschlag melden
     * konnte, und seither steht dort ein Zustand, den niemand aufloesen konnte.
     * Mailguns Auskunft, dass die Mail zugestellt wurde, ist genau die Messung,
     * die dafuer gefehlt hat.
     *
     * **Nur nach oben, nie zurueck.** Eine Zeile, die schon `fehlgeschlagen`
     * ist, bleibt es — der Versuch IST gescheitert, auch wenn ein spaeterer
     * durchkam. Wer das ueberschreibt, loescht die Spur des Vorfalls.
     *
     * @return array<string, mixed>
     */
    private function statusNachzug(
        MailDispatch $eintrag,
        string $zustand,
        CarbonImmutable $zeitpunkt,
        string $grund,
    ): array {
        if ($eintrag->status !== MailDispatch::STATUS_IM_VERSAND) {
            return [];
        }

        if ($zustand === MailDispatch::ZUSTELLUNG_ZUGESTELLT) {
            return [
                'status' => MailDispatch::STATUS_VERSCHICKT,
                'verschickt_am' => $eintrag->verschickt_am ?? $zeitpunkt,
            ];
        }

        if (in_array($zustand, [
            MailDispatch::ZUSTELLUNG_UNZUSTELLBAR,
            MailDispatch::ZUSTELLUNG_ABGEWIESEN,
        ], true)) {
            return [
                'status' => MailDispatch::STATUS_FEHLGESCHLAGEN,
                'fehler' => $grund !== '' ? $grund : 'Von Mailgun als '.$zustand.' gemeldet.',
                'fehlgeschlagen_am' => $eintrag->fehlgeschlagen_am ?? $zeitpunkt,
            ];
        }

        // `verzoegert` laesst den Status stehen: Mailgun versucht es weiter,
        // und die Mail kann noch ankommen.
        return [];
    }

    /**
     * Vermerkt die Sperre — dieselbe Regel wie beim eigenen Versand.
     *
     * WOHIN vermerkt wird, entscheidet die Anwendung ({@see Sperrliste}). Ohne
     * gebundene Sperrliste passiert nichts; der Abgleich bleibt trotzdem
     * vollstaendig.
     *
     * Ein Fehler hier bricht nichts ab. Der Abgleich laeuft ueber hunderte
     * Ereignisse; einer, der an einer einzelnen Zeile scheitert, duerfte nicht
     * den ganzen Lauf mitnehmen.
     */
    private function adresseSperren(MailDispatch $eintrag, string $zustand, string $grund): bool
    {
        $dauerhaft = match ($zustand) {
            MailDispatch::ZUSTELLUNG_UNZUSTELLBAR,
            MailDispatch::ZUSTELLUNG_BESCHWERDE => true,
            // „abgewiesen" ist mehrdeutig: Mailgun weist auch ab, was auf der
            // eigenen Unterdrueckungsliste steht oder gegen eine Regel
            // verstiess. Deshalb entscheidet hier der Wortlaut, wie beim
            // eigenen Versand — im Zweifel nicht sperren.
            MailDispatch::ZUSTELLUNG_ABGEWIESEN => Zustellfehler::ausMeldung($grund),
            default => false,
        };

        if (! $dauerhaft || $this->sperrliste === null) {
            return false;
        }

        try {
            $herkunft = $zustand === MailDispatch::ZUSTELLUNG_BESCHWERDE ? 'beschwerde' : 'bounce';

            return $this->sperrliste->sperren($eintrag->empfaenger, $grund, $herkunft, $eintrag);
        } catch (Throwable $fehler) {
            Log::warning('Rückläufer aus dem Zustellabgleich konnte nicht vermerkt werden.', [
                'dispatch_id' => $eintrag->id,
                'fehler' => $fehler->getMessage(),
            ]);

            return false;
        }
    }

    /** @param  array<string, mixed>  $ereignis */
    private function zustandAus(array $ereignis): string
    {
        $art = (string) ($ereignis['event'] ?? '');

        return match ($art) {
            'delivered' => MailDispatch::ZUSTELLUNG_ZUGESTELLT,
            'complained' => MailDispatch::ZUSTELLUNG_BESCHWERDE,
            'rejected' => MailDispatch::ZUSTELLUNG_ABGEWIESEN,
            // `failed` gibt es in zwei Schweregraden, und die Unterscheidung
            // ist die wichtigste im ganzen Thema: `temporary` heisst „morgen
            // wieder", `permanent` heisst „nie wieder".
            'failed' => ($ereignis['severity'] ?? '') === 'permanent'
                ? MailDispatch::ZUSTELLUNG_UNZUSTELLBAR
                : MailDispatch::ZUSTELLUNG_VERZOEGERT,
            default => MailDispatch::ZUSTELLUNG_VERZOEGERT,
        };
    }

    /**
     * Der Wortlaut der Rueckmeldung — so genau wie Mailgun ihn liefert.
     *
     * @param  array<string, mixed>  $ereignis
     */
    private function grundAus(array $ereignis): string
    {
        $teile = array_filter([
            $ereignis['delivery-status']['code'] ?? null,
            $ereignis['delivery-status']['message'] ?? ($ereignis['delivery-status']['description'] ?? null),
            $ereignis['reason'] ?? null,
        ]);

        return trim(implode(' ', array_map(fn ($teil) => (string) $teil, $teile)));
    }

    /** @param  array<string, mixed>  $ereignis */
    private function zeitpunkt(array $ereignis): ?CarbonImmutable
    {
        $stempel = $ereignis['timestamp'] ?? null;

        return is_numeric($stempel) ? CarbonImmutable::createFromTimestamp((int) $stempel) : null;
    }
}
