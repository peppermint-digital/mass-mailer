<?php

namespace Peppermint\MassMailer;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Peppermint\MassMailer\Console\InstallCommand;
use Peppermint\MassMailer\Contracts\Bezugsaufloeser;
use Peppermint\MassMailer\Contracts\Kaskade;
use Peppermint\MassMailer\Contracts\Mailgunzugang;
use Peppermint\MassMailer\Contracts\Sperrliste;
use Peppermint\MassMailer\Listeners\ProtokolliertVersand;
use Peppermint\MassMailer\Services\MailgunKonto;
use Peppermint\MassMailer\Services\MailgunZustellabgleich;
use Peppermint\MassMailer\Services\Mailmessung;
use Peppermint\MassMailer\Services\MailserverAufloeser;
use Peppermint\MassMailer\Services\Versandplan;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Peppermint\MassMailer\Services\VersandtempoAufloeser;
use Peppermint\MassMailer\Support\EinstufigeKaskade;
use Peppermint\MassMailer\Support\MailgunzugangAusConfig;

class MassMailerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mass-mailer.php', 'mass-mailer');

        // Der Standard-Zugang liest aus der Konfiguration. `bindIf`, damit eine
        // Anwendung, die ihre Zugangsdaten woanders haelt (Connect in den
        // Plattformeinstellungen), einfach vorher etwas anderes binden kann.
        $this->app->bindIf(Mailgunzugang::class, MailgunzugangAusConfig::class);

        // Die Vorgabe ist eine Kaskade mit genau einer Stufe: dem Bereich
        // selbst. Damit laeuft das Paket ohne jede Bindung, und eine Anwendung
        // mit Hierarchie bindet ihre eigene.
        $this->app->bindIf(Kaskade::class, EinstufigeKaskade::class);

        $this->app->singleton(Mailmessung::class);

        // Die beiden Vertraege sind OPTIONAL. Ohne sie funktioniert das
        // Protokoll vollstaendig — es traegt dann nur keinen Bezug ein und
        // sperrt keine Adressen. Ein Paket, das eine Bindung erzwingt, die die
        // Anwendung nicht braucht, ist ein Paket, das man nicht einziehen kann.
        $this->app->singleton(Versandprotokoll::class, fn ($app) => new Versandprotokoll(
            $app->bound(Bezugsaufloeser::class) ? $app->make(Bezugsaufloeser::class) : null,
            $app->bound(Sperrliste::class) ? $app->make(Sperrliste::class) : null,
            $app->make(Mailmessung::class),
        ));

        $this->app->singleton(MailserverAufloeser::class);
        $this->app->singleton(VersandtempoAufloeser::class);
        $this->app->singleton(Versandplan::class);

        $this->app->singleton(MailgunKonto::class);
        $this->app->singleton(MailgunZustellabgleich::class);
    }

    public function boot(): void
    {
        // Kein loadMigrationsFrom(): Der Host besitzt seine Tabellen. Die
        // Spalten kommen ueber MassMailerSchema aus einer Migration IN der
        // Anwendung — so laeuft nie etwas unangekuendigt gegen Produktion.

        if (config('mass-mailer.protokoll.enabled', true)) {
            $this->zuhoererEinhaengen();
        }

        if (config('mass-mailer.messung.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mass-mailer.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/mass-mailer.php' => config_path('mass-mailer.php'),
            ], 'mass-mailer-config');
        }
    }

    /**
     * Die drei Zeitpunkte, an denen eine Mail etwas ueber sich verraet.
     *
     * Ueber Ereignisse und nicht ueber die Aufrufer: Nur so kommt jede Mail
     * vorbei — auch die aus fremden Paketen und die, die es beim Einbau des
     * Protokolls noch gar nicht gab.
     */
    private function zuhoererEinhaengen(): void
    {
        Event::listen(MessageSending::class, [ProtokolliertVersand::class, 'beiVersand']);
        Event::listen(MessageSent::class, [ProtokolliertVersand::class, 'beiAnnahme']);
        Event::listen(JobFailed::class, [ProtokolliertVersand::class, 'beiFehlschlag']);
    }
}
