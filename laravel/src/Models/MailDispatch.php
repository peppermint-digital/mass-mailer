<?php

namespace Peppermint\MassMailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peppermint\MassMailer\Support\MassMailerSchema;
use Peppermint\MassMailer\Support\Modelle;

/**
 * Eine verschickte Mail — der Nachweis, was an wen rausgegangen ist.
 *
 * Drei Zustaende, und der mittlere ist der ehrlichste Teil daran:
 *
 * - `im_versand` — die Mail ist gebaut und an den Postausgang uebergeben,
 *   eine Bestaetigung steht noch aus
 * - `verschickt` — der Postausgang hat sie ANGENOMMEN
 * - `fehlgeschlagen` — er hat sie abgelehnt, mit Grund
 *
 * `verschickt` heisst ausdruecklich nicht „zugestellt" und erst recht nicht
 * „gelesen". Ein Protokoll, das mehr behauptet, als es wissen kann, ist
 * schlimmer als keines: es beendet die Suche an der falschen Stelle.
 *
 * ## Die zweite Frage: was der Anbieter sagt
 *
 * Daneben steht `zustellung_status` — das, was der Versanddienst ueber
 * dieselbe Mail berichtet. Bewusst als eigene Spalte und nicht in `status`
 * hineingerechnet: Die beiden Angaben haben verschiedene Quellen und
 * verschiedene Zuverlaessigkeiten, und die zweite gibt es nur, wo ein Konto
 * verbunden ist.
 *
 * `null` heisst deshalb „nicht gemessen", nicht „nicht angekommen".
 */
class MailDispatch extends Model
{
    public const STATUS_IM_VERSAND = 'im_versand';

    public const STATUS_VERSCHICKT = 'verschickt';

    public const STATUS_FEHLGESCHLAGEN = 'fehlgeschlagen';

    /** Der Anbieter hat die Mail beim Empfaenger abgeliefert. */
    public const ZUSTELLUNG_ZUGESTELLT = 'zugestellt';

    /**
     * Voruebergehend gescheitert — der Anbieter versucht es weiter.
     *
     * Kein Grund, eine Adresse zu sperren: ein volles Postfach oder ein Server
     * in Wartung sind morgen wieder erreichbar.
     */
    public const ZUSTELLUNG_VERZOEGERT = 'verzoegert';

    /** Dauerhaft gescheitert — die Adresse gibt es nicht (mehr). */
    public const ZUSTELLUNG_UNZUSTELLBAR = 'unzustellbar';

    /** Der Anbieter hat die Mail gar nicht erst angenommen. */
    public const ZUSTELLUNG_ABGEWIESEN = 'abgewiesen';

    /** Der Empfaenger hat sie als Werbung gemeldet. */
    public const ZUSTELLUNG_BESCHWERDE = 'beschwerde';

    /** Massenversand — gesetzt vom Kampagnenversand des Pakets. */
    public const ART_MASSE = 'bulk';

    /** Dieselbe Mail, nur an eine Person. */
    public const ART_EINZELN = 'einzel';

    protected $fillable = [
        'mandant_id',
        'bereich_typ',
        'bereich_id',
        'bezug_typ',
        'bezug_id',
        'kampagne_id',
        'ausgeloest_von_id',
        'empfaenger',
        'art',
        'betreff',
        'status',
        'versuche',
        'fehler',
        'verschickt_am',
        'fehlgeschlagen_am',
        'zustellung_status',
        'zustellung_am',
        'zustellung_grund',
        'zustellung_wiederholt',
        'zustellung_geprueft_am',
        'anbieter_message_id',
    ];

    protected function casts(): array
    {
        return [
            'verschickt_am' => 'datetime',
            'fehlgeschlagen_am' => 'datetime',
            'geoeffnet_am' => 'datetime',
            'geklickt_am' => 'datetime',
            'zustellung_am' => 'datetime',
            'zustellung_geprueft_am' => 'datetime',
            'zustellung_wiederholt' => 'boolean',
        ];
    }

