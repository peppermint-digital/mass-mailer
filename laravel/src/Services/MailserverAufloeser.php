<?php

namespace Peppermint\MassMailer\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Peppermint\MassMailer\Contracts\Kaskade;
use Peppermint\MassMailer\Models\MailServer;

/**
 * Beantwortet die eine Frage, die der Versand stellen muss: „Ueber welchen
 * Postausgang geht diese Mail raus?"
 *
 * Die Reihenfolge kommt aus der {@see Kaskade} — dieselbe wie beim Versandtempo,
 * damit sich niemand zwei unterschiedliche Ketten merken muss. Antwortet keine
 * Ebene, bleibt es beim Postausgang aus der `.env`.
 *
 * **Abgeschaltete Eintraege zaehlen auf jeder Stufe als „nicht vorhanden"**, und
 * die naechste Stufe uebernimmt. Sonst wuerde ein abgeschalteter Postausgang
 * einer Veranstaltung den ihrer Organisation verdecken, und der Versand liefe
 * ueberraschend ueber die Weltkonfiguration — also gerade nicht ueber den
 * Postausgang, den jemand extra hinterlegt hat.
 */
class MailserverAufloeser
{
    public function __construct(private readonly Kaskade $kaskade) {}

    /**
     * Der Postausgang fuer diesen Bereich — oder `null` fuer den globalen.
     */
    public function fuer(?Model $bereich): ?MailServer
    {
        foreach ($this->kaskade->ebenen($bereich) as $ebene) {
            $server = $this->benutzbarer($ebene);

            if ($server !== null) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Ein fertiger Mailer fuer diesen Postausgang.
     *
     * `Mail::build()` statt eines benannten Mailers aus `config/mail.php`: ein
     * benannter Mailer muesste vor dem Versand irgendwo registriert werden, und
     * genau das passiert im Queue-Worker nicht mehr — dort lebt nur noch die
     * Mail selbst, nicht der Request, der sie ausgeloest hat.
     */
    public function mailer(MailServer $server): Mailer
    {
        /** @var Mailer $mailer */
        $mailer = Mail::build($server->laravelKonfiguration());

        $absender = $server->absender();

        // `Mail::build()` setzt — anders als ein benannter Mailer — keine
        // globale Absenderadresse. Ohne diese Zeile traegt die Mail den Absender
        // aus der `.env`, waehrend sie ueber einen fremden Server laeuft: die
        // haeufigste Ursache fuer „abgelehnt, Adresse gehoert nicht zum Konto".
        if ($absender !== null) {
            $mailer->alwaysFrom($absender['address'], $absender['name']);
        }

        return $mailer;
    }

    /** Der hinterlegte Postausgang dieser Ebene — sofern er benutzbar ist. */
    public function anEbene(Model $ebene): ?MailServer
    {
        return $this->benutzbarer($ebene);
    }

    private function benutzbarer(Model $ebene): ?MailServer
    {
        $server = MailServer::query()
            ->where('owner_type', $ebene->getMorphClass())
            ->where('owner_id', $ebene->getKey())
            ->first();

        return $server?->istBenutzbar() ? $server : null;
    }
}
