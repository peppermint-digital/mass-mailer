<?php

use Illuminate\Support\ServiceProvider;
use Peppermint\MassMailer\MassMailerServiceProvider;

/**
 * Die Oberflaechen sind Vorlagen zum Kopieren, keine Bausteine zum Einbinden.
 *
 * Geprueft wird deshalb die Marke und nicht der Inhalt: Sobald sie in der
 * Anwendung liegen, gehoeren sie ihr — samt Layout, Routen und Benennung. Was
 * der Test verhindert, ist der stille Ausfall: eine Datei umbenannt, die Marke
 * zeigt ins Leere, und `--react` kopiert wortlos nichts.
 */
it('bietet die drei Oberflaechen-Vorlagen unter einer Marke an', function () {
    $pfade = ServiceProvider::pathsToPublish(MassMailerServiceProvider::class, 'mass-mailer-react');

    expect($pfade)->toHaveCount(1);

    $quelle = array_key_first($pfade);

    expect(is_dir($quelle))->toBeTrue()
        ->and(reset($pfade))->toEndWith('js/components/');

    $dateien = array_map('basename', glob($quelle.'/*.tsx'));

    expect($dateien)->toEqualCanonicalizing([
        'mass-mailer-postausgang-form.tsx',
        'mass-mailer-protokoll.tsx',
        'mass-mailer-auswertung.tsx',
    ]);
});

it('publiziert die Konfiguration unter einer eigenen Marke', function () {
    // Getrennte Marken, damit `vendor:publish --tag=mass-mailer-config` in
    // einer Anwendung ohne React nicht ploetzlich Komponenten mitkopiert.
    $pfade = ServiceProvider::pathsToPublish(MassMailerServiceProvider::class, 'mass-mailer-config');

    expect($pfade)->toHaveCount(1)
        ->and(reset($pfade))->toEndWith('config/mass-mailer.php');
});
