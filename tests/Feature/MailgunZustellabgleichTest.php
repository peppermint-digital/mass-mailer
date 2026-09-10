<?php

use Illuminate\Support\Facades\Http;
use Peppermint\MassMailer\Contracts\Sperrliste;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Services\MailgunZustellabgleich;
use Peppermint\MassMailer\Services\Versandprotokoll;

beforeEach(function () {
    config()->set('mass-mailer.mailgun.secret', 'key-test');
    config()->set('mass-mailer.mailgun.domain', 'mg.beispiel.de');
    config()->set('mass-mailer.mailgun.endpoint', 'api.eu.mailgun.net');
});

/**
 * @param  array<int, array<string, mixed>>  $ereignisse
 */
function mailgunAntwortet(array $ereignisse): void
{
    // Catch-All zuerst: Ein nicht gematchtes Muster liefe sonst ECHT hinaus.
    Http::fake([
        '*' => Http::response(['items' => $ereignisse, 'paging' => ['next' => null]]),
    ]);
}

function ereignis(string $art, int $dispatchId, array $extra = []): array
{
    return array_merge([
        'event' => $art,
        'recipient' => 'anna@beispiel.de',
        'timestamp' => now()->timestamp,
        'user-variables' => [
            app(Versandprotokoll::class)->mailgunSchluessel() => (string) $dispatchId,
        ],
    ], $extra);
}

it('traegt eine Zustellung in die Zeile ein', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([ereignis('delivered', $eintrag->id)]);

    $bilanz = app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($bilanz['zugeordnet'])->toBe(1)
        ->and($eintrag->refresh()->zustellung_status)->toBe(MailDispatch::ZUSTELLUNG_ZUGESTELLT)
        ->and($eintrag->zustellung_geprueft_am)->not->toBeNull();
});

/**
 * `failed` gibt es in zwei Schweregraden, und die Unterscheidung ist die
 * wichtigste im ganzen Thema: `temporary` heisst „morgen wieder", `permanent`
 * heisst „nie wieder".
 */
it('unterscheidet die beiden Schweregrade eines Fehlschlags', function (string $severity, string $erwartet) {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([ereignis('failed', $eintrag->id, ['severity' => $severity])]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($eintrag->refresh()->zustellung_status)->toBe($erwartet);
})->with([
    ['permanent', MailDispatch::ZUSTELLUNG_UNZUSTELLBAR],
    ['temporary', MailDispatch::ZUSTELLUNG_VERZOEGERT],
]);

/**
 * Der Kern des Graylisting-Falls: Nach „erst verzoegert, dann zugestellt" steht
 * in `zustellung_status` nur noch „zugestellt" — korrekt, aber die
 * Zwischenstufe waere weg. Der Anbieter zaehlt sie als Fehlschlag, und ohne
 * diesen Merker widersprechen sich die beiden Zahlen scheinbar.
 */
it('behaelt den Merker fuer einen zwischenzeitlichen Fehlschlag', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([
        ereignis('failed', $eintrag->id, ['severity' => 'temporary']),
        ereignis('delivered', $eintrag->id),
    ]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($eintrag->refresh()->zustellung_status)->toBe(MailDispatch::ZUSTELLUNG_ZUGESTELLT)
        ->and($eintrag->zustellung_wiederholt)->toBeTrue();
});

/**
 * Ein Merker, kein Zaehler: Der Abgleich laeuft ueber ueberlappende Fenster und
 * sieht dasselbe Ereignis mehrfach. Zweimal gesetzt muss dasselbe bleiben.
 */
it('bleibt bei einem zweiten Lauf ueber dieselben Ereignisse gleich', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([ereignis('failed', $eintrag->id, ['severity' => 'temporary'])]);

    $abgleich = app(MailgunZustellabgleich::class);
    $abgleich->fuerZeitraum(now()->subHour()->toImmutable());
    $ersterStand = $eintrag->refresh()->toArray();

    $abgleich->fuerZeitraum(now()->subHour()->toImmutable());
    $zweiterStand = $eintrag->refresh()->toArray();

    unset($ersterStand['zustellung_geprueft_am'], $zweiterStand['zustellung_geprueft_am']);
    unset($ersterStand['updated_at'], $zweiterStand['updated_at']);

    expect($zweiterStand)->toBe($ersterStand);
});

/**
 * Hier loesen sich die Zeilen auf, die auf `im_versand` haengengeblieben sind:
 * Der Job wurde abgebrochen, bevor er berichten konnte.
 */
it('zieht einen haengengebliebenen Status nach', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_IM_VERSAND,
    ]);

    mailgunAntwortet([ereignis('delivered', $eintrag->id)]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($eintrag->refresh()->status)->toBe(MailDispatch::STATUS_VERSCHICKT)
        ->and($eintrag->verschickt_am)->not->toBeNull();
});

