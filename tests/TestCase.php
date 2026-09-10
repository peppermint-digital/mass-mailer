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
    }
}
