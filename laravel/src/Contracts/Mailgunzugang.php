<?php

namespace Peppermint\MassMailer\Contracts;

use Peppermint\MassMailer\Support\MailgunzugangAusConfig;

/**
 * Woher die Mailgun-Zugangsdaten kommen.
 *
 * Der Standard ({@see MailgunzugangAusConfig})
 * liest sie aus der Konfiguration, also letztlich aus der `.env`. Das genuegt
 * fuer die meisten Anwendungen.
 *
 * Connect bindet stattdessen eine eigene Fassung, die sie aus den
 * Plattformeinstellungen holt: Dort pflegt sie der Betreiber in der
 * Oberflaeche, und ein Wechsel des Kontos soll kein Deploy sein.
 *
 * ## Der Endpunkt ist keine Nebensache
 *
 * Ein Konto in der EU muss gegen `api.eu.mailgun.net` gefragt werden. Wird der
 * US-Endpunkt benutzt, antwortet Mailgun mit **404 „Domain not found"** — als
 * gaebe es die Domain nicht. Das ist der haeufigste Einrichtungsfehler, und er
 * sieht nach etwas voellig anderem aus.
 */
interface Mailgunzugang
{
    /** Der private API-Schluessel — nicht das SMTP-Passwort. */
    public function schluessel(): ?string;

    /** Die Versand-Domain, etwa `mg.beispiel.de`. */
    public function domain(): ?string;

    /** `api.eu.mailgun.net` oder `api.mailgun.net`. */
    public function endpunkt(): string;

    /** Sind ueberhaupt Zugangsdaten hinterlegt? */
    public function vorhanden(): bool;
}
