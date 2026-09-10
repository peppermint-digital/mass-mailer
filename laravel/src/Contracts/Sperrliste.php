<?php

namespace Peppermint\MassMailer\Contracts;

use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Support\Zustellfehler;

/**
 * Wohin eine tote Adresse gesperrt wird.
 *
 * Das Paket erkennt, DASS eine Adresse endgueltig unbrauchbar ist — das macht
 * {@see Zustellfehler}, und diese Unterscheidung
 * ist ueberall dieselbe. Wo der Vermerk hingehoert, ist es nicht: Connect
 * schreibt ihn an den Kontakt, das CRM an den Lead, die Verwaltung an den
 * Kundenkontakt.
 *
 * ## Warum das nicht am Bezug haengen darf
 *
 * Naheliegend waere, den Vermerk einfach am polymorphen Bezug zu machen. Das
 * waere falsch: In Connect ist der Bezug eine ANMELDUNG, die Adresse gehoert
 * aber der PERSON und gilt fuer jede kuenftige Veranstaltung desselben
 * Veranstalters. Haenge man es an die Anmeldung, schriebe man dieselbe kaputte
 * Adresse bei der naechsten Einladung wieder an.
 *
 * Wie weit ein Vermerk traegt, weiss nur die Anwendung. Deshalb bekommt sie die
 * ganze Protokollzeile und entscheidet selbst.
 *
 * ## Fehler duerfen hier nichts abbrechen
 *
 * Aufgerufen wird das im Fehlerpfad — der Versand ist ohnehin schon
 * gescheitert. Eine Ausnahme aus dieser Methode wird gefangen und protokolliert,
 * ein misslungener Vermerk darf daraus keinen zweiten Fehler machen.
 */
interface Sperrliste
{
    /**
     * Diese Adresse ist dauerhaft nicht erreichbar.
     *
     * @param  string  $adresse  Die Empfaengeradresse
     * @param  string  $grund  Die Meldung des empfangenden Servers, gekuerzt
     * @param  string  $herkunft  `versandfehler` (eigener Versand) oder `zustellabgleich` (Anbieter-Meldung)
     * @return bool Ob tatsaechlich etwas gesperrt wurde — nur fuer die Statistik des Abgleichs
     */
    public function sperren(
        string $adresse,
        string $grund,
        string $herkunft,
        ?MailDispatch $eintrag = null,
    ): bool;
}
