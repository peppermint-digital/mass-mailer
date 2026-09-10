<?php

namespace Peppermint\MassMailer\Services;

use Illuminate\Support\Facades\Log;
use Peppermint\MassMailer\Contracts\Bezugsaufloeser;
use Peppermint\MassMailer\Contracts\Sperrliste;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\MailDispatchVersuch;
use Peppermint\MassMailer\Support\Metadatenschluessel;
use Peppermint\MassMailer\Support\Modelle;
use Peppermint\MassMailer\Support\Zustellfehler;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Schreibt mit, was verschickt wird — unabhaengig davon, wer es ausgeloest hat.
 *
 * Angesetzt wird an den Mail-Ereignissen und nicht an den Aufrufern. Der Grund
 * ist Vollstaendigkeit: In Connect gibt es fuenfzehn automatische Mails, den
 * Massenversand und den Einzelversand, und jeder neue Weg waere sonst eine
 * Gelegenheit, das Protokoll zu vergessen. Am Ereignis kommt alles vorbei —
 * auch Mails aus fremden Paketen, an die beim Bauen niemand gedacht hat.
 *
 * Was die Mail ueber sich selbst weiss, reist als Metadaten mit
 * ({@see Metadatenschluessel}). Das ueberlebt die Queue, weil es ein einfaches
 * Array ist.
 *
 * Fehlen die Metadaten, bleibt die Zeile trotzdem: Empfaenger und Betreff
 * stehen im Umschlag, und der Bezug laesst sich ueber die Adresse nachschlagen.
 * **Lieber eine magere Zeile als eine fehlende.**
 */
class Versandprotokoll
{
    public function __construct(
        private readonly ?Bezugsaufloeser $bezugsaufloeser = null,
        private readonly ?Sperrliste $sperrliste = null,
        private readonly ?Mailmessung $messung = null,
    ) {}

    /** Unter diesem Schluessel merkt sich die Nachricht ihre eigene Zeile. */
    public function kopfzeile(): string
    {
        return config('mass-mailer.protokoll.kopfzeile', 'X-Mass-Mailer-Protokoll');
    }

    /**
     * Dieselbe Nummer noch einmal — in der Form, die Mailgun zurueckliefert.
     *
     * Eigene Kopfzeilen tauchen in Mailguns Ereignissen NICHT auf; von den
     * Kopfzeilen kommen nur `message-id`, `to`, `from` und `subject` zurueck.
     * Was zurueckkommt, sind die „user variables" — und die entstehen beim
     * SMTP-Versand aus genau dieser Kopfzeile, die ein JSON-Objekt enthaelt.
     *
     * Ohne sie liesse sich ein Mailgun-Ereignis nur ueber Adresse und Zeitpunkt
     * zuordnen. Das ist bei zwei gleichen Mails an dieselbe Person innerhalb
     * einer Stunde geraten, nicht gewusst — und der Massenversand erzeugt genau
     * solche Faelle.
     */
    private const MAILGUN_KOPFZEILE = 'X-Mailgun-Variables';

    /** Der Schluessel INNERHALB der Mailgun-Variablen. */
    public function mailgunSchluessel(): string
    {
        return config('mass-mailer.mailgun.variablen_schluessel', 'mass_mailer_protokoll');
    }

