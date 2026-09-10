<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\MassMailer\Contracts\Kaskade;
use Peppermint\MassMailer\Models\MailServer;
use Peppermint\MassMailer\Services\MailserverAufloeser;
use Peppermint\MassMailer\Services\Versandplan;
use Peppermint\MassMailer\Services\VersandtempoAufloeser;

/**
 * Zwei Stufen, wie in Connect: die Veranstaltung und ihre Organisation.
 * Beide tragen ein eigenes Tempo-Feld — genau wie dort.
 */
class Bereich extends Model
{
    protected $table = 'test_bereiche';

    protected $guarded = [];

    public $timestamps = false;
}

class Oberbereich extends Model
{
    protected $table = 'test_oberbereiche';

    protected $guarded = [];

    public $timestamps = false;
}

class ZweistufigeKaskade implements Kaskade
{
    public function ebenen(?Model $bereich): array
    {
        if (! $bereich instanceof Bereich) {
            return [];
        }

        $oben = $bereich->oberbereich_id === null
            ? null
            : Oberbereich::find($bereich->oberbereich_id);

        return array_values(array_filter([$bereich, $oben]));
    }
}

beforeEach(function () {
    Schema::create('test_oberbereiche', function (Blueprint $t) {
        $t->id();
        $t->unsignedInteger('mail_rate_per_hour')->nullable();
    });

    Schema::create('test_bereiche', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('oberbereich_id')->nullable();
        $t->unsignedInteger('mail_rate_per_hour')->nullable();
    });

    app()->bind(Kaskade::class, ZweistufigeKaskade::class);

    foreach ([MailserverAufloeser::class, VersandtempoAufloeser::class, Versandplan::class] as $dienst) {
        app()->forgetInstance($dienst);
    }
});

function bereichMit(?int $tempo = null, ?int $obenTempo = null): Bereich
{
    $oben = Oberbereich::create(['mail_rate_per_hour' => $obenTempo]);

    return Bereich::create([
        'oberbereich_id' => $oben->id,
        'mail_rate_per_hour' => $tempo,
    ]);
}

function postausgangFuer(Model $ebene, array $werte = []): MailServer
{
    $server = new MailServer(array_merge(['host' => 'smtp.beispiel.de', 'port' => 587], $werte));
    $server->owner_type = $ebene->getMorphClass();
    $server->owner_id = $ebene->getKey();
    $server->save();

    return $server;
}

// ---------------------------------------------------------------- Postausgang

it('nimmt den Postausgang der speziellsten Ebene', function () {
    $bereich = bereichMit();
    $oben = Oberbereich::find($bereich->oberbereich_id);

    postausgangFuer($oben, ['host' => 'oben.beispiel.de']);
    postausgangFuer($bereich, ['host' => 'unten.beispiel.de']);

    expect(app(MailserverAufloeser::class)->fuer($bereich)->host)->toBe('unten.beispiel.de');
});

it('faellt auf die naechste Ebene zurueck, wenn die erste keinen hat', function () {
    $bereich = bereichMit();
    $oben = Oberbereich::find($bereich->oberbereich_id);

    postausgangFuer($oben, ['host' => 'oben.beispiel.de']);

    expect(app(MailserverAufloeser::class)->fuer($bereich)->host)->toBe('oben.beispiel.de');
});

/**
 * Der Fall, für den `istBenutzbar()` da ist: Ein abgeschalteter Postausgang
 * darf den der nächsten Stufe NICHT verdecken. Sonst liefe der Versand
 * überraschend über die Weltkonfiguration — also gerade nicht über den Server,
 * den jemand extra hinterlegt hat.
 */
it('ueberspringt einen abgeschalteten Postausgang', function () {
    $bereich = bereichMit();
    $oben = Oberbereich::find($bereich->oberbereich_id);

    postausgangFuer($bereich, ['host' => 'unten.beispiel.de', 'is_active' => false]);
    postausgangFuer($oben, ['host' => 'oben.beispiel.de']);

    expect(app(MailserverAufloeser::class)->fuer($bereich)->host)->toBe('oben.beispiel.de');
});

it('ueberspringt einen Postausgang ohne Host', function () {
    $bereich = bereichMit();
    $oben = Oberbereich::find($bereich->oberbereich_id);

    postausgangFuer($bereich, ['host' => '']);
    postausgangFuer($oben, ['host' => 'oben.beispiel.de']);

    expect(app(MailserverAufloeser::class)->fuer($bereich)->host)->toBe('oben.beispiel.de');
});

it('liefert null, wenn keine Ebene einen Postausgang hat', function () {
    expect(app(MailserverAufloeser::class)->fuer(bereichMit()))->toBeNull()
        ->and(app(MailserverAufloeser::class)->fuer(null))->toBeNull();
});

it('haelt die Zugangsdaten aus jeder Ausgabe heraus', function () {
    $server = postausgangFuer(bereichMit(), ['username' => 'anna', 'password' => 'geheim']);

    expect($server->fresh()->toArray())
        ->not->toHaveKey('username')
        ->not->toHaveKey('password')
        // Verschluesselt abgelegt, im Klartext gelesen.
        ->and($server->fresh()->password)->toBe('geheim');
});

/**
 * Laravel wertet einen Schlüssel `encryption` nicht mehr aus — es leitet das
 * Schema sonst aus dem Port ab. Ohne diese Übersetzung hinge die
 * Verschlüsselung am Port statt an der Einstellung.
 */
