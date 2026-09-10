<?php

namespace Peppermint\MassMailer\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Haengt das Versandprotokoll an die drei Zeitpunkte, an denen eine Mail etwas
 * ueber ihr Schicksal verraet.
 *
 * Ein Zuhoerer mit drei Methoden statt drei Klassen: es ist ein Vorgang in drei
 * Schritten, und wer den mittleren sucht, soll nicht drei Dateien aufmachen
 * muessen.
 *
 * Alles hier ist gegen Fehler abgesichert und schluckt sie. Das ist Absicht:
 * **ein Protokoll darf niemals den Versand verhindern, den es protokolliert.**
 * Lieber eine fehlende Zeile als eine Mail, die wegen der Buchfuehrung nicht
 * rausgeht.
 */
class ProtokolliertVersand
{
    public function __construct(private readonly Versandprotokoll $protokoll) {}

    public function beiVersand(MessageSending $ereignis): void
    {
        try {
            $this->protokoll->beginnen($ereignis->message);
        } catch (Throwable) {
            // bewusst still — siehe Klassenkommentar
        }
    }

    public function beiAnnahme(MessageSent $ereignis): void
    {
        try {
            $nachricht = $ereignis->sent->getOriginalMessage();

            if ($nachricht instanceof Email) {
                $this->protokoll->bestaetigen($nachricht);
            }
        } catch (Throwable) {
            // bewusst still
        }
    }

    /**
     * Der Fehlerfall — und der eigentliche Grund fuer das Protokoll.
     *
     * Ein Job, der endgueltig scheitert, landet sonst nur in `failed_jobs`, wo
     * niemand nachsieht, der wissen will, ob ein bestimmter Empfaenger seine
     * Mail bekommen hat.
     *
     * Der Umweg ueber die Nutzlast des Jobs ist noetig, weil das Ereignis den
     * Empfaenger nicht mitliefert. Er kann fehlschlagen (ein Modell, das es
     * nicht mehr gibt) — dann bleibt die Zeile auf `im_versand` stehen, was
     * immer noch mehr aussagt als gar nichts.
     */
    public function beiFehlschlag(JobFailed $ereignis): void
    {
        try {
            $befehl = $ereignis->job->payload()['data']['command'] ?? null;

            if (! is_string($befehl)) {
                return;
            }

            $job = unserialize($befehl);

            if (! $job instanceof SendQueuedMailable) {
                return;
            }

            $empfaenger = $job->mailable->to[0]['address'] ?? null;

            [$metadaten, $betreff] = $this->kontextAus($job->mailable);

            $this->protokoll->scheitern(null, $empfaenger, $ereignis->exception, $metadaten, $betreff);
        } catch (Throwable) {
            // bewusst still
        }
    }

    /**
     * Metadaten und Betreff aus dem Mailable — ohne es zu rendern.
     *
     * Scheitert eine Mail schon beim Bauen (eine ungueltige Adresse etwa),
     * feuert `MessageSending` nie. Dann gibt es keine Symfony-Nachricht, aus der
     * das Protokoll seine Bezuege lesen koennte — wohl aber das Mailable im Job.
     * Sein `envelope()` liefert genau dieselben Metadaten, die sonst als
     * Kopfzeilen mitreisen, und kostet nichts: es baut ein Wertobjekt, keine
     * Ansicht.
     *
     * Bewusst ueber `envelope()` und nicht ueber die einzelnen Eigenschaften des
     * Mailables: so gilt das hier fuer jede Mail-Art, die ihre Metadaten setzt,
     * statt nur fuer die, an die gerade jemand gedacht hat.
     *
     * @return array{0: array<string, string>, 1: string|null}
     */
    private function kontextAus(Mailable $mailable): array
    {
        if (! method_exists($mailable, 'envelope')) {
            return [[], null];
        }

        try {
            $umschlag = $mailable->envelope();
        } catch (Throwable) {
            // Ein Umschlag, der sich nicht bauen laesst, ist genau der Fall, der
            // uns hierher gebracht hat. Dann eben ohne Bezuege — eine magere
            // Zeile bleibt besser als gar keine.
            return [[], null];
        }

        return [$umschlag->metadata, $umschlag->subject];
    }
}
