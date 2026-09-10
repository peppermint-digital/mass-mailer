<?php

namespace Peppermint\MassMailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peppermint\MassMailer\Services\MailserverAufloeser;
use Peppermint\MassMailer\Support\MassMailerSchema;

/**
 * Ein eigener Postausgang — der einer Ebene aus der Kaskade.
 *
 * Das Modell kennt seinen Besitzer, aber nicht die Reihenfolge zwischen den
 * Stufen; die beantwortet {@see MailserverAufloeser}.
 *
 * ## Warum die Spalten hier `owner_*` heissen und nicht `besitzer_*`
 *
 * Anders als beim Protokoll gibt es hier nichts umzubenennen: `owner_type` /
 * `owner_id` ist bereits neutral, es ist Laravels eigene Konvention fuer
 * `morphTo()`, und keine Anwendung muesste dafuer eine Live-Tabelle anfassen.
 * Beim Protokoll war das anders — dort hiessen die Spalten `event_id` und
 * `registration_id` und trugen damit Connects Vokabular.
 *
 * Eine Umbenennung allein der Einheitlichkeit wegen waere genau die zweite
 * Aenderung, die man sich beim Umzug spart.
 *
 * @property string $host
 * @property int $port
 * @property string|null $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property bool $is_active
 */
class MailServer extends Model
{
    protected $fillable = [
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'is_active',
    ];

    /**
     * Zugangsdaten gehoeren in keine Inertia-Prop und in keine API-Antwort.
     * Die Masken zeigen nur, DASS ein Passwort hinterlegt ist.
     *
     * `$hidden` am Modell und nicht in der Ansicht: Es ist der Riegel, den man
     * nicht vergessen kann — eine neue Maske erbt ihn, eine neue Zeile in einer
     * Ansicht nicht.
     */
    protected $hidden = [
        'username',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'port' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return MassMailerSchema::tabelle('postausgaenge', MassMailerSchema::TABELLE_POSTAUSGAENGE);
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Die Zugangsdaten als Mailer-Konfiguration, wie Laravel sie in
     * `config/mail.php` erwartet.
     *
     * Bewusst hier und nicht im Aufloeser: es ist eine Eigenschaft dieser Zeile,
     * keine Frage der Reihenfolge — und so laesst sie sich ohne Datenbank und
     * ohne Mailversand pruefen.
     *
     * Achtung beim Lesen: Laravel wertet einen Schluessel `encryption` NICHT
     * mehr aus. Es leitet das Schema aus dem Port ab (465 → `smtps`, sonst
     * `smtp`). Eine Zeile `'encryption' => 'ssl'` waere hier also stille Zierde
     * — die Auswahl aus der Maske muss in `scheme` uebersetzt werden, sonst
     * haengt die Verschluesselung am Port statt an der Einstellung.
     *
     * @return array{transport: string, scheme: string, host: string, port: int, username: string|null, password: string|null, timeout: null}
     */
    public function laravelKonfiguration(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'timeout' => null,
        ];
    }

    /**
     * Der Absender, den dieser Postausgang setzt — oder `null`, wenn er keinen
     * eigenen mitbringt und der globale gelten soll.
     *
     * @return array{address: string, name: string}|null
     */
    public function absender(): ?array
    {
        if (blank($this->from_address)) {
            return null;
        }

        return [
            'address' => $this->from_address,
            'name' => $this->from_name ?: (string) config('mail.from.name'),
        ];
    }

    /** Ob die Zugangsdaten vollstaendig genug sind, um es zu versuchen. */
    public function istBenutzbar(): bool
    {
        return $this->is_active && filled($this->host);
    }
}