    /**
     * Legt die Zeile an, sobald die Nachricht an den Postausgang geht.
     *
     * Der Zeitpunkt ist mit Absicht VOR dem Versand: bricht der ab, gibt es
     * trotzdem etwas, das auf `fehlgeschlagen` gesetzt werden kann. Eine Zeile
     * erst nach erfolgreichem Versand anzulegen hiesse, dass ausgerechnet der
     * Fehlerfall keine Spur hinterlaesst.
     *
     * ## Wiederholungsversuche bekommen keine zweite Zeile
     *
     * Dieses Ereignis feuert bei JEDEM Sendeversuch, und ein Worker laeuft
     * ueblicherweise mit `--tries=3`. Wuerde je Versuch eine Zeile angelegt,
     * blieben nach einem dreimal gescheiterten Job drei Zeilen stehen — zwei
     * davon fuer immer auf `im_versand`, weil beim Scheitern nur eine markiert
     * wird.
     *
     * Das ist mehr als unsauber: Ein Empfaenger erscheint dreifach, die
     * Zusammenfassung zaehlt Versuche statt Personen, und „im Versand"
     * behauptet einen laufenden Vorgang, den es nicht mehr gibt. Aufloesen kann
     * das auch der Zustellabgleich nicht — zu einer nie angenommenen Mail gibt
     * es beim Anbieter kein Ereignis.
     */
    public function beginnen(Email $nachricht): ?MailDispatch
    {
        $empfaenger = $this->ersterEmpfaenger($nachricht);

        if ($empfaenger === null) {
            return null;
        }

        $metadaten = $this->metadaten($nachricht);
        $spalten = $this->spalten($metadaten, $empfaenger, $nachricht->getSubject());

        $eintrag = $this->offeneZeileZu($spalten)
            ?? Modelle::anlegen($spalten);

        // Jeder Anlauf bekommt seine eigene Spur. Die Zeile sagt, was aus der
        // Mail wurde; erst die Versuche sagen, wie oft es dafuer gebraucht hat
        // — und woran die vorherigen scheiterten.
        $this->versuchBeginnen($eintrag);

        // Die Nummer wandert in die Nachricht, damit die Bestaetigung dieselbe
        // Zeile wiederfindet. Ohne das muesste sie ueber Empfaenger und Betreff
        // raten — und zwei gleiche Mails an dieselbe Person waeren nicht
        // auseinanderzuhalten.
        $nachricht->getHeaders()->addTextHeader($this->kopfzeile(), (string) $eintrag->id);

        // Und dieselbe Nummer fuer den Rueckweg ueber Mailgun. Andere
        // Postausgaenge ignorieren die Kopfzeile — sie kostet dort nichts und
        // erspart hier eine Fallunterscheidung beim Versand.
        $nachricht->getHeaders()->addTextHeader(
            self::MAILGUN_KOPFZEILE,
            (string) json_encode([$this->mailgunSchluessel() => (string) $eintrag->id]),
        );

        // Zaehlpixel und Klick-Umleitung — nur, wo die Mail es ausdruecklich
        // erlaubt. Hier und nicht beim Aufrufer, weil erst jetzt BEIDES
        // vorliegt: der gerenderte Rumpf und die Protokollzeile, auf die
        // gemessen wird.
        $this->messung?->anwenden($nachricht, $eintrag, $metadaten);

        return $eintrag;
    }

    /**
     * Die noch offene Zeile desselben Vorgangs — oder `null`.
     *
     * „Derselbe Vorgang" heisst: gleicher Empfaenger, gleiche Mail-Art,
     * gleicher Bezug (Kampagne UND Bezugsobjekt), und die Zeile steht noch auf
     * `im_versand`.
     *
     * Der Schluss dahinter: Eine Zeile auf `im_versand` ist per Definition ein
     * Versuch, der nie zu Ende kam. Wird dieselbe Mail an dieselbe Person
     * erneut gebaut, ist das die Fortsetzung dieses Versuchs und keine zweite
     * Mail — denn eine erfolgreich verschickte waere laengst auf `verschickt`
     * gesetzt.
     *
     * Der Bezug gehoert ausdruecklich in den Vergleich. Ohne ihn wuerde eine
     * Einladung, die haengt, von der naechsten Bestaetigung an dieselbe Adresse
     * uebernommen — zwei verschiedene Mails in einer Zeile, und die Einladung
     * waere aus dem Protokoll verschwunden.
     *
     * @param  array<string, mixed>  $spalten
     */
    private function offeneZeileZu(array $spalten): ?MailDispatch
    {
        $fenster = (int) config('mass-mailer.protokoll.wiederholungsfenster_stunden', 24);

        return Modelle::protokollAbfrage()
            ->where('empfaenger', $spalten['empfaenger'])
            ->where('status', MailDispatch::STATUS_IM_VERSAND)
            ->where('art', $spalten['art'])
            ->where(fn ($frage) => $this->gleich($frage, 'kampagne_id', $spalten['kampagne_id']))
            ->where(fn ($frage) => $this->gleich($frage, 'bezug_typ', $spalten['bezug_typ']))
            ->where(fn ($frage) => $this->gleich($frage, 'bezug_id', $spalten['bezug_id']))
            ->where('created_at', '>=', now()->subHours($fenster))
            ->latest('id')
            ->first();
    }

