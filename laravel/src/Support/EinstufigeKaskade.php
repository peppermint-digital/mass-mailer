<?php

namespace Peppermint\MassMailer\Support;

use Illuminate\Database\Eloquent\Model;
use Peppermint\MassMailer\Contracts\Kaskade;

/**
 * Die Vorgabe: der Bereich selbst, und sonst nichts.
 *
 * Damit funktioniert das Paket ohne jede Bindung — eine Anwendung ohne
 * Hierarchie (oder eine, die noch keine braucht) bekommt genau eine Ebene und
 * faellt danach auf die Weltkonfiguration zurueck.
 *
 * Wer mehr Stufen hat, bindet eine eigene {@see Kaskade}.
 */
class EinstufigeKaskade implements Kaskade
{
    /** @return array<int, Model> */
    public function ebenen(?Model $bereich): array
    {
        return $bereich === null ? [] : [$bereich];
    }
}
