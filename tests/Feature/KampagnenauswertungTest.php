<?php

use Illuminate\Support\Carbon;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\MailDispatchVersuch;
use Peppermint\MassMailer\Models\Versandkampagne;
use Peppermint\MassMailer\Services\Kampagnenauswertung;

function kampagne(array $werte = []): Versandkampagne
{
    return Versandkampagne::create(array_merge([
        'betreff' => 'Einladung',
        'rumpf' => '<p>Hallo</p>',
        'empfaenger_anzahl' => 0,
        'gestartet_am' => Carbon::parse('2026-09-10 08:00:00'),
    ], $werte));
}

function zeile(Versandkampagne $kampagne, array $werte = []): MailDispatch
{
    return MailDispatch::create(array_merge([
        'kampagne_id' => $kampagne->id,
        'empfaenger' => fake()->safeEmail(),
        'art' => MailDispatch::ART_MASSE,
        'status' => MailDispatch::STATUS_VERSCHICKT,
        'verschickt_am' => Carbon::parse('2026-09-10 09:00:00'),
        'versuche' => 1,
    ], $werte));
}

it('rechnet die Zustellquote gegen die verschickten, nicht gegen die eingereihten', function () {
    // Was noch in der Warteschlange liegt, ist weder gelungen noch
    // gescheitert — es duerfte die Quote nicht druecken.
    $kampagne = kampagne(['empfaenger_anzahl' => 10]);

    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT]);
    zeile($kampagne, ['status' => MailDispatch::STATUS_IM_VERSAND, 'verschickt_am' => null]);
    zeile($kampagne, ['status' => MailDispatch::STATUS_IM_VERSAND, 'verschickt_am' => null]);

    $bilanz = app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung'];

    expect($bilanz['verschickt'])->toBe(2)
        ->and($bilanz['zustellquote'])->toBe(100.0);
});

it('nennt keine Quote, solange nichts verschickt ist', function () {
    // `null` und nicht `0` — „0 %" waere eine Behauptung ueber einen Versand,
    // der noch gar nicht stattgefunden hat.
    $kampagne = kampagne();
    zeile($kampagne, ['status' => MailDispatch::STATUS_IM_VERSAND, 'verschickt_am' => null]);

    expect(app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung']['zustellquote'])->toBeNull();
});

/**
 * Der Fehler, der einen gesunden Versand kaputt aussehen laesst: Am 04.09.2026
 * meldete Mailgun 114 Fehlschlaege, von denen 96 blosses Graylisting waren.
 */
it('haelt dauerhafte und voruebergehende Rueckläufer auseinander', function () {
    $kampagne = kampagne();

    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_UNZUSTELLBAR]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_ABGEWIESEN]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_BESCHWERDE]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_VERZOEGERT]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_VERZOEGERT]);

    $bilanz = app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung'];

    expect($bilanz['ruecklaeufer_dauerhaft'])->toBe(3)
        ->and($bilanz['ruecklaeufer_voruebergehend'])->toBe(2);
});

