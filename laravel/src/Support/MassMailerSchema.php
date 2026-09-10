<?php

namespace Peppermint\MassMailer\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Die Form der Tabellen des Pakets — an einer Stelle.
 *
 * **Bewusst KEINE Paket-Migration.** Genau wie beim Baukasten gilt: Der Host
 * besitzt seine Tabellen. Ein Paket, das beim `composer update` still eine
 * Migration mitbringt, die beim naechsten Deploy gegen eine Produktionstabelle
 * laeuft, ist ein Selbstschussgeraet. Stattdessen erzeugt
 * `php artisan mass-mailer:install` eine Migration IN der Anwendung, die diese
 * Helfer aufruft — sichtbar, lesbar, im eigenen Repository versioniert.
 *
 * ## Warum die Bezuege polymorph sind
 *
 * In Connect ist der Bezug einer Mail eine Anmeldung, im CRM ein Lead, in der
 * Verwaltung ein Kundenkontakt. Ein `registration_id` im Paket haette Connects
 * Vokabular allen anderen aufgezwungen.
 *
 * Zwei Ebenen, weil beide gebraucht werden und sie Verschiedenes bedeuten:
 *
 * - **`bereich`** — worin die Mail steht (Connect: die Veranstaltung). Danach
 *   wird gefiltert und ausgewertet.
 * - **`bezug`** — um wen es geht (Connect: die Anmeldung). Daran haengt „was
 *   ging an diese Person raus?".
 *
 * ## Warum hier keine Fremdschluessel stehen
 *
 * Das Paket kennt weder die Tabellennamen des Hosts noch dessen Loeschregeln.
 * Die Spalten sind deshalb nackte `unsignedBigInteger`. Wer Fremdschluessel
 * will, setzt sie in derselben Migration hinterher — mit `nullOnDelete()` und
 * nicht `cascadeOnDelete()`: Wird eine Anmeldung geloescht, ist die Frage „was
 * ist damals rausgegangen?" nicht erledigt, sondern erst recht interessant.
 * Die Adresse steht ohnehin als Text daneben.
 */
class MassMailerSchema
{
    public const TABELLE_PROTOKOLL = 'mail_dispatches';

    public const TABELLE_VERSUCHE = 'mail_dispatch_versuche';

    public const TABELLE_POSTAUSGAENGE = 'mail_servers';

    public const TABELLE_KAMPAGNEN = 'mass_email_campaigns';

    /**
     * Der eingetragene Tabellenname — oder die Vorgabe.
     *
     * **Nicht `config($schluessel, $vorgabe)`.** Laravels zweites Argument
     * greift nur, wenn der Schluessel FEHLT — nicht, wenn er da ist und `null`
     * enthaelt. Eine ausgeleerte Zeile in der publizierten Konfiguration
     * ergaebe sonst eine Abfrage gegen die Tabelle `` — und die Fehlermeldung
     * zeigt auf die Datenbank statt auf die Konfiguration.
     */
    public static function tabelle(string $name, string $vorgabe): string
    {
        $wert = config('mass-mailer.tabellen.'.$name);

        return is_string($wert) && $wert !== '' ? $wert : $vorgabe;
    }

    /**
     * Die Protokolltabelle: eine Zeile je verschickter Mail.
     *
     * Fast alles ist `nullable`, und das ist keine Nachlaessigkeit. Nicht jede
     * Mail gehoert zu einem Bereich (eine Team-Einladung gehoert zu keiner
     * Veranstaltung), nicht jede zu einem Mandanten (manche entstehen, bevor
     * ein Mandant feststeht). **Eine magere Zeile ist besser als keine Zeile** —
     * ein Protokoll, das den Versand abbricht, weil ein Bezug fehlt, waere die
     * Umkehrung seines Zwecks.
     */
    public static function protokollTabelle(Blueprint $table): void
    {
        $table->id();

        $table->unsignedBigInteger(Mandant::spalte())->nullable();

        $table->string('bereich_typ')->nullable();
        $table->unsignedBigInteger('bereich_id')->nullable();

        $table->string('bezug_typ')->nullable();
        $table->unsignedBigInteger('bezug_id')->nullable();

        $table->unsignedBigInteger('kampagne_id')->nullable();
        $table->unsignedBigInteger('ausgeloest_von_id')->nullable();

        $table->string('empfaenger');

        // Kein Enum: Die Liste der Mail-Arten waechst mit jeder neuen Mail, und
        // eine Zeile im Protokoll darf nie daran scheitern, dass die Art
        // unbekannt ist.
        $table->string('art')->nullable();
        $table->string('betreff')->nullable();

        // im_versand → verschickt | fehlgeschlagen
        $table->string('status')->default('im_versand');

        // Vorgabe 1 und nicht 0: Eine Zeile entsteht erst, wenn ein Versuch
        // beginnt. „0 Versuche" gaebe es also nie.
        $table->unsignedSmallInteger('versuche')->default(1);

        $table->text('fehler')->nullable();

        $table->timestamp('verschickt_am')->nullable();
        $table->timestamp('fehlgeschlagen_am')->nullable();

        self::zustellungsSpalten($table);
        self::messungsSpalten($table);

        $table->timestamps();

        // Die drei Fragen, die tatsaechlich gestellt werden: „was ging an
        // diesen Bezug?", „was ging an diese Adresse?" (auch wenn der Bezug
        // inzwischen weg ist) und „wie steht es um diesen Bereich?".
        $table->index(['bezug_typ', 'bezug_id', 'created_at'], 'protokoll_bezug');
        $table->index(['empfaenger', 'created_at'], 'protokoll_empfaenger');
        $table->index(['bereich_typ', 'bereich_id', 'status'], 'protokoll_bereich');

        // Der Abgleich fragt „welche Zeilen sind noch offen?" — ueber diese
        // beiden Spalten, und zwar ueber den ganzen Bestand.
        $table->index(['status', 'zustellung_geprueft_am'], 'protokoll_abgleich');
    }

