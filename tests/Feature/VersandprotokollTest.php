<?php

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Peppermint\MassMailer\Contracts\Sperrliste;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\MailDispatchVersuch;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Peppermint\MassMailer\Support\Metadatenschluessel;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;

/** Eine Mail, die sich zu erkennen gibt — so wie es die Anwendungen tun sollen. */
class Testmail extends Mailable
{
    /** @param  array<string, string>  $metadaten */
    public function __construct(private readonly array $metadaten = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ein Betreff', metadata: $this->metadaten);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<html><body><p>Hallo</p></body></html>');
    }
}

/** Merkt sich, was gesperrt wurde — statt es irgendwohin zu schreiben. */
class MerkendeSperrliste implements Sperrliste
{
    /** @var array<int, array{adresse: string, grund: string, herkunft: string}> */
    public array $gesperrt = [];

    public function sperren(string $adresse, string $grund, string $herkunft, ?MailDispatch $eintrag = null): bool
    {
        $this->gesperrt[] = compact('adresse', 'grund', 'herkunft');

        return true;
    }
}

it('schreibt eine Zeile, sobald eine Mail an den Postausgang geht', function () {
    Mail::to('anna@beispiel.de')->send(new Testmail([
        Metadatenschluessel::ART => 'anmeldebestaetigung',
        Metadatenschluessel::MANDANT => '7',
        Metadatenschluessel::BEREICH_TYP => 'App\\Models\\Event',
        Metadatenschluessel::BEREICH => '3',
        Metadatenschluessel::BEZUG_TYP => 'App\\Models\\Registration',
        Metadatenschluessel::BEZUG => '42',
    ]));

    $eintrag = MailDispatch::sole();

    expect($eintrag->empfaenger)->toBe('anna@beispiel.de')
        ->and($eintrag->betreff)->toBe('Ein Betreff')
        ->and($eintrag->art)->toBe('anmeldebestaetigung')
        ->and($eintrag->mandant_id)->toBe(7)
        ->and($eintrag->bereich_id)->toBe(3)
        ->and($eintrag->bezug_typ)->toBe('App\\Models\\Registration')
        ->and($eintrag->bezug_id)->toBe(42)
        // Der Array-Transport nimmt die Mail an, also steht sie auf
        // `verschickt` — und das heisst ausdruecklich nicht „zugestellt".
        ->and($eintrag->status)->toBe(MailDispatch::STATUS_VERSCHICKT)
        ->and($eintrag->zustellung_status)->toBeNull();
});

/**
 * Der Fall, den das Paket ohne Metadaten trotzdem koennen muss. Eine magere
 * Zeile ist besser als eine fehlende — sonst faellt jede Mail aus einem
 * fremden Paket lautlos aus dem Protokoll.
 */
it('schreibt auch fuer eine Mail ohne jede Angabe eine Zeile', function () {
    Mail::to('unbekannt@beispiel.de')->send(new Testmail);

    $eintrag = MailDispatch::sole();

    expect($eintrag->empfaenger)->toBe('unbekannt@beispiel.de')
        ->and($eintrag->art)->toBeNull()
        ->and($eintrag->bezug_id)->toBeNull();
});

it('zaehlt jeden Anlauf einzeln mit', function () {
    Mail::to('anna@beispiel.de')->send(new Testmail([Metadatenschluessel::ART => 'test']));

    $eintrag = MailDispatch::sole();

    expect($eintrag->versuche)->toBe(1)
        ->and($eintrag->sendeversuche)->toHaveCount(1)
        ->and($eintrag->sendeversuche->first()->ergebnis)->toBe(MailDispatchVersuch::ERGEBNIS_ERFOLG)
        ->and($eintrag->brauchteWiederholung())->toBeFalse();
});

/**
 * Der Kern von Bug #689 aus Connect: Das Ereignis feuert bei JEDEM Versuch.
 * Ohne diese Regel blieben nach einem dreimal gescheiterten Job drei Zeilen
 * stehen — zwei davon fuer immer auf `im_versand`.
 */
it('gibt einem zweiten Anlauf keine zweite Zeile, sondern einen zweiten Versuch', function () {
    $protokoll = app(Versandprotokoll::class);

    $nachricht = fn () => (new Email)
        ->to('anna@beispiel.de')
        ->subject('Einladung')
        ->html('<p>x</p>');

    $protokoll->beginnen($nachricht());
    $protokoll->beginnen($nachricht());

    $eintrag = MailDispatch::sole();

    expect($eintrag->versuche)->toBe(2)
        ->and($eintrag->sendeversuche)->toHaveCount(2)
        ->and($eintrag->brauchteWiederholung())->toBeTrue();
});

