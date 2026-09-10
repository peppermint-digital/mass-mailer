<?php

namespace Peppermint\MassMailer\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Verteilt einen Massenversand ueber die Zeit — und zwar so, dass sich zwei
 * Kampagnen nicht gegenseitig ueberholen.
 *
 * Ohne diese Stelle waere die Drosselung nur halb wirksam: jede Kampagne wuerde
 * ihren Versatz ab „jetzt" rechnen. Wer um 10:00 fuenfhundert Einladungen
 * verschickt und um 10:05 dreihundert Erinnerungen, haette waehrend der
 * Ueberschneidung die doppelte Rate am Postausgang — also genau die Menge,
 * gegen die gedrosselt wird.
 *
 * Deshalb merkt sich der Plan je Postausgang, bis wann bereits eingereiht ist,
 * und die naechste Kampagne setzt dort an. Reserviert wird unter einer Sperre,
 * damit zwei gleichzeitige Versendungen nicht denselben Zeitraum belegen.
 *
 * ## Der Schluessel haengt am POSTAUSGANG, nicht am Bereich
 *
 * Das Limit gehoert dem Mailserver. Zwei Bereiche, die sich einen teilen,
 * teilen sich auch das Kontingent — zwei mit eigenen Servern stehen sich nicht
 * im Weg. Ein Schluessel am Bereich waere in beiden Faellen falsch: Er wuerde
 * geteilte Server ueberlasten und getrennte grundlos ausbremsen.
 */
class Versandplan
{
    public function __construct(
        private readonly MailserverAufloeser $mailserver,
        private readonly VersandtempoAufloeser $tempo,
    ) {}

    /**
     * Reserviert Platz fuer `$anzahl` Mails und liefert die Startzeit.
     *
     * Die Mail an Position `$i` (ab 0) geht dann zu
     * `$start->addSeconds($this->versatzInSekunden($i, $proStunde))` raus.
     */
    public function reservieren(?Model $bereich, int $anzahl): CarbonImmutable
    {
        $proStunde = $this->tempo->fuer($bereich);

        if ($anzahl < 1) {
            return CarbonImmutable::now();
        }

        $schluessel = $this->schluessel($bereich);
        $sperre = Cache::lock($schluessel.':sperre', 10);

        // `block()` statt `get()`: wer die Sperre nicht bekommt, soll warten
        // statt unreserviert loszulegen. Bekommt er sie auch nach fuenf
        // Sekunden nicht, faellt er auf „ab jetzt" zurueck — ein Versand, der
        // an einer Sperre scheitert, waere schlimmer als einer, der einmal zu
        // schnell ist.
        try {
            $sperre->block(5);
        } catch (LockTimeoutException) {
            return CarbonImmutable::now();
        }

        try {
            $jetzt = CarbonImmutable::now();

            $start = Cache::get($schluessel);
            $start = $start !== null ? CarbonImmutable::parse($start) : $jetzt;

            // Liegt der Zeiger in der Vergangenheit, ist der letzte Versand
            // durch — dann faengt dieser wieder bei jetzt an.
            if ($start->lessThan($jetzt)) {
                $start = $jetzt;
            }

            // In Sekunden und nicht in Minuten: bei 80/Stunde liegen 45
            // Sekunden zwischen zwei Mails, und auf ganze Minuten gerundet
            // waere die Reservierung je Kampagne um bis zu eine Minute zu gross
            // — bei vielen kleinen Kampagnen summiert sich das.
            $dauerInSekunden = (int) ceil($anzahl * 3600 / max($proStunde, 1));
            $ende = $start->addSeconds($dauerInSekunden);

            // Haltbarkeit knapp ueber das Ende hinaus: ist der Versand durch,
            // darf der Zeiger verschwinden, damit die naechste Kampagne nicht
            // an einem uralten Wert klebt.
            Cache::put($schluessel, $ende->toIso8601String(), $ende->addMinutes(5));

            return $start;
        } finally {
            $sperre->release();
        }
    }

    /**
     * Der Versatz der Mail an Position `$position` innerhalb der Kampagne.
     *
     * Nimmt die Rate als Wert entgegen und schlaegt sie nicht selbst nach —
     * sonst liefe je Empfaenger eine Abfrage ueber die ganze Kaskade.
     */
    public function versatzInSekunden(int $position, int $proStunde): int
    {
        return $this->tempo->versatzInSekunden($position, $proStunde);
    }

    public function proStunde(?Model $bereich): int
    {
        return $this->tempo->fuer($bereich);
    }

    private function schluessel(?Model $bereich): string
    {
        $server = $this->mailserver->fuer($bereich);

        return $server !== null
            ? 'mass-mailer:versandplan:postausgang:'.$server->id
            : 'mass-mailer:versandplan:anwendung';
    }
}