    /**
     * Gleichheit, die auch fuer `null` funktioniert.
     *
     * `where(..., null)` waere hier ein stiller Ausfall: SQL vergleicht NULL
     * nicht mit `=`, die Abfrage faende also genau die Mails ohne Bezug nie —
     * und legte fuer sie bei jedem Versuch eine neue Zeile an.
     */
    private function gleich(mixed $frage, string $spalte, mixed $wert): mixed
    {
        return $wert === null
            ? $frage->whereNull($spalte)
            : $frage->where($spalte, $wert);
    }

    /**
     * Beginnt einen Versuch und zaehlt ihn an der Zeile mit.
     *
     * Die Nummer kommt aus der Versuchstabelle und nicht aus dem Zaehler: So
     * bleibt sie richtig, auch wenn der Zaehler einmal nicht mitgeschrieben
     * wurde. Der Zaehler ist die schnelle Antwort fuer Listen, die Tabelle die
     * wahre.
     */
    private function versuchBeginnen(MailDispatch $eintrag): void
    {
        $nummer = (int) $eintrag->sendeversuche()->max('nummer') + 1;

        $eintrag->forceFill(['versuche' => $nummer])->save();

        $versuch = Modelle::versuch();

        $versuch::create([
            'mail_dispatch_id' => $eintrag->id,
            'nummer' => $nummer,
            'begonnen_am' => now(),
        ]);
    }

    /**
     * Schliesst den laufenden Versuch ab.
     *
     * Findet sich keiner, passiert nichts — eine Mail, die vor der Einfuehrung
     * des Protokolls losgeschickt wurde, hat keinen offenen Versuch, und dafuer
     * einen nachtraeglich zu erfinden waere eine Behauptung ueber etwas, das
     * niemand beobachtet hat.
     */
    private function versuchAbschliessen(MailDispatch $eintrag, string $ergebnis, ?string $fehler = null): void
    {
        $eintrag->sendeversuche()
            ->whereNull('ergebnis')
            // `reorder()` und nicht `latest()`: Die Beziehung bringt bereits
            // eine aufsteigende Sortierung mit, und ein weiteres `orderBy`
            // haengt sich nur dahinter — die erste gewinnt. Ohne `reorder()`
            // schliesst diese Methode also den AELTESTEN offenen Versuch ab
            // statt den laufenden, und der Verlauf steht hinterher auf dem Kopf.
            ->reorder('nummer', 'desc')
            ->first()
            ?->update([
                'ergebnis' => $ergebnis,
                'beendet_am' => now(),
                'fehler' => $fehler,
            ]);
    }

    /** Der Postausgang hat die Mail angenommen. */
    public function bestaetigen(Email $nachricht): void
    {
        $eintrag = $this->eintragZu($nachricht);

        if ($eintrag === null) {
            return;
        }

        $eintrag->update([
            'status' => MailDispatch::STATUS_VERSCHICKT,
            'verschickt_am' => now(),
        ]);

        $this->versuchAbschliessen($eintrag, MailDispatchVersuch::ERGEBNIS_ERFOLG);
    }

