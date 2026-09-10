<?php

namespace Peppermint\MassMailer\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Was verschickt werden soll — bevor es verschickt ist.
 *
 * Ein Wertobjekt statt acht Parameter am Dienst. Der Unterschied zeigt sich
 * beim Lesen des Aufrufs: `verschicken($entwurf, $empfaenger, $bauen)` sagt,
 * was passiert; acht Argumente in Reihe sagen es nicht, und beim neunten
 * vertauscht sie jemand.
 *
 * ## Betreff und Rumpf gehoeren hierher, nicht in die Vorlage
 *
 * Sie werden mit der Kampagne gespeichert, auch wenn sie aus einer Vorlage
 * stammen. Die Historie muss zeigen, **was verschickt wurde**, und nicht, was
 * in der Vorlage inzwischen steht. Wer die Vorlage nach dem Versand aendert,
 * darf damit nicht rueckwirkend die Vergangenheit aendern.
 */
final class Kampagnenentwurf
{
    /**
     * @param  Model|null  $bereich  Worin die Kampagne steht (Connect: die Veranstaltung)
     * @param  array<string, mixed>  $filter  Wie die Empfaenger ausgewaehlt wurden — zum Nachvollziehen
     * @param  int|null  $vorlageId  Die Vorlage, aus der es entstand; `null` bei Freitext
     * @param  int|null  $ausgeloestVonId  Wer den Versand angestossen hat
     * @param  int|null  $mandantId  Ohne Angabe wird versucht, ihn aus dem Bereich zu lesen
     */
    public function __construct(
        public readonly string $betreff,
        public readonly string $rumpf,
        public readonly ?Model $bereich = null,
        public readonly array $filter = [],
        public readonly ?int $vorlageId = null,
        public readonly ?int $ausgeloestVonId = null,
        public readonly ?int $mandantId = null,
    ) {}
}
