<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;
use Peppermint\MassMailer\Models\MailDispatch as PaketProtokoll;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Peppermint\MassMailer\Support\Modelle;
use Symfony\Component\Mime\Email;

/**
 * Was Connect braucht: ein Protokollmodell mit eigenem Mandanten-Scope.
 *
 * Der Scope traegt beim Anlegen den Mandanten ein und filtert jede Abfrage
 * darauf. Genau das kann das Paket nicht mitbringen — es kennt Connects
 * Mandanten nicht.
 */
class MandantenScope implements Scope
{
    public static ?int $aktiv = null;

    public function apply(Builder $abfrage, $modell): void
    {
        if (self::$aktiv !== null) {
            $abfrage->where('mandant_id', self::$aktiv);
        }
    }
}

class EigenesProtokoll extends PaketProtokoll
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MandantenScope);

        static::creating(function (self $zeile): void {
            $zeile->mandant_id ??= MandantenScope::$aktiv;
        });
    }
}

beforeEach(function () {
    MandantenScope::$aktiv = null;
    config()->set('mass-mailer.modelle.protokoll', EigenesProtokoll::class);
});

afterEach(function () {
    MandantenScope::$aktiv = null;
});

it('legt die Zeile ueber die Klasse der Anwendung an', function () {
    MandantenScope::$aktiv = 7;

    app(Versandprotokoll::class)->beginnen(
        (new Email)->to('anna@beispiel.de')->subject('x')->html('<p>x</p>')
    );

    $eintrag = EigenesProtokoll::withoutGlobalScopes()->sole();

    expect($eintrag)->toBeInstanceOf(EigenesProtokoll::class)
        // Der Haken der Anwendung hat gegriffen — das Paket hat ihn nicht
        // umgangen, indem es seine eigene Klasse benutzt.
        ->and($eintrag->mandant_id)->toBe(7);
});

/**
 * Der Grund für `withoutGlobalScopes()` in `Modelle::protokollAbfrage()`:
 *
 * Das Protokoll entsteht im Queue-Worker, und dort ist kein Mandant aktiv. Ein
 * Mandanten-Scope würde dann alles wegfiltern — die Bestätigung fände ihre
 * eigene Zeile nicht wieder und das Protokoll bliebe für immer auf
 * „im Versand" stehen.
 */
it('findet die eigene Zeile wieder, auch wenn kein Mandant aktiv ist', function () {
    MandantenScope::$aktiv = 7;

    $nachricht = (new Email)->to('anna@beispiel.de')->subject('x')->html('<p>x</p>');
    $protokoll = app(Versandprotokoll::class);
    $protokoll->beginnen($nachricht);

    // Der Worker läuft ohne Sitzung — ab hier ist kein Mandant mehr gesetzt.
    MandantenScope::$aktiv = null;

    $protokoll->bestaetigen($nachricht);

    expect(EigenesProtokoll::withoutGlobalScopes()->sole()->status)
        ->toBe(PaketProtokoll::STATUS_VERSCHICKT);
});

/**
 * Und die Gegenprobe zum vorigen Test: Wäre der Mandant beim Bestätigen ein
 * ANDERER, dürfte die Abfrage trotzdem die richtige Zeile treffen — sonst
 * hinge das Protokoll daran, wer gerade angemeldet ist.
 */
it('findet die Zeile auch unter einem fremden Mandanten wieder', function () {
    MandantenScope::$aktiv = 7;

    $nachricht = (new Email)->to('anna@beispiel.de')->subject('x')->html('<p>x</p>');
    $protokoll = app(Versandprotokoll::class);
    $protokoll->beginnen($nachricht);

    MandantenScope::$aktiv = 99;
    $protokoll->bestaetigen($nachricht);

    expect(EigenesProtokoll::withoutGlobalScopes()->sole()->status)
        ->toBe(PaketProtokoll::STATUS_VERSCHICKT);
});

it('faellt ohne Eintrag auf die Paketklasse zurueck', function () {
    config()->set('mass-mailer.modelle.protokoll', null);

    expect(Modelle::protokoll())->toBe(PaketProtokoll::class);
});
