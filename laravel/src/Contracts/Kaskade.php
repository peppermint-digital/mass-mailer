<?php

namespace Peppermint\MassMailer\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Die Frage, die jede Einstellung des Versands stellt: **wen frage ich zuerst?**
 *
 * Postausgang, Versandtempo und (spaeter) Vorlagen laufen alle dieselbe Kette
 * ab — von speziell nach allgemein, und die erste Stufe mit einer Antwort
 * gewinnt. In Connect ist das Veranstaltung → Organisation → Weltkonfiguration.
 * Im CRM koennte es Marke → Weltkonfiguration sein, in einer Anwendung ohne
 * Mandanten nur die Weltkonfiguration.
 *
 * Das Paket kennt keine dieser Hierarchien. Es fragt hier nach der Kette und
 * laeuft sie ab.
 *
 * ## Warum EINE Kette und nicht drei
 *
 * Weil sich sonst niemand merken kann, welche wo gilt. In Connect war das von
 * Anfang an so gehalten: „dieselbe Reihenfolge wie bei den Vorlagen, damit sich
 * niemand zwei unterschiedliche Ketten merken muss." Eine zweite Kette waere
 * eine zweite Gelegenheit, sie auseinanderlaufen zu lassen.
 *
 * ## Die Weltkonfiguration steht NICHT in der Kette
 *
 * Sie ist keine Ebene, sondern das, was uebrigbleibt, wenn keine Ebene
 * geantwortet hat. Sie als letztes Element mitzuliefern haette einen
 * Modell-Platzhalter fuer etwas gebraucht, das kein Modell ist.
 *
 * ## Beispiel (Connect)
 *
 * ```php
 * public function ebenen(?Model $bereich): array
 * {
 *     if (! $bereich instanceof Event) {
 *         return [];
 *     }
 *
 *     $organisation = $bereich->organization;
 *
 *     return array_filter([$bereich, $organisation]);
 * }
 * ```
 */
interface Kaskade
{
    /**
     * Die Ebenen zu einem Bereich — von speziell nach allgemein.
     *
     * Ein leeres Array ist eine gueltige Antwort und heisst: es gilt direkt die
     * Weltkonfiguration.
     *
     * @return array<int, Model>
     */
    public function ebenen(?Model $bereich): array;
}