    /**
     * Was nach der Annahme durch den Postausgang passiert.
     *
     * Getrennt von `status`, und das ist der Kern: `status` beantwortet „haben
     * WIR die Mail losgeschickt?" und hoert genau dort auf, wo es fuer den
     * Empfaenger interessant wird. `verschickt` heisst angenommen, nicht
     * zugestellt.
     *
     * Beides in eine Spalte zu rechnen waere verlockend und falsch: Dann
     * verloere man die Unterscheidung zwischen „wir haben es versucht und es
     * hat geklappt" und „der Anbieter sagt, es kam an". Zwei Aussagen, zwei
     * Quellen, zwei Zuverlaessigkeiten — und die zweite gibt es nur, wo ein
     * Konto beim Anbieter verbunden ist. Wer sie vermischt, kann hinterher
     * nicht mehr sagen, ob eine leere Zelle „nicht zugestellt" oder „nicht
     * gemessen" heisst.
     */
    public static function zustellungsSpalten(Blueprint $table): void
    {
        // `null` heisst „nicht gemessen" — nicht „nicht angekommen".
        $table->string('zustellung_status')->nullable();
        $table->timestamp('zustellung_am')->nullable();

        // Der Wortlaut des Anbieters. „550 5.1.1 user unknown" sagt jemandem,
        // der nachsieht, mehr als jede Zusammenfassung von uns.
        $table->text('zustellung_grund')->nullable();

        // Ein MERKER, kein Zaehler. Der Abgleich laeuft ueber ueberlappende
        // Fenster und sieht dasselbe Ereignis mehrfach — ein `increment()`
        // waere bei jedem Lauf mitgewachsen, ohne dass etwas passiert ist.
        // Ein Ja/Nein ist idempotent.
        $table->boolean('zustellung_wiederholt')->default(false);

        // Wann zuletzt nachgefragt wurde. Trennt „der Abgleich lief und fand
        // nichts" von „der Abgleich lief fuer diese Zeile nie" — ohne das
        // sieht ein ausgefallener Abgleich aus wie ein stiller Versand.
        $table->timestamp('zustellung_geprueft_am')->nullable();

        $table->string('anbieter_message_id')->nullable();
    }

    /**
     * Oeffnungen und Klicks.
     *
     * Zaehler zusaetzlich zum Zeitstempel: Der Zeitstempel beantwortet „wann
     * zuerst", der Zaehler „wie oft" — und ein Zaehler, der bei einer einzigen
     * Mail auf 40 steht, ist selbst der Hinweis darauf, dass ein
     * Sicherheits-Scanner die Links abklappert und kein Mensch.
     */
    public static function messungsSpalten(Blueprint $table): void
    {
        $table->timestamp('geoeffnet_am')->nullable();
        $table->timestamp('geklickt_am')->nullable();
        $table->unsignedInteger('oeffnungen')->default(0);
        $table->unsignedInteger('klicks')->default(0);
    }

