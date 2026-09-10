<?php

namespace Peppermint\MassMailer\Services;

use Illuminate\Support\Facades\URL;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Support\Metadatenschluessel;
use Symfony\Component\Mime\Email;

/**
 * Oeffnungs- und Klick-Messung — der Teil, der die Mail veraendert.
 *
 * Angesetzt wird im selben Augenblick wie das Protokoll: dort liegen der
 * HTML-Rumpf UND die Protokollzeile bereits vor, und beides zusammen braucht es.
 * Ein Umschreiben beim Aufrufer waere nicht moeglich — der kennt den Rumpf noch
 * gar nicht, weil die Vorlage erst beim Versand gerendert wird.
 *
 * ## Wer entscheidet, ob gemessen wird
 *
 * **Die Anwendung, je Mail.** Sie setzt {@see Metadatenschluessel::MESSUNG_KLICKS}
 * bzw. `MESSUNG_OEFFNUNGEN` am Umschlag. In Connect haengt das an einem
 * Schalter je Veranstaltung, im CRM koennte es an der Marke haengen — das Paket
 * baut diese Regel nicht nach, es befolgt sie nur.
 *
 * Der Grund fuer die Zurueckhaltung: Der Zugriff auf das Endgeraet des
 * Empfaengers braucht eine Rechtsgrundlage (§ 25 TDDDG), die nur der Betreiber
 * herstellen kann. Ein Paket, das von sich aus misst, traefe diese Entscheidung
 * stillschweigend fuer ihn.
 *
 * ## Was die beiden Zahlen taugen
 *
 * - **Klicks** sind eine Handlung des Empfaengers und damit belastbar.
 * - **Oeffnungen** sind ein geladenes Bild. Apple Mail laedt seit 2021 alle
 *   Bilder vorab, Gmail ueber einen Proxy, andere Programme laden sie gar
 *   nicht. Die Zahl liegt systematisch zu hoch UND zu niedrig, je nach
 *   Postfach — sie taugt als Anhaltspunkt, nicht als Nachweis.
 *
 * Wer sie anzeigt, sollte das dazusagen, statt eine Prozentzahl hinzustellen,
 * die niemand einordnen kann.
 */
class Mailmessung
{
    /**
     * Baut Zaehlpixel und umgeschriebene Links ein — soweit die Mail es erlaubt.
     *
     * @param  array<string, string>  $metadaten
     */
    public function anwenden(Email $nachricht, MailDispatch $eintrag, array $metadaten = []): void
    {
        if (! config('mass-mailer.messung.enabled', false)) {
            return;
        }

        $klicks = $this->erlaubt($metadaten, Metadatenschluessel::MESSUNG_KLICKS);
        $oeffnungen = $this->erlaubt($metadaten, Metadatenschluessel::MESSUNG_OEFFNUNGEN);

        if (! $klicks && ! $oeffnungen) {
            return;
        }

        $rumpf = $nachricht->getHtmlBody();

        if (! is_string($rumpf) || $rumpf === '') {
            return;
        }

        // Erst die Links, dann der Pixel: Sonst liefe der Pixel selbst durch
        // die Link-Umschreibung, und die Messung maesse sich selbst.
        if ($klicks) {
            $rumpf = $this->linkeUmschreiben($rumpf, $eintrag);
        }

        if ($oeffnungen) {
            $rumpf = $this->pixelAnhaengen($rumpf, $eintrag);
        }

        $nachricht->html($rumpf);
    }

    /**
     * Nur ein ausdrueckliches Ja zaehlt.
     *
     * Metadaten sind Kopfzeilen und damit immer Strings. `'0'` und `'false'`
     * duerfen nicht als „an" durchgehen — ein Aufrufer, der `(string) $flag`
     * schreibt, wuerde sonst bei ausgeschalteter Messung messen.
     *
     * @param  array<string, string>  $metadaten
     */
    private function erlaubt(array $metadaten, string $schluessel): bool
    {
        return filter_var($metadaten[$schluessel] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Der Zaehlpixel, direkt vor dem schliessenden `</body>`.
     *
     * Ohne `</body>` wird angehaengt: eine Mail ohne diesen Abschluss ist
     * selten, aber ein verlorener Pixel waere ein stiller Ausfall der Messung.
     */
    private function pixelAnhaengen(string $rumpf, MailDispatch $eintrag): string
    {
        $url = URL::signedRoute('mass-mailer.messung.oeffnung', ['dispatch' => $eintrag->id]);

        $pixel = '<img src="'.e($url).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px" />';

        return str_contains($rumpf, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $rumpf)
            : $rumpf.$pixel;
    }

    /**
     * Schreibt `href`-Ziele auf die eigene Umleitung um.
     *
     * Ausgenommen bleiben `mailto:`, `tel:` und Ankerspruenge — sie fuehren
     * nirgendwohin, was sich zaehlen liesse, und ueber die Umleitung geschickt
     * waeren sie schlicht kaputt. Das Muster greift deshalb nur `http(s)`.
     *
     * Ebenso ausgenommen ist die eigene Messung: Ein bereits umgeschriebener
     * Link ergaebe sonst bei einem zweiten Durchlauf eine Umleitung auf eine
     * Umleitung.
     */
    private function linkeUmschreiben(string $rumpf, MailDispatch $eintrag): string
    {
        $eigenerPfad = '/'.trim((string) config('mass-mailer.messung.prefix', 'mail'), '/').'/messung/';

        return preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $treffer) use ($eintrag, $eigenerPfad): string {
                $ziel = html_entity_decode($treffer[1], ENT_QUOTES);

                if (str_contains($ziel, $eigenerPfad)) {
                    return $treffer[0];
                }

                $url = URL::signedRoute('mass-mailer.messung.klick', [
                    'dispatch' => $eintrag->id,
                    'ziel' => $ziel,
                ]);

                return 'href="'.e($url).'"';
            },
            $rumpf
        ) ?? $rumpf;
    }
}
