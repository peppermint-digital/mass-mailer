<?php

namespace Peppermint\MassMailer\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\MailDispatchVersuch;
use Peppermint\MassMailer\Models\MailServer;
use Peppermint\MassMailer\Models\Versandkampagne;

/**
 * Welche Modellklassen das Paket benutzt — und warum das eine Frage ist.
 *
 * Naheliegend waere, im Dienst schlicht `MailDispatch::create(...)` zu
 * schreiben. Das ist genau so lange richtig, bis eine Anwendung ihrem Modell
 * etwas mitgeben muss, das nur sie kennt.
 *
 * ## Der Fall, der das ausgeloest hat
 *
 * Connect haengt an fast jedem Modell einen Mandanten-Scope
 * (`BelongsToOrganization`): Er filtert jede Abfrage auf die Organisation der
 * angemeldeten Person und traegt sie beim Anlegen ein. Ein Waechter-Test
 * erzwingt, dass **jedes** mandantenbehaftete Modell ihn hat.
 *
 * Das Paket kann diesen Scope nicht mitbringen — es kennt Connects Mandanten
 * nicht. Und Connect kann ihn nicht nachruesten, solange das Paket seine eigene
 * Klasse fest verdrahtet.
 *
 * Also erbt die Anwendung:
 *
 * ```php
 * class MailDispatch extends \Peppermint\MassMailer\Models\MailDispatch
 * {
 *     use BelongsToOrganization;
 * }
 * ```
 *
 * und traegt sie in `config('mass-mailer.modelle.protokoll')` ein.
 *
 * ## Warum ueber die Konfiguration und nicht ueber den Container
 *
 * Weil es hier nicht um EINE Instanz geht, sondern um Abfragen: `::query()`,
 * `::create()`, `hasMany(...)`. Eine Container-Bindung loest davon nur den
 * Konstruktor auf — der Klassenname wird an all den anderen Stellen trotzdem
 * gebraucht.
 */
class Modelle
{
    /** @return class-string<Model> */
    public static function protokoll(): string
    {
        return static::klasse('protokoll', MailDispatch::class);
    }

    /** @return class-string<Model> */
    public static function versuch(): string
    {
        return static::klasse('versuch', MailDispatchVersuch::class);
    }

    /** @return class-string<Model> */
    public static function postausgang(): string
    {
        return static::klasse('postausgang', MailServer::class);
    }

    /** @return class-string<Model> */
    public static function kampagne(): string
    {
        return static::klasse('kampagne', Versandkampagne::class);
    }

    /**
     * Der eingetragene Klassenname — oder der des Pakets.
     *
     * **Nicht `config($schluessel, $vorgabe)`.** Laravels zweites Argument
     * greift nur, wenn der Schluessel FEHLT — nicht, wenn er da ist und `null`
     * enthaelt. Genau das ist hier aber der wahrscheinliche Fall: Wer die
     * Konfiguration publiziert und eine Zeile auskommentiert oder leert, bekaeme
     * sonst keinen Rueckfall, sondern einen `TypeError` mitten im Versand.
     *
     * @param  class-string<Model>  $vorgabe
     * @return class-string<Model>
     */
    private static function klasse(string $name, string $vorgabe): string
    {
        $wert = config('mass-mailer.modelle.'.$name);

        return is_string($wert) && $wert !== '' ? $wert : $vorgabe;
    }

    /**
     * Eine Abfrage auf die Protokolltabelle.
     *
     * **Ohne globale Scopes.** Das ist keine Bequemlichkeit, sondern noetig:
     * Das Protokoll entsteht im Queue-Worker, und dort ist kein Mandant aktiv.
     * Ein Mandanten-Scope wuerde dann entweder alles wegfiltern oder abbrechen —
     * und ein Protokoll, das den Versand abbricht, waere die Umkehrung seines
     * Zwecks.
     *
     * Beim ANLEGEN gilt das Gegenteil: Dort soll der Scope der Anwendung
     * greifen und den Mandanten eintragen. Deshalb geht {@see self::anlegen()}
     * den normalen Weg.
     *
     * @return Builder<Model>
     */
    public static function protokollAbfrage(): Builder
    {
        return static::protokoll()::query()->withoutGlobalScopes();
    }

    /**
     * Legt eine Protokollzeile an — ueber den normalen Weg der Anwendung.
     *
     * @param  array<string, mixed>  $werte
     */
    public static function anlegen(array $werte): Model
    {
        $klasse = static::protokoll();

        return $klasse::create($werte);
    }
}
