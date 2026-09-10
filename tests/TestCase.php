<?php

namespace Peppermint\MassMailer\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Basis;
use Peppermint\MassMailer\MassMailerServiceProvider;
use Peppermint\MassMailer\Support\MassMailerSchema;

abstract class TestCase extends Basis
{
    protected function getPackageProviders($app): array
    {
        return [MassMailerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('mail.default', 'array');
    }

    /**
     * Dieselben Helfer, die auch die Migration der Anwendung benutzt.
     *
     * Bewusst nicht eine eigene Testtabelle von Hand: Sonst pruefte die Suite
     * eine Form, die es in keiner Anwendung gibt — und ein Fehler im
     * Schema-Helfer bliebe unentdeckt.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create(MassMailerSchema::TABELLE_PROTOKOLL, function (Blueprint $table): void {
            MassMailerSchema::protokollTabelle($table);
        });

        Schema::create(MassMailerSchema::TABELLE_VERSUCHE, function (Blueprint $table): void {
            MassMailerSchema::versucheTabelle($table);
        });

        Schema::create(MassMailerSchema::TABELLE_POSTAUSGAENGE, function (Blueprint $table): void {
            MassMailerSchema::postausgangTabelle($table);
        });

        Schema::create(MassMailerSchema::TABELLE_KAMPAGNEN, function (Blueprint $table): void {
            MassMailerSchema::kampagnenTabelle($table);
        });

        // Die Warteschlange als echte Tabelle. Klingt nach Aufwand fuer einen
        // Test, ist aber die einzige Art, die Staffelung zu BEWEISEN:
        // `Mail::fake()` verwirft die Verzoegerung, und `Queue::fake()` auch —
        // beide leiten `later()` auf `queue()` um. Wer die Staffelung gegen
        // eine Attrappe prueft, prueft seine eigene Vorstellung.
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
