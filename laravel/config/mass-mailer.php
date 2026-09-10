<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tabellen
    |--------------------------------------------------------------------------
    |
    | Die beiden Protokolltabellen. Konfigurierbar, weil eine Anwendung, die
    | das Paket nachtraeglich einzieht, ihre Tabelle womoeglich schon anders
    | genannt hat — ein fest verdrahteter Name zwaenge sie zu einer
    | Umbenennung in Produktion, fuer nichts.
    |
    */

    'tabellen' => [
        'protokoll' => 'mail_dispatches',
        'versuche' => 'mail_dispatch_versuche',
        'postausgaenge' => 'mail_servers',
        'kampagnen' => 'mass_email_campaigns',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mandant
    |--------------------------------------------------------------------------
    |
    | Nennt ein Kampagnenentwurf keinen Mandanten, versucht das Paket ihn aus
    | diesem Feld des Bereichs zu lesen. In Connect heisst es
    | `organization_id`, anderswo anders. Fehlt es, bleibt der Mandant leer —
    | eine Kampagne ohne Mandant ist besser als eine mit dem falschen.
    |
    */

    'mandant' => [
        'spalte' => env('MASS_MAILER_MANDANT_SPALTE', 'organization_id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Versandtempo
    |--------------------------------------------------------------------------
    |
    | Je STUNDE, weil die Grenzen der Anbieter je Stunde gelten (Mailgun
    | drosselt neue Konten auf 100/Stunde). Stuende die Einstellung in Minuten,
    | muesste jeder den Wert aus dem Vertrag erst umrechnen — und ein
    | Tippfehler um den Faktor 60 sieht im Feld aus wie eine gewoehnliche Zahl.
    |
    | 60 als Vorgabe: der Wert, der nachweislich durchlaeuft, mit Luft unter
    | einer 100er-Drosselung fuer die Mails, die nebenher entstehen.
    |
    | `spalte` ist der Name des Feldes, in dem eine Ebene der Kaskade ihr
    | eigenes Tempo haelt. Fehlt es an einem Modell, uebernimmt die naechste
    | Stufe — nicht jede Ebene muss das Tempo kennen.
    |
    */

    'tempo' => [
        'pro_stunde' => (int) env('MASS_MAILER_PRO_STUNDE', 60),
        'spalte' => env('MASS_MAILER_TEMPO_SPALTE', 'mail_rate_per_hour'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Versandprotokoll
    |--------------------------------------------------------------------------
    |
    | `enabled = false` haengt den Zuhoerer gar nicht erst ein. Gedacht fuer
    | Testlaeufe und fuer Anwendungen, die nur den Zustellabgleich wollen.
    |
    | `kopfzeile` ist der Schluessel, unter dem sich eine Nachricht ihre eigene
    | Protokollzeile merkt. Sie reist mit der Mail und wird beim Empfaenger
    | nicht angezeigt.
    |
    | `wiederholungsfenster_stunden`: Wie lange eine offene Zeile als derselbe
    | Vorgang gilt. Grosszuegig bemessen, weil zwischen zwei Versuchen
    | `queue.connections.*.retry_after` liegt — real gern zwei Stunden. Eine
    | Zeile, die laenger als einen Tag auf `im_versand` steht, ist ohnehin
    | verwaist; sie nochmal zu benutzen wuerde nichts verbessern.
    |
    */

    'protokoll' => [
        'enabled' => filter_var(env('MASS_MAILER_PROTOKOLL', true), FILTER_VALIDATE_BOOL),
        'kopfzeile' => env('MASS_MAILER_KOPFZEILE', 'X-Mass-Mailer-Protokoll'),
        'wiederholungsfenster_stunden' => (int) env('MASS_MAILER_WIEDERHOLUNGSFENSTER', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail-Arten in Worten
    |--------------------------------------------------------------------------
    |
    | Der Katalog fuer die Protokollanzeige: Schluessel → Beschriftung. Das
    | Paket kennt die Mail-Arten seiner Anwendung nicht, deshalb steht die
    | Liste hier. Fehlt ein Schluessel, zeigt das Protokoll ihn roh an — eine
    | alte Zeile soll lesbar bleiben, auch wenn die Mail-Art inzwischen
    | umbenannt wurde.
    |
    | `bulk` und `einzel` sind fest eingebaut und muessen nicht eingetragen
    | werden.
    |
    */

    'arten' => [
        // 'anmeldebestaetigung' => 'Anmeldebestätigung',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailgun
    |--------------------------------------------------------------------------
    |
    | Zugangsdaten fuer den Zustellabgleich. Bleiben sie leer, faellt das Paket
    | auf Laravels eigene `services.mailgun.*` zurueck — wer Mailgun ohnehin
    | als Mailer eingerichtet hat, soll die Werte nicht ein zweites Mal
    | pflegen.
    |
    | Wer sie woanders haelt (Connect pflegt sie in den Plattformeinstellungen,
    | damit ein Kontowechsel kein Deploy ist), bindet stattdessen eine eigene
    | Fassung von `Contracts\Mailgunzugang`.
    |
    | ACHTUNG beim Endpunkt: Ein Konto in der EU muss gegen
    | `api.eu.mailgun.net` gefragt werden. Gegen den US-Endpunkt antwortet
    | Mailgun mit 404 „Domain not found" — als gaebe es die Domain nicht. Das
    | ist der haeufigste Einrichtungsfehler, und er sieht nach etwas voellig
    | anderem aus.
    |
    | ## `variablen_schluessel` — bitte einmal bewusst setzen
    |
    | Unter diesem Schluessel reist die Nummer der Protokollzeile in Mailguns
    | „user variables" mit; nur darueber lassen sich dessen Ereignisse spaeter
    | wieder unseren Zeilen zuordnen. Eigene Kopfzeilen tauchen in Mailguns
    | Ereignissen NICHT auf.
    |
    | Zwei Dinge haengen daran:
    |
    | - Teilen sich zwei Anwendungen ein Mailgun-Konto, brauchen sie
    |   VERSCHIEDENE Schluessel — sonst zieht der Abgleich der einen die Zeilen
    |   der anderen an sich.
    | - Wird der Schluessel geaendert, verlieren alle Mails, die gerade
    |   unterwegs sind, ihre Zuordnung. Beim Einziehen des Pakets in eine
    |   Anwendung, die schon einen Schluessel benutzt hat, gehoert hier der
    |   ALTE Wert hinein.
    |
    */

    'mailgun' => [
        'secret' => env('MAILGUN_SECRET'),
        'domain' => env('MAILGUN_DOMAIN'),
        'endpoint' => env('MAILGUN_ENDPOINT'),
        'variablen_schluessel' => env('MASS_MAILER_MAILGUN_VARIABLE', 'mass_mailer_protokoll'),

        // Wie weit der Abgleich zurueckschaut, wenn ihm niemand einen Zeitraum
        // nennt. Ueberlappend gedacht: Der Abgleich darf ausfallen und beim
        // naechsten Lauf nachholen. Die Grenze ist Mailguns Gedaechtnis —
        // Ereignisse liegen dort je nach Tarif teils nur einen Tag.
        'fenster_stunden' => (int) env('MASS_MAILER_ABGLEICH_FENSTER', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Oeffnungs- und Klickmessung
    |--------------------------------------------------------------------------
    |
    | Standardmaessig AUS, und das ist keine Vorsicht ohne Grund: Der Zugriff
    | auf das Endgeraet des Empfaengers braucht eine Rechtsgrundlage
    | (§ 25 TDDDG), die nur der Betreiber herstellen kann. Eine Voreinstellung
    | „an" traefe diese Entscheidung stillschweigend fuer ihn.
    |
    | Ob gemessen wird, entscheidet ohnehin der Aufrufer je Mail — diese
    | Schalter sind nur die aeusserste Grenze. Steht `enabled` auf false,
    | werden die Routen gar nicht erst registriert.
    |
    | Zu den beiden Messungen: **Klicks** sind eine Handlung des Empfaengers
    | und damit belastbar. **Oeffnungen** sind ein geladenes Bild — Apple Mail
    | laedt seit 2021 alle Bilder vorab, Gmail ueber einen Proxy, andere
    | Programme laden sie gar nicht. Die Zahl liegt systematisch zu hoch UND zu
    | niedrig, je nach Postfach.
    |
    */

    'messung' => [
        'enabled' => filter_var(env('MASS_MAILER_MESSUNG', false), FILTER_VALIDATE_BOOL),
        'prefix' => env('MASS_MAILER_MESSUNG_PREFIX', 'mail'),
        'middleware' => [],
    ],

];