    /**
     * Der Versand ist gescheitert.
     *
     * `$empfaenger` dient als Rueckfallebene: schlaegt der Versand fehl, bevor
     * die Nachricht ueberhaupt gebaut ist (eine kaputte Ansicht, ein fehlendes
     * Modell), gibt es keine Kopfzeile und keine Zeile — dann entsteht sie
     * hier, damit der Fehler nicht unsichtbar bleibt.
     *
     * `$metadaten` und `$betreff` sind fuer genau diesen Fall da. Sie kommen
     * dann nicht aus der Nachricht (die es ja nicht gibt), sondern aus dem
     * Mailable im gescheiterten Job — und sorgen dafuer, dass die neue Zeile
     * dieselben Bezuege traegt wie eine im Regelfall entstandene.
     *
     * @param  array<string, string>  $metadaten
     */
    public function scheitern(
        ?MailDispatch $eintrag,
        ?string $empfaenger,
        Throwable $fehler,
        array $metadaten = [],
        ?string $betreff = null,
    ): void {
        $eintrag ??= $empfaenger !== null
            ? Modelle::protokollAbfrage()
                ->where('empfaenger', $empfaenger)
                ->where('status', MailDispatch::STATUS_IM_VERSAND)
                ->latest('id')
                ->first()
            : null;

        // Entsteht die Zeile erst hier, bekommt sie denselben Kontext wie im
        // Regelfall. Ohne das haengt ausgerechnet der Fehlschlag an keiner
        // Kampagne und an keinem Bereich — und faellt damit aus genau der
        // Liste heraus, in der jemand nach ihm sucht. In Connect am 17.08.2026
        // real passiert: zwei kaputte Adressen aus einem Import, beide
        // unsichtbar.
        $eintrag ??= $empfaenger !== null
            ? Modelle::anlegen($this->spalten($metadaten, $empfaenger, $betreff))
            : null;

        $meldung = $this->lesbar($fehler);

        $eintrag?->update([
            'status' => MailDispatch::STATUS_FEHLGESCHLAGEN,
            'fehler' => $meldung,
            'fehlgeschlagen_am' => now(),
        ]);

        if ($eintrag !== null) {
            $this->versuchAbschliessen(
                $eintrag,
                MailDispatchVersuch::ERGEBNIS_FEHLSCHLAG,
                $meldung,
            );
        }

        $this->sperrenWennDauerhaft($eintrag, $empfaenger, $meldung);
    }

    /**
     * Vermerkt, dass diese Adresse nicht erreichbar ist.
     *
     * **Nur bei dauerhaften Fehlern.** Ein volles Postfach oder ein Server in
     * Wartung sind voruebergehend; wer daraufhin sperrt, nimmt einem Empfaenger
     * die Post, weil sein Postfach an einem Dienstagnachmittag voll war. Die
     * Unterscheidung trifft {@see Zustellfehler}.
     *
     * Wohin der Vermerk gehoert, weiss nur die Anwendung — siehe
     * {@see Sperrliste}. Ist keine gebunden, passiert hier nichts; das Protokoll
     * bleibt trotzdem vollstaendig.
     *
     * Ein Fehler hier bricht nichts ab — der Versand ist ohnehin schon
     * gescheitert, und ein misslungener Vermerk darf daraus keinen zweiten
     * Fehler machen.
     */
    private function sperrenWennDauerhaft(?MailDispatch $eintrag, ?string $empfaenger, string $meldung): void
    {
        $adresse = $eintrag?->empfaenger ?? $empfaenger;

        if ($this->sperrliste === null || $adresse === null || ! Zustellfehler::ausMeldung($meldung)) {
            return;
        }

        try {
            $this->sperrliste->sperren($adresse, $meldung, 'versandfehler', $eintrag);
        } catch (Throwable $weiterer) {
            Log::warning('Rückläufer konnte nicht vermerkt werden.', [
                'dispatch_id' => $eintrag?->id,
                'fehler' => $weiterer->getMessage(),
            ]);
        }
    }

    public function eintragZu(Email $nachricht): ?MailDispatch
    {
        $kopfzeile = $nachricht->getHeaders()->get($this->kopfzeile());

        if ($kopfzeile === null) {
            return null;
        }

        return Modelle::protokollAbfrage()->find((int) $kopfzeile->getBodyAsString());
    }

    /**
     * Die Metadaten der Nachricht.
     *
     * @return array<string, string>
     */
    public function metadaten(Email $nachricht): array
    {
        $werte = [];

        foreach ($nachricht->getHeaders()->all() as $kopfzeile) {
            if ($kopfzeile instanceof MetadataHeader) {
                $werte[$kopfzeile->getKey()] = $kopfzeile->getValue();
            }
        }

        return $werte;
    }

