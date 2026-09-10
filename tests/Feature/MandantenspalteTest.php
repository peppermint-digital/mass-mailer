<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Models\Versandkampagne;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Peppermint\MassMailer\Support\Mandant;
use Peppermint\MassMailer\Support\MassMailerSchema;
use Peppermint\MassMailer\Support\Metadatenschluessel;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;

/**
 * Der Fall, der diese Einstellung erzwungen hat: Connect haengt an jedem
 * mandantenbehafteten Modell `BelongsToOrganization`, und der ist fest auf
 * `organization_id` verdrahtet. Haette das Paket auf `mandant_id` bestanden,
 * waere die Wahl gewesen — Mandantentrennung im Protokoll aufgeben oder
 * Connects Tenancy anfassen. Beides fuer einen Spaltennamen.
 */
beforeEach(function () {
    config()->set('mass-mailer.mandant.spalte', 'organization_id');

    // Die Tabellen entstehen im TestCase mit der Vorgabe. Fuer diesen Test
    // baue ich sie mit der geaenderten Einstellung neu auf — sonst pruefte er
    // nur, dass die Konfiguration gelesen wird, nicht dass sie ankommt.
    Schema::dropIfExists(MassMailerSchema::TABELLE_VERSUCHE);
    Schema::dropIfExists(MassMailerSchema::TABELLE_PROTOKOLL);
    Schema::dropIfExists(MassMailerSchema::TABELLE_KAMPAGNEN);

    Schema::create(MassMailerSchema::TABELLE_PROTOKOLL, function (Blueprint $t): void {
        MassMailerSchema::protokollTabelle($t);
    });
    Schema::create(MassMailerSchema::TABELLE_VERSUCHE, function (Blueprint $t): void {
        MassMailerSchema::versucheTabelle($t);
    });
    Schema::create(MassMailerSchema::TABELLE_KAMPAGNEN, function (Blueprint $t): void {
        MassMailerSchema::kampagnenTabelle($t);
    });
});

it('legt die Spalte unter dem Namen des Hosts an', function () {
    expect(Schema::hasColumn(MassMailerSchema::TABELLE_PROTOKOLL, 'organization_id'))->toBeTrue()
        ->and(Schema::hasColumn(MassMailerSchema::TABELLE_PROTOKOLL, 'mandant_id'))->toBeFalse()
        ->and(Schema::hasColumn(MassMailerSchema::TABELLE_KAMPAGNEN, 'organization_id'))->toBeTrue();
});

it('schreibt den Mandanten in die Spalte des Hosts', function () {
    $nachricht = (new Email)->to('anna@beispiel.de')->subject('x')->html('<p>x</p>');
    $nachricht->getHeaders()->add(new MetadataHeader(Metadatenschluessel::MANDANT, '7'));

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect(MailDispatch::sole()->getAttribute('organization_id'))->toBe(7);
});

/**
 * Die Gegenprobe: Ohne die Ergänzung in `getFillable()` würde die
 * Massenzuweisung den Wert stillschweigend verwerfen — kein Fehler, nur eine
 * Zeile ohne Mandant. Genau die Sorte Ausfall, die erst auffällt, wenn jemand
 * eine Auswertung nach Mandant filtert.
 */
it('verwirft den Mandanten nicht bei der Massenzuweisung', function () {
    $kampagne = Versandkampagne::create([
        'betreff' => 'x',
        'rumpf' => 'y',
        'organization_id' => 7,
    ]);

    expect($kampagne->fresh()->getAttribute('organization_id'))->toBe(7)
        ->and($kampagne->fresh()->metadaten()[Metadatenschluessel::MANDANT])->toBe('7');
});

it('nennt die konfigurierte Spalte', function () {
    expect(Mandant::spalte())->toBe('organization_id');
});

it('faellt bei leerem Eintrag auf die Vorgabe zurueck', function () {
    config()->set('mass-mailer.mandant.spalte', null);

    expect(Mandant::spalte())->toBe(Mandant::VORGABE_SPALTE);
});
