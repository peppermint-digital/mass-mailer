<?php

namespace Peppermint\MassMailer\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Peppermint\MassMailer\Contracts\Mailgunzugang;
use RuntimeException;

/**
 * Fragt Mailgun nach dem Zustand des Kontos.
 *
 * Der Grund steht im Kalender: Am 01.09.2026 war nur an einem verschwundenen
 * Warnbanner in der Mailgun-Oberflaeche abzulesen, ob die
 * Bewaehrungsdrosselung noch galt. Sie galt. Das Versandtempo wurde auf
 * 300/Stunde erhoeht, und nach zwoelf Minuten stand der Versand.
 *
 * Der Fehler war nicht die Erhoehung, sondern dass es keine Moeglichkeit gab,
 * sie zu pruefen. Ein Ratelimit meldet sich ausserdem beim ERREICHEN des
 * Kontingents und nicht beim Ueberschreiten — die ersten Minuten sehen immer
 * gut aus.
 */
class MailgunKonto
{
    public function __construct(private readonly Mailgunzugang $zugang) {}

    /**
     * Der Zustand der Versand-Domain.
     *
     * @return array{erreichbar: bool, domain: ?string, zustand: ?string, gesperrt: ?bool, meldung: ?string}
     */
    public function zustand(): array
    {
        $schluessel = $this->zugang->schluessel();
        $domain = $this->zugang->domain();

        if ($schluessel === null || $domain === null) {
            return [
                'erreichbar' => false,
                'domain' => $domain,
                'zustand' => null,
                'gesperrt' => null,
                'meldung' => 'Es sind keine Mailgun-Zugangsdaten hinterlegt.',
            ];
        }

        $endpunkt = $this->zugang->endpunkt();

        try {
            $antwort = Http::withBasicAuth('api', $schluessel)
                ->acceptJson()
                ->timeout(15)
                ->get(sprintf('https://%s/v4/domains/%s', $endpunkt, $domain));
        } catch (\Throwable $fehler) {
            return [
                'erreichbar' => false,
                'domain' => $domain,
                'zustand' => null,
                'gesperrt' => null,
                'meldung' => 'Mailgun war nicht erreichbar: '.$fehler->getMessage(),
            ];
        }

        if ($antwort->status() === 401) {
            return [
                'erreichbar' => false,
                'domain' => $domain,
                'zustand' => null,
                'gesperrt' => null,
                'meldung' => 'Der API-Schlüssel wurde abgelehnt. Ist es der private API-Key und nicht das SMTP-Passwort?',
            ];
        }

        if ($antwort->status() === 404) {
            // Der haeufigste Fehler beim Einrichten, und er sieht nach etwas
            // anderem aus: Ein EU-Konto, gegen den US-Endpunkt gefragt, meldet
            // „Domain not found" — als gaebe es die Domain nicht.
            return [
                'erreichbar' => false,
                'domain' => $domain,
                'zustand' => null,
                'gesperrt' => null,
                'meldung' => sprintf(
                    'Mailgun kennt die Domain „%s" unter %s nicht. Bei einem Konto in der EU muss der Endpunkt api.eu.mailgun.net sein.',
                    $domain,
                    $endpunkt,
                ),
            ];
        }

        if ($antwort->failed()) {
            return [
                'erreichbar' => false,
                'domain' => $domain,
                'zustand' => null,
                'gesperrt' => null,
                'meldung' => 'Mailgun antwortete mit '.$antwort->status().'.',
            ];
        }

        $daten = $antwort->json('domain') ?? [];

        return [
            'erreichbar' => true,
            'domain' => $domain,
            'zustand' => $daten['state'] ?? null,
            'gesperrt' => ($daten['is_disabled'] ?? false) === true,
            'meldung' => null,
        ];
    }

