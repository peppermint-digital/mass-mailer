<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Peppermint\MassMailer\Http\Controllers\MessungController;

/*
|--------------------------------------------------------------------------
| Messung
|--------------------------------------------------------------------------
|
| Beide Routen sind `signed` — ohne das liesse sich die Statistik von aussen
| mit erfundenen Oeffnungen fuellen, indem jemand die laufenden Nummern
| durchprobiert.
|
| Bewusst OHNE `auth`: Die Adressen werden aus dem Postfach des Empfaengers
| aufgerufen, und der ist in aller Regel nicht angemeldet. Die Middleware aus
| der Konfiguration wird davor gehaengt, falls jemand etwa `web` braucht.
|
*/

// `SubstituteBindings` ausdruecklich und nicht nur ueber die Middleware aus
// der Konfiguration: Ohne sie loest `{dispatch}` NICHT auf die Protokollzeile
// auf — der Controller bekaeme ein frisches, leeres Modell und legte bei jedem
// Aufruf eine Muellzeile an, statt die gemeinte zu zaehlen. Kein Fehler weit
// und breit, nur falsche Zahlen. In Connect fiel das nie auf, weil die Routen
// dort in der `web`-Gruppe lagen, die sie mitbringt.
Route::middleware([SubstituteBindings::class, ...(array) config('mass-mailer.messung.middleware', [])])
    ->prefix(config('mass-mailer.messung.prefix', 'mail'))
    ->group(function (): void {
        Route::get('/messung/oeffnung/{dispatch}', [MessungController::class, 'oeffnung'])
            ->middleware('signed')
            ->name('mass-mailer.messung.oeffnung');

        Route::get('/messung/klick/{dispatch}', [MessungController::class, 'klick'])
            ->middleware('signed')
            ->name('mass-mailer.messung.klick');
    });
