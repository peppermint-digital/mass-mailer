<?php

namespace Peppermint\MassMailer\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Beantwortet die Frage, die das Protokoll nicht selbst beantworten kann:
 * **um wen geht es in dieser Mail?**
 *
 * In Connect ist das eine Anmeldung, im CRM ein Kontakt, in der Verwaltung ein
 * Kundenkontakt. Das Paket kennt keinen davon — es speichert den Bezug
 * polymorph (`bezug_typ`/`bezug_id`) und fragt hier nach, wenn die Mail ihn
 * nicht selbst mitgebracht hat.
 *
 * ## Wann diese Stelle ueberhaupt gefragt wird
 *
 * Der Regelfall ist, dass die Mail ihren Bezug als Metadatum mitfuehrt
 * (`Mailable::metadata(['bezug_typ' => …, 'bezug_id' => …])`). Dann steht er
 * fest und niemand muss raten.
 *
 * Gefragt wird nur beim Rest: eine Mail, die noch keine Metadaten setzt, ein
 * Passwort-Zuruecksetzen, eine Benachrichtigung aus einem fremden Paket. Fuer
 * die ist eine Zuordnung ueber die Adresse besser als gar keine — aber sie ist
 * geraten, und deshalb gehoert sie in die Anwendung, die weiss, wie eindeutig
 * eine Adresse bei ihr ist.
 *
 * ## Die Falle, die es in Connect gab
 *
 * Die Suche ueber die Adresse muss auf den Bereich eingegrenzt werden. Ohne das
 * haengt eine Bestaetigung fuer Veranstaltung A an der Anmeldung fuer
 * Veranstaltung B, sobald jemand bei beiden dabei ist. Deshalb bekommt die
 * Methode den Bereich mit — und darf ohne ihn ruhig `null` liefern.
 */
interface Bezugsaufloeser
{
    /**
     * Wen meint diese Adresse innerhalb dieses Bereichs?
     *
     * `null` ist eine gueltige Antwort und heisst „weiss ich nicht" — die
     * Protokollzeile entsteht dann trotzdem, nur ohne Bezug. Lieber eine magere
     * Zeile als eine fehlende.
     *
     * @param  string  $adresse  Die Empfaengeradresse, bereits getrimmt und klein
     * @param  string|null  $bereichTyp  Modellklasse des Bereichs (Connect: Event)
     * @param  int|null  $bereichId  Schluessel des Bereichs
     * @param  array<string, string>  $metadaten  Alles, was die Mail sonst noch mitfuehrt
     */
    public function ausAdresse(
        string $adresse,
        ?string $bereichTyp,
        ?int $bereichId,
        array $metadaten = [],
    ): ?Model;
}
