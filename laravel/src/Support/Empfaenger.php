<?php

namespace Peppermint\MassMailer\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Empfaenger einer Kampagne — Adresse, Platzhalterwerte, Bezug.
 *
 * Ein Wertobjekt und kein Array, weil an dieser Stelle in Connect genau der
 * Fehler passiert ist, gegen den ein Wertobjekt hilft: Die Empfaengerliste war
 * ein Array mit `email`, den Platzhaltern UND `registration_id` in einer Ebene.
 * Beim Bauen der Mail musste die Nummer dann wieder herausgefiltert werden
 * (`Arr::except($recipient, ['registration_id'])`), damit sie nicht als
 * Platzhalter in der Mail landet — und wer einen weiteren technischen Wert
 * ergaenzte, musste an zwei Stellen daran denken.
 *
 * Hier sind die drei Dinge getrennt, weil sie drei verschiedene Dinge sind.
 */
final class Empfaenger
{
    /**
     * @param  string  $adresse  Die E-Mail-Adresse
     * @param  array<string, string>  $platzhalter  Werte fuer die Vorlage — und NUR die
     * @param  Model|null  $bezug  Um wen es geht; landet im Versandprotokoll
     */
    public function __construct(
        public readonly string $adresse,
        public readonly array $platzhalter = [],
        public readonly ?Model $bezug = null,
    ) {}

    /**
     * Aus einem Array, wie es eine Abfrage liefert.
     *
     * @param  array<string, mixed>  $werte
     */
    public static function aus(array $werte, ?Model $bezug = null): self
    {
        $adresse = (string) ($werte['email'] ?? $werte['adresse'] ?? '');

        return new self(
            adresse: $adresse,
            platzhalter: array_map(
                static fn ($wert): string => (string) $wert,
                array_filter(
                    array_diff_key($werte, array_flip(['email', 'adresse'])),
                    static fn ($wert): bool => is_scalar($wert) || $wert === null,
                ),
            ),
            bezug: $bezug,
        );
    }

    /**
     * Hat dieser Empfaenger ueberhaupt eine Adresse?
     *
     * Eine leere Adresse ist kein Fehler, sondern ein Alltagsfall: ein Kontakt
     * ohne hinterlegte Mail. Der Massenversand ueberspringt ihn — abbrechen
     * wuerde die restlichen 2500 Mails mitnehmen.
     */
    public function erreichbar(): bool
    {
        return trim($this->adresse) !== '';
    }
}
