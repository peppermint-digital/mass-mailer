<?php

namespace Peppermint\MassMailer\Support;

/**
 * Die Schluessel, unter denen eine Mail dem Protokoll erzaehlt, was sie ist.
 *
 * Sie reisen als `Mailable::metadata()` mit. Das ueberlebt die Queue, weil es
 * ein einfaches Array ist — anders als ein Closure, den man an die Mail haengen
 * koennte.
 *
 * Als Konstanten und nicht als lose Strings, weil ein Tippfehler hier keinen
 * Fehler ausloest: Die Zeile entsteht trotzdem, nur ohne den Bezug. Der Ausfall
 * faellt erst auf, wenn jemand im Protokoll nach etwas sucht und es nicht
 * findet.
 *
 * ## Beispiel
 *
 * ```php
 * public function envelope(): Envelope
 * {
 *     return new Envelope(
 *         subject: 'Ihre Anmeldung',
 *         metadata: [
 *             Metadatenschluessel::ART => 'anmeldebestaetigung',
 *             Metadatenschluessel::MANDANT => (string) $this->event->organization_id,
 *             Metadatenschluessel::BEREICH_TYP => Event::class,
 *             Metadatenschluessel::BEREICH => (string) $this->event->id,
 *             Metadatenschluessel::BEZUG_TYP => Registration::class,
 *             Metadatenschluessel::BEZUG => (string) $this->registration->id,
 *         ],
 *     );
 * }
 * ```
 *
 * Alle Werte sind Strings — Symfony traegt Metadaten als Kopfzeilen, und eine
 * Kopfzeile kennt nichts anderes.
 */
class Metadatenschluessel
{
    /** Welche Art Mail das ist (`anmeldebestaetigung`, `bulk`, …). */
    public const ART = 'art';

    /** Der Mandant, dem diese Mail gehoert. */
    public const MANDANT = 'mandant';

    /** Modellklasse des Bereichs, in dem die Mail steht. */
    public const BEREICH_TYP = 'bereich_typ';

    /** Schluessel des Bereichs. */
    public const BEREICH = 'bereich';

    /** Modellklasse dessen, um den es geht. */
    public const BEZUG_TYP = 'bezug_typ';

    /** Schluessel des Bezugs. */
    public const BEZUG = 'bezug';

    /** Die Kampagne, aus der die Mail stammt. */
    public const KAMPAGNE = 'kampagne';

    /** Wer den Versand angestossen hat. */
    public const AUSGELOEST_VON = 'ausgeloest_von';

    /**
     * Ob fuer DIESE Mail gemessen werden darf — `1` oder nichts.
     *
     * Bewusst je Mail und nicht als globaler Schalter: Ob gemessen werden darf,
     * entscheidet die Anwendung anhand ihrer eigenen Regeln (in Connect je
     * Veranstaltung). Das Paket soll diese Entscheidung nicht nachbauen, es
     * soll sie nur befolgen.
     */
    public const MESSUNG_OEFFNUNGEN = 'messung_oeffnungen';

    public const MESSUNG_KLICKS = 'messung_klicks';
}