it('zaehlt die Empfaenger, die erst im zweiten Anlauf angenommen haben', function () {
    $kampagne = kampagne();

    zeile($kampagne, [
        'zustellung_wiederholt' => true,
        'zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT,
    ]);
    zeile($kampagne, [
        'zustellung_wiederholt' => true,
        'zustellung_status' => MailDispatch::ZUSTELLUNG_UNZUSTELLBAR,
    ]);
    zeile($kampagne, ['zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT]);

    $bilanz = app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung'];

    expect($bilanz['graylisting'])->toBe(2)
        ->and($bilanz['graylisting_zugestellt'])->toBe(1);
});

/**
 * Der Anbieter quittiert auch den Erfolg im Klartext. Stuenden diese Zeilen
 * mit in der Liste, waeren sie der haeufigste Fall und verdraengten genau die
 * Meldungen, wegen derer jemand nachsieht.
 */
it('nennt unter den haeufigsten Gruenden nur Problemfaelle', function () {
    $kampagne = kampagne();

    foreach (range(1, 3) as $i) {
        zeile($kampagne, [
            'zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT,
            'zustellung_grund' => '250 2.0.0 Queued email for delivery',
        ]);
    }

    zeile($kampagne, [
        'zustellung_status' => MailDispatch::ZUSTELLUNG_UNZUSTELLBAR,
        'zustellung_grund' => '550 5.1.1 User unknown',
    ]);

    $gruende = app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung']['haeufigste_gruende'];

    expect($gruende)->toHaveCount(1)
        ->and($gruende[0]['grund'])->toBe('550 5.1.1 User unknown');
});

/**
 * Bei einem Versand, der gestern endete, waere „Laufzeit" sonst die Zeit seit
 * gestern — und das Tempo entsprechend erfunden.
 */
it('misst die Laufzeit bis zur letzten Mail, nicht bis jetzt', function () {
    Carbon::setTestNow('2026-09-12 12:00:00');

    $kampagne = kampagne(['gestartet_am' => Carbon::parse('2026-09-10 08:00:00')]);

    zeile($kampagne, ['verschickt_am' => Carbon::parse('2026-09-10 09:00:00')]);
    zeile($kampagne, ['verschickt_am' => Carbon::parse('2026-09-10 10:00:00')]);

    $bilanz = app(Kampagnenauswertung::class)->fuer($kampagne)['auswertung'];

    expect($bilanz['laufzeit_minuten'])->toBe(120)
        ->and($bilanz['rate_je_stunde'])->toBe(1);

    Carbon::setTestNow();
});

/**
 * Ein gescheiterter VERSUCH liegt zwischen uns und dem Postausgang, eine
 * gescheiterte ZUSTELLUNG zwischen Postausgang und Empfaenger. Zusammengezaehlt
 * wiese man einen Serverfehler bei uns als tote Adresse aus.
 */
it('zaehlt Sendeversuche getrennt von der Zustellung', function () {
    $kampagne = kampagne();
    $eintrag = zeile($kampagne, ['versuche' => 2, 'zustellung_status' => MailDispatch::ZUSTELLUNG_ZUGESTELLT]);

    MailDispatchVersuch::create([
        'mail_dispatch_id' => $eintrag->id,
        'nummer' => 1,
        'begonnen_am' => Carbon::parse('2026-09-10 09:00:00'),
        'ergebnis' => MailDispatchVersuch::ERGEBNIS_FEHLSCHLAG,
        'fehler' => 'Connection could not be established',
    ]);

    MailDispatchVersuch::create([
        'mail_dispatch_id' => $eintrag->id,
        'nummer' => 2,
        'begonnen_am' => Carbon::parse('2026-09-10 09:05:00'),
        'ergebnis' => MailDispatchVersuch::ERGEBNIS_ERFOLG,
    ]);

    // Ohne Ergebnis heisst „abgebrochen", nicht „gescheitert": Der Worker wurde
    // beendet, bevor er berichten konnte.
    MailDispatchVersuch::create([
        'mail_dispatch_id' => $eintrag->id,
        'nummer' => 3,
        'begonnen_am' => Carbon::parse('2026-09-10 09:10:00'),
    ]);

    $ergebnis = app(Kampagnenauswertung::class)->fuer($kampagne);

    expect($ergebnis['versuche']['gesamt'])->toBe(3)
        ->and($ergebnis['versuche']['gescheitert'])->toBe(1)
        ->and($ergebnis['versuche']['abgebrochen'])->toBe(1)
        ->and($ergebnis['versuche']['gruende'][0]['grund'])->toBe('Connection could not be established')
        ->and($ergebnis['auswertung']['zugestellt'])->toBe(1);
});

it('nimmt nur die Zeilen der eigenen Kampagne', function () {
    $meine = kampagne();
    $fremde = kampagne();

    zeile($meine);
    zeile($fremde);
    zeile($fremde);

    expect(app(Kampagnenauswertung::class)->fuer($meine)['summary'][MailDispatch::STATUS_VERSCHICKT])->toBe(1);
});

/**
 * Ohne verbundenes Konto darf die Oberflaeche gar nichts behaupten — „0
 * zugestellt" waere eine Aussage ueber etwas, das nie gemessen wurde.
 */
it('meldet ein nicht verbundenes Konto, statt Nullen auszuweisen', function () {
    config()->set('services.mailgun.secret', null);

    $kampagne = kampagne();
    zeile($kampagne);

    $zustellung = app(Kampagnenauswertung::class)->fuer($kampagne)['zustellung'];

    expect($zustellung['verbunden'])->toBeFalse()
        ->and($zustellung['gemessen'])->toBe(0);
});
