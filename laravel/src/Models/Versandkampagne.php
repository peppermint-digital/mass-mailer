<?php

namespace Peppermint\MassMailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peppermint\MassMailer\Support\Empfaenger;
use Peppermint\MassMailer\Support\MassMailerSchema;
use Peppermint\MassMailer\Support\Metadatenschluessel;

/**
 * Ein Massenversand — was an wen rausging, und was tatsaechlich drinstand.
 *
 * `betreff` und `rumpf` sind eine **Kopie** zum Zeitpunkt des Versands, auch
 * wenn sie aus einer Vorlage stammen. Wer die Vorlage danach aendert, aendert
 * damit nicht rueckwirkend, was verschickt wurde. Eine Kampagne, die auf die
 * Vorlage zeigt, beantwortet die Frage „was habe ich denen geschickt?" beim
 * zweiten Nachsehen falsch.
 *
 * Die einzelnen Mails haengen ueber `kampagne_id` am {@see MailDispatch} — dort
 * steht, was aus jeder einzelnen geworden ist.
 */
class Versandkampagne extends Model
{
    protected $fillable = [
        'mandant_id',
        'bereich_typ',
        'bereich_id',
        'vorlage_id',
        'ausgeloest_von_id',
        'betreff',
        'rumpf',
        'filter',
        'empfaenger_anzahl',
        'gestartet_am',
    ];

    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'gestartet_am' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('mass-mailer.tabellen.kampagnen', MassMailerSchema::TABELLE_KAMPAGNEN);
    }

    /** @return MorphTo<Model, $this> */
    public function bereich(): MorphTo
    {
        return $this->morphTo('bereich', 'bereich_typ', 'bereich_id');
    }

    /**
     * Die einzelnen Mails dieser Kampagne — das Protokoll je Empfaenger.
     *
     * @return HasMany<MailDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(MailDispatch::class, 'kampagne_id');
    }

    /**
     * Die Metadaten, die eine Mail dieser Kampagne mitfuehren muss.
     *
     * Hier und nicht beim Aufrufer, weil dieser Code in jeder Anwendung gleich
     * aussehen wuerde — und weil er leise falsch wird, wenn ihn jemand von Hand
     * schreibt: Eine vergessene Kampagnennummer loest keinen Fehler aus, sie
     * loest nur eine Auswertung auf, die spaeter jemand vermisst.
     *
     * Der Aufrufer merged das Ergebnis in seinen `Envelope`:
     *
     * ```php
     * metadata: $kampagne->metadaten($empfaenger) + ['eigenes' => '…']
     * ```
     *
     * @return array<string, string>
     */
    public function metadaten(?Empfaenger $empfaenger = null): array
    {
        $werte = array_filter([
            Metadatenschluessel::ART => MailDispatch::ART_MASSE,
            Metadatenschluessel::KAMPAGNE => (string) $this->id,
            Metadatenschluessel::MANDANT => $this->zeichen($this->mandant_id),
            Metadatenschluessel::BEREICH_TYP => $this->bereich_typ,
            Metadatenschluessel::BEREICH => $this->zeichen($this->bereich_id),
            Metadatenschluessel::AUSGELOEST_VON => $this->zeichen($this->ausgeloest_von_id),
        ], static fn (?string $wert): bool => $wert !== null);

        if ($empfaenger?->bezug !== null) {
            $werte[Metadatenschluessel::BEZUG_TYP] = $empfaenger->bezug->getMorphClass();
            $werte[Metadatenschluessel::BEZUG] = (string) $empfaenger->bezug->getKey();
        }

        return $werte;
    }

    private function zeichen(mixed $wert): ?string
    {
        return $wert === null ? null : (string) $wert;
    }
}