/**
 * **Nur nach oben, nie zurueck.** Eine Zeile, die schon `fehlgeschlagen` ist,
 * bleibt es — der Versuch IST gescheitert, auch wenn ein spaeterer durchkam.
 * Wer das ueberschreibt, loescht die Spur des Vorfalls.
 */
it('macht einen bereits verbuchten Fehlschlag nicht rueckgaengig', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_FEHLGESCHLAGEN,
        'fehler' => 'Ursprünglicher Fehler',
    ]);

    mailgunAntwortet([ereignis('delivered', $eintrag->id)]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($eintrag->refresh()->status)->toBe(MailDispatch::STATUS_FEHLGESCHLAGEN)
        ->and($eintrag->fehler)->toBe('Ursprünglicher Fehler')
        // Was der Anbieter sagt, wird trotzdem festgehalten — nur eben daneben.
        ->and($eintrag->zustellung_status)->toBe(MailDispatch::ZUSTELLUNG_ZUGESTELLT);
});

it('meldet eine dauerhaft unzustellbare Adresse an die Sperrliste', function () {
    $liste = new class implements Sperrliste
    {
        /** @var array<int, string> */
        public array $gesperrt = [];

        public function sperren(string $adresse, string $grund, string $herkunft, ?MailDispatch $eintrag = null): bool
        {
            $this->gesperrt[] = $herkunft;

            return true;
        }
    };

    app()->instance(Sperrliste::class, $liste);
    app()->forgetInstance(MailgunZustellabgleich::class);

    $eintrag = MailDispatch::create([
        'empfaenger' => 'tot@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([ereignis('failed', $eintrag->id, ['severity' => 'permanent'])]);

    $bilanz = app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($liste->gesperrt)->toBe(['bounce'])
        ->and($bilanz['gesperrt'])->toBe(1);
});

/**
 * „abgewiesen" ist mehrdeutig: Mailgun weist auch ab, was auf der eigenen
 * Unterdrueckungsliste steht. Deshalb entscheidet der Wortlaut — im Zweifel
 * nicht sperren.
 */
it('sperrt bei einer Abweisung nur, wenn der Wortlaut dauerhaft ist', function (string $grund, bool $erwartet) {
    $liste = new class implements Sperrliste
    {
        public int $anzahl = 0;

        public function sperren(string $adresse, string $grund, string $herkunft, ?MailDispatch $eintrag = null): bool
        {
            $this->anzahl++;

            return true;
        }
    };

    app()->instance(Sperrliste::class, $liste);
    app()->forgetInstance(MailgunZustellabgleich::class);

    $eintrag = MailDispatch::create([
        'empfaenger' => 'x@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([ereignis('rejected', $eintrag->id, ['reason' => $grund])]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($liste->anzahl > 0)->toBe($erwartet);
})->with([
    ['550 user unknown', true],
    ['rate limit exceeded', false],
]);

/**
 * Ein Ereignis ohne Variable und ohne eindeutigen Treffer bleibt liegen. Bei
 * zwei gleichen Mails an dieselbe Person waere jede Wahl geraten — und ein
 * falsch zugeordneter Rueckläufer sperrt am Ende die Adresse eines
 * Unbeteiligten.
 */
it('ordnet nicht zu, wenn zwei Zeilen in Frage kommen', function () {
    MailDispatch::create(['empfaenger' => 'anna@beispiel.de', 'status' => MailDispatch::STATUS_VERSCHICKT]);
    MailDispatch::create(['empfaenger' => 'anna@beispiel.de', 'status' => MailDispatch::STATUS_VERSCHICKT]);

    mailgunAntwortet([[
        'event' => 'delivered',
        'recipient' => 'anna@beispiel.de',
        'timestamp' => now()->timestamp,
    ]]);

    $bilanz = app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($bilanz['ohne_zuordnung'])->toBe(1)
        ->and($bilanz['zugeordnet'])->toBe(0);
});

it('ordnet ueber die Adresse zu, solange nur eine Zeile in Frage kommt', function () {
    $eintrag = MailDispatch::create([
        'empfaenger' => 'anna@beispiel.de',
        'status' => MailDispatch::STATUS_VERSCHICKT,
    ]);

    mailgunAntwortet([[
        'event' => 'delivered',
        'recipient' => 'anna@beispiel.de',
        'timestamp' => now()->timestamp,
    ]]);

    app(MailgunZustellabgleich::class)->fuerZeitraum(now()->subHour()->toImmutable());

    expect($eintrag->refresh()->zustellung_status)->toBe(MailDispatch::ZUSTELLUNG_ZUGESTELLT);
});
