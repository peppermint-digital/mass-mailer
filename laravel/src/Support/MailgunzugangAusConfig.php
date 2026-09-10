<?php

namespace Peppermint\MassMailer\Support;

use Peppermint\MassMailer\Contracts\Mailgunzugang;

/**
 * Die Standardfassung: Zugangsdaten aus der Konfiguration.
 *
 * Bewusst auch als Rueckfall auf Laravels eigene `services.mailgun.*` — wer
 * Mailgun ohnehin als Mailer eingerichtet hat, hat die Werte dort schon stehen
 * und soll sie nicht ein zweites Mal pflegen. Zwei Stellen fuer denselben
 * Schluessel laufen auseinander, und gemerkt wird es an dem Tag, an dem der
 * Abgleich still nichts mehr findet.
 */
class MailgunzugangAusConfig implements Mailgunzugang
{
    public function schluessel(): ?string
    {
        return $this->ersterWert(['mass-mailer.mailgun.secret', 'services.mailgun.secret']);
    }

    public function domain(): ?string
    {
        return $this->ersterWert(['mass-mailer.mailgun.domain', 'services.mailgun.domain']);
    }

    public function endpunkt(): string
    {
        return $this->ersterWert(['mass-mailer.mailgun.endpoint', 'services.mailgun.endpoint'])
            ?? 'api.eu.mailgun.net';
    }

    public function vorhanden(): bool
    {
        return $this->schluessel() !== null && $this->domain() !== null;
    }

    /**
     * Der erste Schluessel, der einen nicht-leeren Wert hat.
     *
     * Ein leerer String zaehlt als „nicht gesetzt". Das ist der Normalfall bei
     * einer `.env`-Zeile ohne Wert (`MAILGUN_DOMAIN=`), und ein leerer Wert,
     * der einen gesetzten Rueckfall verdeckt, ist genau die Art Ausfall, die
     * niemand sucht.
     *
     * @param  array<int, string>  $schluessel
     */
    private function ersterWert(array $schluessel): ?string
    {
        foreach ($schluessel as $eintrag) {
            $wert = config($eintrag);

            if (is_string($wert) && trim($wert) !== '') {
                return trim($wert);
            }
        }

        return null;
    }
}
