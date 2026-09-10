<?php

namespace Peppermint\MassMailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\MassMailer\Support\MassMailerSchema;

/**
 * Ein einzelner Sendeversuch.
 *
 * Die Protokollzeile sagt, was aus einer Mail geworden ist. Diese Tabelle sagt,
 * wie oft es dafuer gebraucht hat — und woran die Anlaeufe davor scheiterten.
 *
 * Ein Versuch ohne `ergebnis` ist nicht „unbekannt", sondern **abgebrochen**:
 * Der Worker wurde beendet, bevor er Erfolg oder Fehlschlag melden konnte. Das
 * ist ein eigener, aussagekraeftiger Zustand — und der haeufigste, wenn ein
 * Deploy in einen laufenden Versand faellt.
 */
class MailDispatchVersuch extends Model
{
    /** Der Postausgang hat die Mail angenommen. */
    public const ERGEBNIS_ERFOLG = 'erfolg';

    /** Er hat sie abgelehnt, mit Grund. */
    public const ERGEBNIS_FEHLSCHLAG = 'fehlschlag';

    protected $fillable = [
        'mail_dispatch_id',
        'nummer',
        'begonnen_am',
        'ergebnis',
        'beendet_am',
        'fehler',
    ];

    protected function casts(): array
    {
        return [
            'begonnen_am' => 'datetime',
            'beendet_am' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return MassMailerSchema::tabelle('versuche', MassMailerSchema::TABELLE_VERSUCHE);
    }

    /** @return BelongsTo<MailDispatch, $this> */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(MailDispatch::class, 'mail_dispatch_id');
    }

    /**
     * Laeuft dieser Versuch noch — oder wurde er abgebrochen?
     *
     * Die Frage ist von aussen nicht zu trennen, und das ist ehrlich so: Beide
     * sehen gleich aus, weil in beiden Faellen dieselbe Meldung fehlt.
     */
    public function istOffen(): bool
    {
        return $this->ergebnis === null;
    }
}