/**
 * Zwei verschiedene Mails an dieselbe Adresse duerfen sich NICHT eine Zeile
 * teilen — sonst verschwindet die erste aus dem Protokoll.
 */
it('haelt zwei verschiedene Mail-Arten an dieselbe Adresse auseinander', function () {
    $protokoll = app(Versandprotokoll::class);

    foreach (['einladung', 'bestaetigung'] as $art) {
        $nachricht = (new Email)
            ->to('anna@beispiel.de')
            ->subject($art)
            ->html('<p>x</p>');
        $nachricht->getHeaders()->add(new MetadataHeader('art', $art));

        $protokoll->beginnen($nachricht);
    }

    expect(MailDispatch::count())->toBe(2);
});

it('haelt zwei Zeilen ohne Bezug auseinander, wenn die Kampagne verschieden ist', function () {
    $protokoll = app(Versandprotokoll::class);

    foreach (['1', '2'] as $kampagne) {
        $nachricht = (new Email)
            ->to('anna@beispiel.de')
            ->subject('Rundmail')
            ->html('<p>x</p>');
        $nachricht->getHeaders()->add(new MetadataHeader('kampagne', $kampagne));

        $protokoll->beginnen($nachricht);
    }

    expect(MailDispatch::count())->toBe(2);
});

it('haelt den Fehlschlag samt Grund fest', function () {
    $protokoll = app(Versandprotokoll::class);

    $protokoll->scheitern(null, 'tot@beispiel.de', new RuntimeException('550 5.1.1 user unknown'));

    $eintrag = MailDispatch::sole();

    expect($eintrag->status)->toBe(MailDispatch::STATUS_FEHLGESCHLAGEN)
        ->and($eintrag->fehler)->toContain('user unknown')
        ->and($eintrag->fehlgeschlagen_am)->not->toBeNull();
});

/**
 * Der Fall, der in Connect am 17.08.2026 unsichtbar blieb: Scheitert die Mail,
 * bevor sie gebaut ist, entsteht die Zeile erst im Fehlerpfad — und muss
 * dieselben Bezuege tragen wie eine im Regelfall entstandene.
 */
it('gibt einer erst im Fehlerpfad entstandenen Zeile denselben Kontext', function () {
    app(Versandprotokoll::class)->scheitern(
        null,
        'tot@beispiel.de',
        new RuntimeException('Ansicht fehlt'),
        [
            Metadatenschluessel::ART => 'einladung',
            Metadatenschluessel::BEREICH => '3',
            Metadatenschluessel::KAMPAGNE => '9',
        ],
        'Einladung',
    );

    $eintrag = MailDispatch::sole();

    expect($eintrag->art)->toBe('einladung')
        ->and($eintrag->bereich_id)->toBe(3)
        ->and($eintrag->kampagne_id)->toBe(9)
        ->and($eintrag->betreff)->toBe('Einladung');
});

it('meldet eine tote Adresse an die Sperrliste', function () {
    $liste = new MerkendeSperrliste;
    app()->instance(Sperrliste::class, $liste);
    app()->forgetInstance(Versandprotokoll::class);

    app(Versandprotokoll::class)->scheitern(null, 'tot@beispiel.de', new RuntimeException('550 user unknown'));

    expect($liste->gesperrt)->toHaveCount(1)
        ->and($liste->gesperrt[0]['adresse'])->toBe('tot@beispiel.de')
        ->and($liste->gesperrt[0]['herkunft'])->toBe('versandfehler');
});

/**
 * Die Gegenprobe, und die zaehlt mehr als die Probe: Wer bei einem vollen
 * Postfach sperrt, nimmt einem Empfaenger dauerhaft die Post.
 */
it('meldet einen voruebergehenden Fehler NICHT an die Sperrliste', function () {
    $liste = new MerkendeSperrliste;
    app()->instance(Sperrliste::class, $liste);
    app()->forgetInstance(Versandprotokoll::class);

    app(Versandprotokoll::class)->scheitern(null, 'voll@beispiel.de', new RuntimeException('452 mailbox full'));

    expect($liste->gesperrt)->toBeEmpty()
        ->and(MailDispatch::sole()->status)->toBe(MailDispatch::STATUS_FEHLGESCHLAGEN);
});

it('kommt ohne gebundene Sperrliste zurecht', function () {
    app(Versandprotokoll::class)->scheitern(null, 'tot@beispiel.de', new RuntimeException('550 user unknown'));

    expect(MailDispatch::sole()->status)->toBe(MailDispatch::STATUS_FEHLGESCHLAGEN);
});