    /**
     * Metadaten auf Spalten abbilden — die eine Stelle, an der das passiert.
     *
     * Sie wird von beiden Wegen benutzt: vom Regelfall in `beginnen()` und von
     * der Rueckfallebene in `scheitern()`. Zwei Abbildungen waeren die Sorte
     * Doppelung, die auseinanderlaeuft, sobald ein Feld dazukommt — und der
     * Fehlerpfad ist genau der, an den dabei niemand denkt.
     *
     * @param  array<string, string>  $metadaten
     * @return array<string, mixed>
     */
    private function spalten(array $metadaten, string $empfaenger, ?string $betreff): array
    {
        $bezug = $this->bezug($metadaten, $empfaenger);

        return [
            'mandant_id' => $this->zahl($metadaten[Metadatenschluessel::MANDANT] ?? null),
            'bereich_typ' => $metadaten[Metadatenschluessel::BEREICH_TYP] ?? null,
            'bereich_id' => $this->zahl($metadaten[Metadatenschluessel::BEREICH] ?? null),
            'bezug_typ' => $bezug['typ'],
            'bezug_id' => $bezug['id'],
            'kampagne_id' => $this->zahl($metadaten[Metadatenschluessel::KAMPAGNE] ?? null),
            'ausgeloest_von_id' => $this->zahl($metadaten[Metadatenschluessel::AUSGELOEST_VON] ?? null),
            'empfaenger' => $empfaenger,
            'art' => $metadaten[Metadatenschluessel::ART] ?? null,
            'betreff' => $betreff,
            'status' => MailDispatch::STATUS_IM_VERSAND,
        ];
    }

    /**
     * Der Bezug — aus den Metadaten, sonst ueber die Adresse.
     *
     * Die Suche ueber die Adresse macht das Paket ausdruecklich NICHT selbst:
     * Wie eindeutig eine Adresse ist und wie weit gesucht werden darf, weiss
     * nur die Anwendung. Ohne gebundenen {@see Bezugsaufloeser} bleibt der
     * Bezug leer — die Zeile entsteht trotzdem.
     *
     * @param  array<string, string>  $metadaten
     * @return array{typ: ?string, id: ?int}
     */
    private function bezug(array $metadaten, string $empfaenger): array
    {
        $typ = $metadaten[Metadatenschluessel::BEZUG_TYP] ?? null;
        $id = $this->zahl($metadaten[Metadatenschluessel::BEZUG] ?? null);

        if ($typ !== null && $id !== null) {
            return ['typ' => $typ, 'id' => $id];
        }

        if ($this->bezugsaufloeser === null) {
            return ['typ' => null, 'id' => null];
        }

        try {
            $modell = $this->bezugsaufloeser->ausAdresse(
                mb_strtolower(trim($empfaenger)),
                $metadaten[Metadatenschluessel::BEREICH_TYP] ?? null,
                $this->zahl($metadaten[Metadatenschluessel::BEREICH] ?? null),
                $metadaten,
            );
        } catch (Throwable $fehler) {
            // Ein Aufloeser, der wirft, darf den Versand nicht mitnehmen.
            Log::warning('Bezug konnte nicht aufgelöst werden.', [
                'empfaenger' => $empfaenger,
                'fehler' => $fehler->getMessage(),
            ]);

            return ['typ' => null, 'id' => null];
        }

        return $modell === null
            ? ['typ' => null, 'id' => null]
            : ['typ' => $modell->getMorphClass(), 'id' => (int) $modell->getKey()];
    }

    private function ersterEmpfaenger(Email $nachricht): ?string
    {
        $to = $nachricht->getTo();

        return $to === [] ? null : $to[0]->getAddress();
    }

    private function zahl(?string $wert): ?int
    {
        return $wert === null || $wert === '' ? null : (int) $wert;
    }

    /**
     * Die Fehlermeldung, gekuerzt auf das, was jemand lesen will.
     *
     * Der Stacktrace gehoert ins Log, nicht in eine Maske — dort steht die
     * Frage „warum kam die Mail nicht an?", und die beantwortet die erste
     * Zeile.
     */
    private function lesbar(Throwable $fehler): string
    {
        Log::warning('Mailversand fehlgeschlagen', [
            'fehler' => $fehler->getMessage(),
            'klasse' => $fehler::class,
        ]);

        return mb_substr(trim($fehler->getMessage()), 0, 500);
    }
}
