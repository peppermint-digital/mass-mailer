<?php

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\Versandkampagne;
use Peppermint\MassMailer\Services\Massenversand;
use Peppermint\MassMailer\Support\Empfaenger;
use Peppermint\MassMailer\Support\Kampagnenentwurf;
use Peppermint\MassMailer\Support\Metadatenschluessel;

/** So sieht eine Kampagnenmail aus, die ihre Metadaten aus der Kampagne uebernimmt. */
class Rundmail extends Mailable
{
    public function __construct(
        private readonly Empfaenger $person,
        private readonly Versandkampagne $kampagne,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->kampagne->betreff,
            metadata: $this->kampagne->metadaten($this->person),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<html><body>'.$this->kampagne->rumpf.'</body></html>');
    }
}

function versenden(array $empfaenger, array $entwurf = []): Versandkampagne
{
    return app(Massenversand::class)->verschicken(
        new Kampagnenentwurf(...array_merge([
            'betreff' => 'Einladung',
            'rumpf' => '<p>Kommen Sie vorbei</p>',
        ], $entwurf)),
        $empfaenger,
        fn (Empfaenger $person, Versandkampagne $kampagne) => new Rundmail($person, $kampagne),
    );
}

it('legt die Kampagne an und reiht jede Mail ein', function () {
    Mail::fake();

    $kampagne = versenden([
        new Empfaenger('anna@beispiel.de'),
        new Empfaenger('bert@beispiel.de'),
    ]);

    expect($kampagne->empfaenger_anzahl)->toBe(2)
        ->and($kampagne->betreff)->toBe('Einladung')
        ->and($kampagne->gestartet_am)->not->toBeNull();

    Mail::assertQueuedCount(2);
});

/**
 * Eine leere Adresse ist kein Fehler, sondern ein Alltagsfall: ein Kontakt ohne
 * hinterlegte Mail. Abbrechen würde die übrigen Mails mitnehmen.
 */
it('ueberspringt Empfaenger ohne Adresse und zaehlt sie nicht mit', function () {
    Mail::fake();

    $kampagne = versenden([
        new Empfaenger('anna@beispiel.de'),
        new Empfaenger(''),
        new Empfaenger('   '),
        new Empfaenger('bert@beispiel.de'),
    ]);

    expect($kampagne->empfaenger_anzahl)->toBe(2);

    Mail::assertQueuedCount(2);
});

/**
 * Der Grund, warum die Kampagnenzeile VOR dem Einreihen entsteht: Jede einzelne
 * Mail muss ihre Nummer mitnehmen, sonst lässt sich im Protokoll nicht sagen,
 * zu welchem Versand eine Zeile gehört.
 */
it('traegt die Kampagnennummer in jede Protokollzeile', function () {
    $kampagne = versenden([
        new Empfaenger('anna@beispiel.de'),
        new Empfaenger('bert@beispiel.de'),
    ]);

    // Der array-Transport verschickt sofort, das Protokoll schreibt mit.
    expect(MailDispatch::count())->toBe(2)
        ->and(MailDispatch::where('kampagne_id', $kampagne->id)->count())->toBe(2)
        ->and(MailDispatch::pluck('art')->unique()->all())->toBe([MailDispatch::ART_MASSE]);
});

it('traegt den Bezug jedes Empfaengers mit', function () {
    $bezug = Versandkampagne::create(['betreff' => 'x', 'rumpf' => 'y']);

    versenden([new Empfaenger('anna@beispiel.de', bezug: $bezug)]);

    $eintrag = MailDispatch::sole();

    expect($eintrag->bezug_typ)->toBe($bezug->getMorphClass())
        ->and($eintrag->bezug_id)->toBe($bezug->id);
});

/**
 * Betreff und Rumpf sind eine Kopie. Wer die Vorlage danach ändert, ändert
 * damit nicht rückwirkend, was verschickt wurde.
 */
it('haelt fest, was verschickt wurde — nicht was in der Vorlage steht', function () {
    Mail::fake();

    $kampagne = versenden(
        [new Empfaenger('anna@beispiel.de')],
        ['betreff' => 'Stand vom Dienstag', 'rumpf' => '<p>alter Text</p>', 'vorlageId' => 7],
    );

    expect($kampagne->fresh()->betreff)->toBe('Stand vom Dienstag')
        ->and($kampagne->fresh()->rumpf)->toBe('<p>alter Text</p>')
        ->and($kampagne->fresh()->vorlage_id)->toBe(7);
});

it('merkt sich, wie die Empfaenger ausgewaehlt wurden', function () {
    Mail::fake();

    $kampagne = versenden(
        [new Empfaenger('anna@beispiel.de')],
        ['filter' => ['typ' => 'status', 'wert' => 'invited']],
    );

    expect($kampagne->fresh()->filter)->toBe(['typ' => 'status', 'wert' => 'invited']);
});

