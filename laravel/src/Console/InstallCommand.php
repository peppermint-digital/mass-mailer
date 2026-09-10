<?php

namespace Peppermint\MassMailer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'mass-mailer:install
        {--no-migration : Migration nicht erzeugen.}
        {--react : Die Oberflaechen-Vorlagen nach resources/js/components/ kopieren.}
        {--force : Vorhandene publizierte Dateien ueberschreiben.}';

    protected $description = 'Installiert peppermint/mass-mailer: Konfiguration publizieren, Migration erzeugen, naechste Schritte zeigen.';

    public function handle(): int
    {
        $this->components->info('peppermint/mass-mailer — Installation');

        $this->configPublizieren();

        if ($this->option('react')) {
            $this->oberflaechenPublizieren();
        }

        if (! $this->option('no-migration')) {
            $this->migrationErzeugen();
        }

        $this->naechsteSchritte();

        $this->components->success('Fertig. Migration durchlesen, dann `php artisan migrate`.');

        return self::SUCCESS;
    }

    private function configPublizieren(): void
    {
        $args = ['--tag' => 'mass-mailer-config'];

        if ($this->option('force')) {
            $args['--force'] = true;
        }

        $this->call('vendor:publish', $args);
    }

    /**
     * Kopiert die drei Oberflaechen-Vorlagen in die Anwendung.
     *
     * Vorlagen und keine Bausteine: Sie gehoeren nach dem Kopieren der
     * Anwendung, samt Layout, Routen und Benennung. Ein Formular, das sich beim
     * naechsten `composer update` unter der Hand anders verhaelt, waere in
     * einer Maske, die jemand taeglich benutzt, das Gegenteil von hilfreich.
     */
    private function oberflaechenPublizieren(): void
    {
        $args = ['--tag' => 'mass-mailer-react'];

        if ($this->option('force')) {
            $args['--force'] = true;
        }

        $this->call('vendor:publish', $args);
    }

    /**
     * Schreibt die Migration in die Anwendung statt sie im Paket auszuliefern.
     *
     * Ein Paket, das beim `composer update` still eine Migration mitbringt, die
     * beim naechsten Deploy gegen eine Produktionstabelle laeuft, ist ein
     * Selbstschussgeraet.
     */
    private function migrationErzeugen(): void
    {
        $protokoll = (string) config('mass-mailer.tabellen.protokoll', 'mail_dispatches');
        $versuche = (string) config('mass-mailer.tabellen.versuche', 'mail_dispatch_versuche');
        $postausgaenge = (string) config('mass-mailer.tabellen.postausgaenge', 'mail_servers');
        $kampagnen = (string) config('mass-mailer.tabellen.kampagnen', 'mass_email_campaigns');

        $stub = str_replace(
            ['{{ protokoll }}', '{{ versuche }}', '{{ postausgaenge }}', '{{ kampagnen }}'],
            [$protokoll, $versuche, $postausgaenge, $kampagnen],
            File::get(__DIR__.'/../../stubs/migration.php.stub'),
        );

        $dateiname = sprintf('%s_create_mass_mailer_tables.php', date('Y_m_d_His'));
        $pfad = database_path('migrations/'.$dateiname);

        if (File::exists($pfad) && ! $this->option('force')) {
            $this->components->warn("Migration existiert bereits: {$dateiname}");

            return;
        }

        File::put($pfad, $stub);

        $this->components->info("Migration angelegt: database/migrations/{$dateiname}");
        $this->components->warn('Bitte vor dem Migrieren einmal durchlesen.');
    }

    private function naechsteSchritte(): void
    {
        $this->newLine();
        $this->components->info('Noch zu erledigen:');
        $this->line('  1. Mails geben sich zu erkennen: Metadatenschluessel im envelope() setzen.');
        $this->line('  2. Optional: Bezugsaufloeser binden (wer steckt hinter einer Adresse?).');
        $this->line('  3. Optional: Sperrliste binden (wohin mit einer toten Adresse?).');
        $this->line('  4. Fuer den Zustellabgleich: MAILGUN_SECRET und MAILGUN_DOMAIN setzen.');

        if (! $this->option('react')) {
            $this->line('  5. Oberflaechen-Vorlagen holen: `--react` (Postausgang, Protokoll, Auswertung).');
        }
        $this->newLine();
        $this->line('  Die README zeigt zu jedem Punkt ein Beispiel.');
    }
}
