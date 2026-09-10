# peppermint/mass-mailer

Massenversand für Laravel — der Teil, der **nach** dem Absenden anfängt.

[`peppermint/mail-builder`](https://github.com/peppermint-digital/mail-builder) baut die
Mail. Dieses Paket bringt sie raus und sagt, was aus ihr geworden ist. Die beiden sind
unabhängig voneinander benutzbar und ergänzen sich, wo beide da sind.

## Was drin ist

| | |
|---|---|
| **Versandprotokoll** | Eine Zeile je verschickter Mail — wer, wann, was, und hat es geklappt. Hängt an Laravels Mail-Ereignissen und erfasst damit **jede** Mail der Anwendung, auch die aus fremden Paketen. |
| **Sendeversuche** | Wie oft es gebraucht hat und woran die Anläufe davor scheiterten. Ein Versuch ohne Ergebnis ist ein abgebrochener — der häufigste Fall, wenn ein Deploy in einen laufenden Versand fällt. |
| **Rückläufer** | Die Unterscheidung dauerhaft/vorübergehend nach SMTP-Code und Wortlaut. Nur Dauerhaftes darf eine Adresse sperren. |
| **Zustellabgleich** | Holt bei Mailgun nach, was aus den Mails geworden ist. Per Abfrage, nicht per Webhook — die Abfrage kann Vergangenheit nachholen. |
| **Kontozustand** | Ist die Domain gesperrt, wie viele Mails gingen in der letzten Stunde raus. Die Zahl, die vor einer Tempoerhöhung fehlt. |
| **Öffnungen und Klicks** | Zählpixel und Klick-Umleitung, je Mail schaltbar, standardmäßig aus. |

**Noch nicht drin** (kommt in den nächsten Stufen): Postausgangs-Kaskade, Versandtempo
und -plan, Kampagnen, Oberflächen-Stubs, Newsletter mit An- und Abmeldung.

## Warum die Klassennamen deutsch sind

Der Code stammt aus Peppermint Connect und ist dort über Monate gewachsen. Ein Umzug
**und** eine Umbenennung wären zwei Änderungen auf einmal gewesen — und beim Umzug einer
gewachsenen Funktion gehen erfahrungsgemäß genau die Feinheiten still verloren, die
nirgendwo sonst existieren. Der Namensraum ist englisch, damit er sich zu den übrigen
Peppermint-Paketen fügt.

## Installation

```bash
composer require peppermint/mass-mailer
php artisan mass-mailer:install
```

Der Befehl publiziert die Konfiguration und legt eine Migration **in deiner Anwendung**
an. Bewusst dort und nicht im Paket: Sie verändert deine Datenbank, also gehört sie in
dein Repository, wo sie gelesen und versioniert wird. Einmal durchlesen, dann
`php artisan migrate`.

## Der eine Schritt, der wirklich nötig ist

Damit das Protokoll mehr als „Adresse und Betreff" weiß, geben sich die Mails zu
erkennen. Das passiert im `envelope()` und kostet ein paar Zeilen je Mailable:

```php
use Peppermint\MassMailer\Support\Metadatenschluessel as M;

public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Ihre Anmeldung',
        metadata: [
            M::ART          => 'anmeldebestaetigung',
            M::MANDANT      => (string) $this->event->organization_id,
            M::BEREICH_TYP  => Event::class,
            M::BEREICH      => (string) $this->event->id,
            M::BEZUG_TYP    => Registration::class,
            M::BEZUG        => (string) $this->registration->id,
        ],
    );
}
```

Alle Werte sind Strings — Metadaten reisen als Kopfzeilen, und eine Kopfzeile kennt
nichts anderes.

**`bereich` gegen `bezug`:** Der Bereich ist, *worin* die Mail steht (in Connect die
Veranstaltung) — danach wird gefiltert und ausgewertet. Der Bezug ist, *um wen* es geht
(die Anmeldung) — daran hängt „was ging an diese Person raus?".

Ohne Metadaten entsteht die Zeile trotzdem. **Eine magere Zeile ist besser als eine
fehlende.**

## Die drei optionalen Verträge

Alle drei sind freiwillig. Ohne sie funktioniert das Paket vollständig — es trägt dann
nur weniger ein.

### `Bezugsaufloeser` — wer steckt hinter einer Adresse?

Für Mails, die ihren Bezug nicht mitbringen (Passwort-Zurücksetzen, Notifications aus
fremden Paketen). Das Paket sucht bewusst nicht selbst: Wie eindeutig eine Adresse ist
und wie weit gesucht werden darf, weiß nur die Anwendung.

```php
$this->app->bind(Bezugsaufloeser::class, fn () => new class implements Bezugsaufloeser {
    public function ausAdresse(string $adresse, ?string $bereichTyp, ?int $bereichId, array $metadaten = []): ?Model
    {
        // Die Eingrenzung auf den Bereich ist keine Kür: Ohne sie hängt eine
        // Bestätigung für Veranstaltung A an der Anmeldung für Veranstaltung B,
        // sobald jemand bei beiden dabei ist.
        return $bereichId === null ? null : Registration::query()
            ->where('event_id', $bereichId)
            ->where('email', $adresse)
            ->first();
    }
});
```

### `Sperrliste` — wohin mit einer toten Adresse?

Das Paket erkennt, *dass* eine Adresse endgültig unbrauchbar ist. Wo der Vermerk
hingehört, weiß nur die Anwendung.

Nicht am Bezug festmachen: In Connect ist der Bezug eine *Anmeldung*, die Adresse gehört
aber der *Person* und gilt für jede künftige Veranstaltung. Hinge es an der Anmeldung,
schriebe man dieselbe kaputte Adresse bei der nächsten Einladung wieder an.

### `Mailgunzugang` — woher die Zugangsdaten kommen

Ohne eigene Bindung liest das Paket `mass-mailer.mailgun.*` und fällt auf Laravels
`services.mailgun.*` zurück. Wer sie in der Datenbank pflegt (damit ein Kontowechsel kein
Deploy ist), bindet eine eigene Fassung.

## Zwei Dinge, die man beim Einziehen falsch machen kann

**Der Mailgun-Variablenschlüssel.** Unter ihm reist die Nummer der Protokollzeile mit;
nur darüber lassen sich Mailguns Ereignisse später wieder zuordnen. Zieht eine Anwendung
das Paket nach und hat schon einen Schlüssel benutzt, gehört in
`mass-mailer.mailgun.variablen_schluessel` der **alte** Wert — sonst verlieren alle
Mails, die gerade unterwegs sind, ihre Zuordnung. Teilen sich zwei Anwendungen ein
Mailgun-Konto, brauchen sie **verschiedene** Schlüssel.

**Der EU-Endpunkt.** Ein Konto in der EU muss gegen `api.eu.mailgun.net` gefragt werden.
Gegen den US-Endpunkt antwortet Mailgun mit *404 „Domain not found"* — als gäbe es die
Domain nicht. Häufigster Einrichtungsfehler, und er sieht nach etwas völlig anderem aus.

## Messung

Standardmäßig aus, und das ist keine Vorsicht ohne Grund: Der Zugriff auf das Endgerät
des Empfängers braucht eine Rechtsgrundlage (§ 25 TDDDG), die nur der Betreiber
herstellen kann.

Ob gemessen wird, entscheidet die Anwendung **je Mail** über
`M::MESSUNG_OEFFNUNGEN` / `M::MESSUNG_KLICKS` im `envelope()`. Der Konfigurationsschalter
`mass-mailer.messung.enabled` ist nur die äußerste Grenze; steht er auf `false`, werden
die Routen gar nicht erst registriert.

Zu den Zahlen: **Klicks** sind eine Handlung des Empfängers und damit belastbar.
**Öffnungen** sind ein geladenes Bild — Apple Mail lädt seit 2021 alle Bilder vorab,
Gmail über einen Proxy, andere Programme laden sie gar nicht. Die Zahl liegt systematisch
zu hoch *und* zu niedrig, je nach Postfach. Wer sie anzeigt, sollte das dazusagen.

## Zustellabgleich einplanen

```php
// routes/console.php
Schedule::call(fn () => app(MailgunZustellabgleich::class)
    ->fuerZeitraum(now()->subHours(6)->toImmutable()))->hourly();
```

Das Fenster überlappt mit Absicht — so darf ein Lauf ausfallen und der nächste holt nach.
Die Grenze ist Mailguns Gedächtnis: Ereignisse liegen dort je nach Tarif teils nur einen
Tag. Ein dauerhaft ausgefallener Abgleich ist deshalb echter Datenverlust, keine
Verzögerung.

## Tests

```bash
composer install
vendor/bin/pest
```