/**
 * Die Verzoegerungen, wie sie tatsaechlich in der Warteschlange stehen.
 *
 * Ueber die echte `jobs`-Tabelle und nicht ueber `Mail::fake()`: Die Attrappe
 * leitet `later()` auf `queue()` um und verwirft die Verzoegerung dabei. Ein
 * Test dagegen waere gruen, auch wenn die Staffelung gar nicht stattfaende.
 *
 * @return array<int, int>
 */
function abstaendeInSekunden(): array
{
    $zeitpunkte = DB::table('jobs')->orderBy('id')->pluck('available_at')->all();

    $abstaende = [];
    for ($i = 1; $i < count($zeitpunkte); $i++) {
        $abstaende[] = (int) $zeitpunkte[$i] - (int) $zeitpunkte[$i - 1];
    }

    return $abstaende;
}

/**
 * Die Staffelung ist der Sinn der Übung: 500 Empfänger auf einen Schlag heißt,
 * dass der Worker sie so schnell hinausschickt, wie er kann.
 */
it('staffelt die Mails ueber die Zeit statt alle auf einmal', function () {
    config()->set('queue.default', 'database');
    config()->set('mass-mailer.tempo.pro_stunde', 60);

    versenden([
        new Empfaenger('a@beispiel.de'),
        new Empfaenger('b@beispiel.de'),
        new Empfaenger('c@beispiel.de'),
    ]);

    expect(DB::table('jobs')->count())->toBe(3)
        // Bei 60/Stunde liegt zwischen zwei Mails eine Minute.
        ->and(abstaendeInSekunden())->toBe([60, 60]);
});

it('staffelt enger, wenn die Stunde mehr hergibt', function () {
    config()->set('queue.default', 'database');
    config()->set('mass-mailer.tempo.pro_stunde', 80);

    versenden([
        new Empfaenger('a@beispiel.de'),
        new Empfaenger('b@beispiel.de'),
    ]);

    // 80/Stunde sind 45 Sekunden Abstand.
    expect(abstaendeInSekunden())->toBe([45]);
});

/**
 * `array_values` in `erreichbare()` ist keine Kosmetik: Bliebe eine Lücke,
 * entstünde an der Stelle des übersprungenen Empfängers eine doppelte Pause.
 */
it('laesst durch einen uebersprungenen Empfaenger keine Luecke im Takt', function () {
    config()->set('queue.default', 'database');
    config()->set('mass-mailer.tempo.pro_stunde', 60);

    versenden([
        new Empfaenger('a@beispiel.de'),
        new Empfaenger(''),
        new Empfaenger('b@beispiel.de'),
    ]);

    expect(abstaendeInSekunden())->toBe([60]);
});

it('kommt mit einer Kampagne ohne einen einzigen Empfaenger zurecht', function () {
    Mail::fake();

    $kampagne = versenden([]);

    expect($kampagne->empfaenger_anzahl)->toBe(0);

    Mail::assertNothingQueued();
});

it('baut die Metadaten so, dass das Protokoll sie versteht', function () {
    $kampagne = Versandkampagne::create([
        'betreff' => 'x',
        'rumpf' => 'y',
        'mandant_id' => 7,
        'bereich_typ' => 'App\\Models\\Event',
        'bereich_id' => 3,
        'ausgeloest_von_id' => 9,
    ]);

    $metadaten = $kampagne->metadaten();

    expect($metadaten[Metadatenschluessel::ART])->toBe(MailDispatch::ART_MASSE)
        ->and($metadaten[Metadatenschluessel::KAMPAGNE])->toBe((string) $kampagne->id)
        ->and($metadaten[Metadatenschluessel::MANDANT])->toBe('7')
        ->and($metadaten[Metadatenschluessel::BEREICH])->toBe('3')
        ->and($metadaten[Metadatenschluessel::AUSGELOEST_VON])->toBe('9')
        // Ohne Empfaenger kein Bezug — und der Schluessel darf dann gar nicht
        // erst auftauchen, sonst schreibt das Protokoll einen leeren String.
        ->and($metadaten)->not->toHaveKey(Metadatenschluessel::BEZUG);
});

it('laesst leere Angaben aus den Metadaten weg', function () {
    $kampagne = Versandkampagne::create(['betreff' => 'x', 'rumpf' => 'y']);

    expect($kampagne->metadaten())
        ->not->toHaveKey(Metadatenschluessel::MANDANT)
        ->not->toHaveKey(Metadatenschluessel::BEREICH)
        ->not->toHaveKey(Metadatenschluessel::AUSGELOEST_VON);
});

it('baut einen Empfaenger aus einer Abfragezeile und trennt Adresse von Platzhaltern', function () {
    $person = Empfaenger::aus([
        'email' => 'anna@beispiel.de',
        'name' => 'Anna Beispiel',
        'first_name' => 'Anna',
    ]);

    expect($person->adresse)->toBe('anna@beispiel.de')
        // Die Adresse gehoert NICHT in die Platzhalter — sonst landet sie als
        // technischer Wert in der Vorlage.
        ->and($person->platzhalter)->toBe(['name' => 'Anna Beispiel', 'first_name' => 'Anna']);
});