    /**
     * Die Versuchstabelle: wie oft es gebraucht hat, und woran es lag.
     *
     * Die Protokollzeile sagt, was aus einer Mail geworden ist. Diese Tabelle
     * sagt, wie oft es dafuer gebraucht hat — und woran die Anlaeufe davor
     * scheiterten. Ohne sie steht nach einem dreimal gescheiterten Job nur der
     * letzte Fehler da, und „hat beim zweiten Mal geklappt" sieht aus wie „ging
     * sofort raus".
     */
    public static function versucheTabelle(Blueprint $table, string $protokollTabelle = self::TABELLE_PROTOKOLL): void
    {
        $table->id();

        // Faellt die Protokollzeile weg, haben ihre Versuche keinen Sinn mehr —
        // sie beschreiben ja nichts anderes als diesen einen Vorgang. Hier
        // also ausdruecklich `cascade`, anders als bei den Bezuegen oben.
        $table->foreignId('mail_dispatch_id')
            ->constrained($protokollTabelle)
            ->cascadeOnDelete();

        $table->unsignedSmallInteger('nummer');

        $table->timestamp('begonnen_am');

        // `null`, solange der Versuch laeuft. Genau dieser Zustand ist die
        // interessante Zwischenstufe: Ein Versuch ohne Ergebnis ist einer, bei
        // dem der Worker abgebrochen wurde, bevor er berichten konnte.
        $table->string('ergebnis')->nullable();

        $table->timestamp('beendet_am')->nullable();
        $table->text('fehler')->nullable();

        $table->timestamps();

        $table->index(['mail_dispatch_id', 'nummer'], 'versuch_je_zeile');
    }

    /**
     * Die Postausgaenge: ein eigener SMTP-Zugang je Ebene der Kaskade.
     *
     * `unique(owner_type, owner_id)`: eine Ebene hat hoechstens einen eigenen
     * Postausgang. Mehrere waeren keine Einstellung mehr, sondern eine Auswahl
     * — und die muesste jemand treffen.
     *
     * Von Hand statt `morphs()`: das legte einen zweiten Index auf dieselben
     * beiden Spalten, die der eindeutige Index schon abdeckt.
     */
    public static function postausgangTabelle(Blueprint $table): void
    {
        $table->id();

        $table->string('owner_type');
        $table->unsignedBigInteger('owner_id');

        $table->string('host');
        $table->unsignedSmallInteger('port')->default(587);

        // 'tls', 'ssl' oder null (unverschluesselt). Kein Enum, weil Symfony
        // die Liste vorgibt und nicht diese Migration.
        $table->string('encryption')->nullable();

        // `text` und nicht `string`: Beide Werte sind verschluesselt
        // abgelegt, und der Geheimtext ist deutlich laenger als das Original.
        $table->text('username')->nullable();
        $table->text('password')->nullable();

        // Ein fremder Postausgang nimmt eine Mail meist nur an, wenn sie von
        // einer seiner eigenen Adressen kommt — der Absender gehoert deshalb zu
        // den Zugangsdaten und nicht in die Weltkonfiguration.
        $table->string('from_address')->nullable();
        $table->string('from_name')->nullable();

        // Abschalten, ohne die Zugangsdaten zu verlieren: die Mail geht dann
        // wieder den Weg der naechsten Stufe.
        $table->boolean('is_active')->default(true);

        $table->timestamps();

        $table->unique(['owner_type', 'owner_id']);
    }

    /**
     * Die Kampagnen: was an viele rausging.
     *
     * `betreff` und `rumpf` sind eine Kopie zum Zeitpunkt des Versands, auch
     * wenn sie aus einer Vorlage stammen — die Historie muss zeigen, was
     * verschickt WURDE, nicht was in der Vorlage inzwischen steht.
     *
     * `empfaenger_anzahl` ist die Zahl beim Einreihen und beantwortet damit
     * eine andere Frage als das Protokoll: „an wie viele war es gedacht?"
     * gegen „bei wie vielen hat es geklappt?". Die zweite steht in
     * {@see self::protokollTabelle()}, und die beiden duerfen auseinandergehen
     * — genau das ist die interessante Information.
     */
    public static function kampagnenTabelle(Blueprint $table): void
    {
        $table->id();

        $table->unsignedBigInteger(Mandant::spalte())->nullable();

        $table->string('bereich_typ')->nullable();
        $table->unsignedBigInteger('bereich_id')->nullable();

        $table->unsignedBigInteger('vorlage_id')->nullable();
        $table->unsignedBigInteger('ausgeloest_von_id')->nullable();

        $table->string('betreff');
        $table->longText('rumpf');

        // Wie die Empfaenger ausgewaehlt wurden. Zum Nachvollziehen, nicht zum
        // Wiederholen: Der Bestand hat sich seither geaendert, und derselbe
        // Filter traefe heute andere Leute.
        $table->json('filter')->nullable();

        $table->unsignedInteger('empfaenger_anzahl')->default(0);
        $table->timestamp('gestartet_am')->nullable();

        $table->timestamps();

        $table->index(['bereich_typ', 'bereich_id', 'gestartet_am'], 'kampagne_bereich');
    }
}