    /**
     * Der Tabellenname kommt aus der Konfiguration.
     *
     * Nicht aus Spielerei: Eine Anwendung, die das Paket nachtraeglich
     * einzieht, hat ihre Protokolltabelle womoeglich schon anders genannt. Ein
     * fest verdrahteter Name zwaenge sie zu einer Umbenennung in Produktion —
     * fuer nichts.
     */
    public function getTable(): string
    {
        return MassMailerSchema::tabelle('protokoll', MassMailerSchema::TABELLE_PROTOKOLL);
    }

    /**
     * Worin die Mail steht — in Connect die Veranstaltung.
     *
     * @return MorphTo<Model, $this>
     */
    public function bereich(): MorphTo
    {
        return $this->morphTo('bereich', 'bereich_typ', 'bereich_id');
    }

    /**
     * Um wen es geht — in Connect die Anmeldung, im CRM der Lead.
     *
     * @return MorphTo<Model, $this>
     */
    public function bezug(): MorphTo
    {
        return $this->morphTo('bezug', 'bezug_typ', 'bezug_id');
    }

    /**
     * Die einzelnen Sendeversuche.
     *
     * Aufsteigend, weil man sie als Verlauf liest: „erst gescheitert, dann
     * durchgekommen" ergibt nur in dieser Richtung einen Satz.
     *
     * **Nicht `versuche()`.** So hiess sie in Connect zuerst — und war damit
     * unerreichbar: Eloquent loest bei Namensgleichheit das ATTRIBUT auf, nie
     * die Beziehung. `$eintrag->versuche` lieferte also die Zahl aus der
     * Spalte, und ein `toHaveCount()` darauf scheiterte mit einer Meldung, die
     * nichts mit der Ursache zu tun hatte. Eine Spalte und eine Beziehung
     * duerfen nicht gleich heissen.
     *
     * @return HasMany<MailDispatchVersuch, $this>
     */
    public function sendeversuche(): HasMany
    {
        return $this->hasMany(Modelle::versuch(), 'mail_dispatch_id')
            ->orderBy('nummer');
    }

    /**
     * Hat diese Mail mehr als einen Anlauf gebraucht?
     *
     * Die Frage, die in einer Liste zaehlt — beantwortet aus der Spalte und
     * nicht ueber die Versuchstabelle, damit 2500 Zeilen keine 2500 Abfragen
     * ausloesen.
     */
    public function brauchteWiederholung(): bool
    {
        return (int) $this->versuche > 1;
    }

    /**
     * Die Mail-Art in Worten.
     *
     * Der Katalog steht in der Konfiguration der Anwendung — das Paket kennt
     * die Mail-Arten seines Hosts nicht. Faellt auf den rohen Schluessel
     * zurueck, wenn die Art nicht (mehr) im Katalog steht: eine alte Zeile soll
     * lesbar bleiben, auch wenn die Mail-Art inzwischen umbenannt oder entfernt
     * wurde.
     */
    public function artInWorten(): string
    {
        if ($this->art === self::ART_MASSE) {
            return 'Massenversand';
        }

        if ($this->art === self::ART_EINZELN) {
            return 'Einzelversand';
        }

        /** @var array<string, string> $katalog */
        $katalog = config('mass-mailer.arten', []);

        return $katalog[(string) $this->art]
            ?? (string) ($this->art ?? 'Unbekannt');
    }

    /**
     * Was der Anbieter ueber diese Mail sagt — in Worten.
     *
     * `null`, solange niemand gefragt hat. Die Unterscheidung zu „nicht
     * zugestellt" ist der ganze Sinn der Spalte: Ohne verbundenes Konto gibt es
     * die Auskunft nicht, und eine leere Zelle darf dann nicht wie ein
     * Fehlschlag aussehen.
     */
    public function zustellungInWorten(): ?string
    {
        return match ($this->zustellung_status) {
            self::ZUSTELLUNG_ZUGESTELLT => 'Zugestellt',
            self::ZUSTELLUNG_VERZOEGERT => 'Verzögert',
            self::ZUSTELLUNG_UNZUSTELLBAR => 'Unzustellbar',
            self::ZUSTELLUNG_ABGEWIESEN => 'Abgewiesen',
            self::ZUSTELLUNG_BESCHWERDE => 'Als Werbung gemeldet',
            default => null,
        };
    }
}