    /**
     * Wie viele Nachrichten in der letzten Stunde ueber Mailgun gingen.
     *
     * Die Zahl, die vor einer Tempoerhoehung fehlt. Sie kommt von Mailgun
     * selbst und nicht aus unserem Versandprotokoll: Nur dort ist zu sehen,
     * was WIRKLICH angenommen wurde.
     *
     * ## Warum gezaehlt und nicht die Statistik gefragt (#4916)
     *
     * Naheliegend waere `/stats/total`. Dessen kleinste Aufloesung ist aber
     * die volle Stunde, und die Faecher liegen auf der Uhr: Wer um 18:48 die
     * letzte Stunde erfragt, bekommt das Fach 17:00–18:00 UND das Fach
     * 18:00–19:00 zurueck und summiert damit 1 Stunde 48 Minuten. Kurz nach
     * einer vollen Stunde ist die Zahl beinahe doppelt so gross wie die
     * Wirklichkeit.
     *
     * Fuer eine Anzeige waere das laessliche Ungenauigkeit. Hier nicht: An
     * dieser Zahl haengt die Entscheidung, ob das Tempo unter dem Limit des
     * Anbieters bleibt. Zu hoch gemessen heisst, ein zulaessiges Tempo fuer
     * unzulaessig zu halten — ein Versand, der grundlos langsamer laeuft als
     * er duerfte. Deshalb werden die Ereignisse gezaehlt: eine Abfrage mehr,
     * dafuer genau das Fenster, das gemeint ist.
     *
     * @throws RuntimeException wenn die Zugangsdaten fehlen oder die Abfrage scheitert
     */
    public function versendetLetzteStunde(): int
    {
        $seit = CarbonImmutable::now()->subHour();
        $anzahl = 0;

        // Vier Seiten sind 1200 Ereignisse in einer Stunde. Wer so schnell
        // sendet, hat kein Messproblem mehr, sondern ein Tempoproblem — und
        // fuer die Frage „ist noch Luft?" genuegt dann jede Zahl.
        foreach ($this->ereignisse(['accepted'], $seit, hoechstensSeiten: 4) as $ereignis) {
            $anzahl++;
        }

        return $anzahl;
    }

    /** Ohne hinterlegte Zugangsdaten gibt es nichts abzugleichen. */
    public function istVerbunden(): bool
    {
        return $this->zugang->vorhanden();
    }

    /**
     * Die Ereignisse eines Zeitraums — was Mailgun ueber unsere Mails weiss.
     *
     * Seitenweise, weil eine Antwort hoechstens 300 Ereignisse fasst und ein
     * Massenversand ueber Stunden mehr erzeugt. Gefolgt wird Mailguns
     * `paging.next`, nicht ein selbst hochgezaehlter Versatz: Die Schnittstelle
     * gibt einen Zeiger zurueck, und wer stattdessen mit Offsets arbeitet,
     * verliert bei gleichzeitig eintreffenden Ereignissen welche.
     *
     * `$hoechstensSeiten` ist eine Notbremse, keine Auslegung: Ohne sie haengt
     * der Abgleich an einer Endlosschleife, wenn die Schnittstelle einmal
     * dieselbe Seite zurueckgibt.
     *
     * @param  array<int, string>  $arten
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException wenn die Zugangsdaten fehlen oder die Abfrage scheitert
     */
    public function ereignisse(
        array $arten,
        CarbonImmutable $von,
        ?CarbonImmutable $bis = null,
        int $hoechstensSeiten = 40,
    ): \Generator {
        $schluessel = $this->zugang->schluessel();
        $domain = $this->zugang->domain();

        if ($schluessel === null || $domain === null) {
            throw new RuntimeException('Es sind keine Mailgun-Zugangsdaten hinterlegt.');
        }

        $endpunkt = $this->zugang->endpunkt();

        $url = sprintf('https://%s/v3/%s/events', $endpunkt, $domain);
        $parameter = [
            'event' => implode(' OR ', $arten),
            'begin' => $von->toRfc2822String(),
            // Aufsteigend: Der Abgleich schreibt jedes Ereignis in die Zeile,
            // und bei mehreren zu derselben Mail soll das SPAETESTE gewinnen.
            // „erst verzoegert, dann zugestellt" ist der Normalfall — in
            // umgekehrter Reihenfolge gelesen bliebe „verzoegert" stehen.
            'ascending' => 'yes',
            'limit' => 300,
        ];

        if ($bis !== null) {
            $parameter['end'] = $bis->toRfc2822String();
        }

        $gesehen = [];

        for ($seite = 0; $seite < $hoechstensSeiten; $seite++) {
            $antwort = Http::withBasicAuth('api', $schluessel)
                ->acceptJson()
                ->timeout(30)
                ->get($url, $parameter);

            if ($antwort->failed()) {
                throw new RuntimeException('Mailgun antwortete mit '.$antwort->status().'.');
            }

            $eintraege = $antwort->json('items') ?? [];

            if ($eintraege === []) {
                return;
            }

            yield from $eintraege;

            $weiter = $antwort->json('paging.next');

            // Die letzte Seite liefert ihren eigenen Zeiger nochmal mit —
            // erkennbar daran, dass sie schon einmal abgefragt wurde.
            if (! is_string($weiter) || $weiter === '' || isset($gesehen[$weiter])) {
                return;
            }

            $gesehen[$weiter] = true;
            $url = $weiter;
            $parameter = [];
        }
    }
}
