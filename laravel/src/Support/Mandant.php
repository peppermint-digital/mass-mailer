<?php

namespace Peppermint\MassMailer\Support;

/**
 * Wie der Mandant in dieser Anwendung heisst.
 *
 * Zwei verschiedene Dinge, die leicht verwechselt werden:
 *
 * - **`spalte()`** — wie das Feld in den Tabellen DIESES Pakets heisst.
 * - **`ableitenAus()`** — aus welchem Feld des Bereichs der Mandant gelesen
 *   wird, wenn ein Kampagnenentwurf ihn nicht selbst nennt.
 *
 * ## Warum die Spalte ueberhaupt frei ist
 *
 * Dieselbe Ueberlegung wie bei `owner_type` am Postausgang: Was schon dem Host
 * gehoert, wird nicht umbenannt.
 *
 * Der Fall, der das erzwungen hat: Connect haengt an jedem mandantenbehafteten
 * Modell den Scope `BelongsToOrganization`. Der ist fest auf `organization_id`
 * verdrahtet — er filtert danach und traegt beim Anlegen dort ein. Haette das
 * Paket auf `mandant_id` bestanden, waere die Wahl gewesen: entweder Connects
 * Mandantentrennung im Protokoll aufgeben oder Connects gesamtes
 * Tenancy-System anfassen. Beides fuer einen Spaltennamen.
 *
 * Eine Anwendung ohne Mandanten laesst beides stehen und ignoriert die Spalte.
 */
class Mandant
{
    public const VORGABE_SPALTE = 'mandant_id';

    public const VORGABE_ABLEITUNG = 'organization_id';

    /** Das Feld in den Tabellen dieses Pakets. */
    public static function spalte(): string
    {
        return static::wert('spalte', self::VORGABE_SPALTE);
    }

    /** Das Feld am Bereich, aus dem der Mandant abgeleitet wird. */
    public static function ableitenAus(): string
    {
        return static::wert('ableiten_aus', self::VORGABE_ABLEITUNG);
    }

    /**
     * Nicht `config($schluessel, $vorgabe)` — Laravels zweites Argument greift
     * nur, wenn der Schluessel FEHLT, nicht wenn er auf `null` steht. Ein
     * leerer Eintrag muendete sonst in `getAttribute('')` und damit still in
     * „kein Mandant", oder in eine Spalte namens `` beim Schreiben.
     */
    private static function wert(string $name, string $vorgabe): string
    {
        $wert = config('mass-mailer.mandant.'.$name);

        return is_string($wert) && $wert !== '' ? $wert : $vorgabe;
    }
}