it('uebersetzt die Verschluesselung in ein Schema', function (?string $encryption, string $schema) {
    $server = new MailServer(['host' => 'x', 'port' => 587, 'encryption' => $encryption]);

    expect($server->laravelKonfiguration()['scheme'])->toBe($schema);
})->with([
    ['ssl', 'smtps'],
    ['tls', 'smtp'],
    [null, 'smtp'],
]);

// ------------------------------------------------------------------- Tempo

it('nimmt das Tempo der speziellsten Ebene', function () {
    expect(app(VersandtempoAufloeser::class)->fuer(bereichMit(tempo: 20, obenTempo: 90)))->toBe(20);
});

it('faellt beim Tempo auf die naechste Ebene zurueck', function () {
    expect(app(VersandtempoAufloeser::class)->fuer(bereichMit(obenTempo: 90)))->toBe(90);
});

it('faellt auf die Weltkonfiguration zurueck, wenn keine Ebene antwortet', function () {
    config()->set('mass-mailer.tempo.pro_stunde', 45);

    expect(app(VersandtempoAufloeser::class)->fuer(bereichMit()))->toBe(45)
        ->and(app(VersandtempoAufloeser::class)->fuer(null))->toBe(45);
});

/**
 * Eine Rate von 0 hieße „nie versenden": Die Division bricht ab, und niemand
 * bekäme eine Fehlermeldung — nur einen Versand, der nicht losgeht.
 */
it('laesst eine unsinnige Rate nicht durch', function (int $wert) {
    expect(app(VersandtempoAufloeser::class)->fuer(bereichMit(tempo: $wert)))
        ->toBe(VersandtempoAufloeser::NOTNAGEL);
})->with([0, -5]);

/**
 * Gleichmäßig verteilt und nicht in Blöcken. Der frühere Block-Versatz schickte
 * das ganze Stundenkontingent in der ersten Sekunde hinaus — gegen ein
 * Stundenlimit genau das falsche Muster.
 */
it('verteilt gleichmaessig ueber die Stunde', function () {
    $tempo = app(VersandtempoAufloeser::class);

    expect($tempo->versatzInSekunden(0, 60))->toBe(0)
        ->and($tempo->versatzInSekunden(1, 60))->toBe(60)
        ->and($tempo->versatzInSekunden(59, 60))->toBe(3540)
        // 80/Stunde sind 45 Sekunden Abstand.
        ->and($tempo->versatzInSekunden(1, 80))->toBe(45);
});

// -------------------------------------------------------------- Versandplan

/**
 * Der eigentliche Zweck des Plans: Zwei Kampagnen kurz nacheinander dürfen sich
 * nicht überholen — sonst läge während der Überschneidung die doppelte Rate am
 * Postausgang, also genau die Menge, gegen die gedrosselt wird.
 */
it('setzt die zweite Kampagne hinter die erste', function () {
    $bereich = bereichMit(tempo: 60);
    $plan = app(Versandplan::class);

    $ersterStart = $plan->reservieren($bereich, 10);
    $zweiterStart = $plan->reservieren($bereich, 10);

    // 10 Mails bei 60/Stunde sind 10 Minuten.
    expect($zweiterStart->timestamp - $ersterStart->timestamp)->toBeGreaterThanOrEqual(595);
});

/**
 * Der Schlüssel hängt am POSTAUSGANG, nicht am Bereich: Das Limit gehört dem
 * Mailserver. Zwei Bereiche mit eigenen Servern stehen sich nicht im Weg.
 */
it('bremst zwei Bereiche mit eigenen Postausgaengen nicht gegenseitig', function () {
    $a = bereichMit(tempo: 60);
    $b = bereichMit(tempo: 60);
    postausgangFuer($a, ['host' => 'a.beispiel.de']);
    postausgangFuer($b, ['host' => 'b.beispiel.de']);

    $plan = app(Versandplan::class);

    $startA = $plan->reservieren($a, 100);
    $startB = $plan->reservieren($b, 10);

    expect($startB->timestamp - $startA->timestamp)->toBeLessThan(5);
});

/** Und die Gegenprobe: geteilter Server heisst geteiltes Kontingent. */
it('bremst zwei Bereiche mit demselben Postausgang gegenseitig', function () {
    $a = bereichMit(tempo: 60);
    $b = bereichMit(tempo: 60);
    $oben = Oberbereich::find($a->oberbereich_id);

    // Beide erben denselben Postausgang von derselben Oberebene.
    $a->update(['oberbereich_id' => $oben->id]);
    $b->update(['oberbereich_id' => $oben->id]);
    postausgangFuer($oben, ['host' => 'geteilt.beispiel.de']);

    $plan = app(Versandplan::class);

    $startA = $plan->reservieren($a, 10);
    $startB = $plan->reservieren($b, 10);

    expect($startB->timestamp - $startA->timestamp)->toBeGreaterThanOrEqual(595);
});

it('reserviert nichts fuer eine leere Kampagne', function () {
    $plan = app(Versandplan::class);

    $plan->reservieren(bereichMit(tempo: 60), 0);
    $start = $plan->reservieren(bereichMit(tempo: 60), 1);

    expect($start->timestamp - now()->timestamp)->toBeLessThan(5);
});
