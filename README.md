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
| **Eigener Postausgang** | Ein SMTP-Zugang je Ebene der Kaskade, Zugangsdaten verschlüsselt. Abgeschaltete Einträge fallen auf die nächste Stufe zurück. |
| **Drosselung** | Versandtempo je Stunde über dieselbe Kaskade, gleichmäßig über die Stunde verteilt — und kampagnenübergreifend reserviert, damit sich zwei Versendungen nicht überholen. |
| **Kampagnen** | Was an wen rausging und was tatsächlich drinstand — eine Kopie zum Zeitpunkt des Versands, keine Referenz auf die Vorlage. |
| **Auswertung** | Die Bilanz eines Versands: Zustellquote, Rückläufer getrennt nach dauerhaft und vorübergehend, Tempo, häufigste Meldungen. |
| **Oberflächen** | Drei React-Vorlagen (shadcn/ui, Inertia) zum Kopieren: Postausgang, Protokoll, Auswertung. |

**Noch nicht drin**: Newsletter mit An- und Abmeldung.

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

## Die vier optionalen Verträge

Alle vier sind freiwillig. Ohne sie funktioniert das Paket vollständig — es trägt dann
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

### `Kaskade` — wen frage ich zuerst?

Postausgang und Versandtempo laufen **dieselbe** Kette ab, von speziell nach allgemein.
Die erste Stufe mit einer Antwort gewinnt; antwortet keine, gilt die Weltkonfiguration.

Ohne Bindung gilt `EinstufigeKaskade`: der Bereich selbst und sonst nichts. Wer eine
Hierarchie hat, bindet seine eigene:

```php
$this->app->bind(Kaskade::class, fn () => new class implements Kaskade {
    public function ebenen(?Model $bereich): array
    {
        return $bereich instanceof Event
            ? array_values(array_filter([$bereich, $bereich->organization]))
            : [];
    }
});
```

Eine Kette und nicht drei — sonst kann sich niemand merken, welche wo gilt.

Das Tempo liest das Paket per `getAttribute` aus einem konfigurierbaren Feld
(`mass-mailer.tempo.spalte`, Vorgabe `mail_rate_per_hour`). Fehlt es an einer Ebene,
übernimmt die nächste. Nicht jede Ebene muss das Tempo kennen.

### `Mailgunzugang` — woher die Zugangsdaten kommen

Ohne eigene Bindung liest das Paket `mass-mailer.mailgun.*` und fällt auf Laravels
`services.mailgun.*` zurück. Wer sie in der Datenbank pflegt (damit ein Kontowechsel kein
Deploy ist), bindet eine eigene Fassung.

## Eigene Modellklassen

Braucht ein Modell etwas, das nur die Anwendung kennt — einen Mandanten-Scope etwa —,
erbt sie von der Paket-Klasse und trägt ihre eigene in `mass-mailer.modelle` ein:

```php
class MailDispatch extends \Peppermint\MassMailer\Models\MailDispatch
{
    use BelongsToOrganization;
}
```

Das Paket benutzt dann durchgehend die eingetragene — beim Anlegen, in Abfragen und in
den Beziehungen.

**Abfragen laufen dabei ohne globale Scopes.** Das ist nötig, nicht bequem: Das Protokoll
entsteht im Queue-Worker, und dort ist kein Mandant aktiv. Ein Mandanten-Scope würde die
Bestätigung ihre eigene Zeile nicht wiederfinden lassen, und der Eintrag bliebe für immer
auf „im Versand". Beim **Anlegen** gilt das Gegenteil: Da soll der Haken der Anwendung
greifen und den Mandanten eintragen — deshalb geht das Anlegen den normalen Weg.

## Die Mandantenspalte gehört dir

`mass-mailer.mandant.spalte` bestimmt, wie das Mandantenfeld in den Tabellen des
Pakets heißt (Vorgabe `mandant_id`). Wer schon ein Tenancy-System hat, trägt dessen
Namen ein und muss nichts umbenennen:

```php
'mandant' => [
    'spalte' => 'organization_id',       // in den Tabellen dieses Pakets
    'ableiten_aus' => 'organization_id', // am Bereich, wenn der Entwurf schweigt
],
```

Der Fall dahinter: Connects `BelongsToOrganization` ist fest auf `organization_id`
verdrahtet — er filtert danach und trägt beim Anlegen dort ein. Hätte das Paket auf
`mandant_id` bestanden, wäre die Wahl gewesen: Mandantentrennung im Protokoll aufgeben
oder das ganze Tenancy-System anfassen. Für einen Spaltennamen.

Dieselbe Überlegung wie bei `owner_type` am Postausgang: **Was schon dem Host gehört,
wird nicht umbenannt.**

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

## Eine Kampagne verschicken

```php
$kampagne = app(Massenversand::class)->verschicken(
    new Kampagnenentwurf(
        betreff: $betreff,
        rumpf: $rumpf,
        bereich: $event,
        filter: ['typ' => 'status', 'wert' => 'invited'],
        vorlageId: $vorlage?->id,
        ausgeloestVonId: auth()->id(),
    ),
    $registrations->map(fn ($r) => new Empfaenger(
        adresse: $r->email,
        platzhalter: $r->platzhalter(),
        bezug: $r,
    )),
    fn (Empfaenger $person, Versandkampagne $kampagne) => new Rundmail($person, $kampagne),
);
```

Der Dienst legt die Kampagnenzeile an, **bevor** er einreiht — damit jede Mail ihre
Nummer mitnehmen kann. Sonst ließe sich im Protokoll nicht sagen, zu welchem Versand
eine Zeile gehört, und genau danach sucht hinterher jemand.

Eingereiht wird gestaffelt über den `Versandplan`. Der merkt sich je **Postausgang**,
bis wann belegt ist, und die nächste Kampagne setzt dort an. Der Schlüssel hängt bewusst
am Mailserver und nicht am Bereich: Das Limit gehört dem Server. Zwei Bereiche, die sich
einen teilen, teilen sich das Kontingent — zwei mit eigenen Servern stehen sich nicht im
Weg.

Empfänger ohne Adresse werden übersprungen und nicht mitgezählt. Eine leere Adresse ist
kein Fehler, sondern ein Alltagsfall.

### Die Mail übernimmt die Metadaten aus der Kampagne

```php
public function envelope(): Envelope
{
    return new Envelope(
        subject: $this->kampagne->betreff,
        metadata: $this->kampagne->metadaten($this->person),
    );
}
```

`metadaten()` liegt im Paket, weil dieser Code in jeder Anwendung gleich aussehen würde
— und weil er leise falsch wird, wenn ihn jemand von Hand schreibt: Eine vergessene
Kampagnennummer löst keinen Fehler aus, sie löst nur eine Auswertung auf, die später
jemand vermisst.

### Warum Empfängerliste und Mailable von außen kommen

Wer die Empfänger sind, weiß nur die Anwendung — in Connect Anmeldungen nach Status oder
Gruppe, im CRM Leads in einer Phase. Und wie die Mail aussieht, weiß das Paket erst
recht nicht. Dafür je einen Vertrag zu bauen hieße, eine Abfrage durch eine Schnittstelle
zu reichen, die nichts damit anfangen kann.

## Nur die Drosselung benutzen

Wer die Kampagnenzeile nicht braucht, kann den `Versandplan` direkt nehmen:

```php
$start = $plan->reservieren($event, $empfaenger->count());
$proStunde = $plan->proStunde($event);   // einmal holen, nicht je Empfänger —
                                          // sonst läuft pro Mail eine Abfrage
                                          // über die ganze Kaskade
```

## Auswertung einer Kampagne

Die Zahlen, mit denen sich ein Versand beurteilen lässt, ohne 2500 Zeilen zu scrollen:

```php
return Inertia::render('Kampagnen/Show', [
    'campaign' => $kampagne->only(['id', 'betreff', 'empfaenger_anzahl', 'gestartet_am']),
    ...app(Kampagnenauswertung::class)->fuer($kampagne),
]);
```

Vier Schlüssel kommen zurück: `summary` (unser Stand), `zustellung` (was der Anbieter
sagt), `auswertung` (die Bilanz) und `versuche` (woran Sendeversuche scheiterten).

Drei Trennungen darin sind der eigentliche Inhalt, und jede hat einen Anlass:

- **`status` und `zustellung_status` sind zwei Fragen.** „Verschickt" heißt angenommen,
  „zugestellt" heißt angekommen. Die Lücke dazwischen ist die Zahl, nach der jemand sucht,
  der eine Beschwerde bearbeitet.
- **Dauerhafte und vorübergehende Rückläufer werden nie addiert.** Am 04.09.2026 meldete
  Mailgun 114 Fehlschläge, von denen 96 bloßes Graylisting waren — zusammengezählt sah ein
  gesunder Versand kaputt aus.
- **Sendeversuche sind kein Zustellproblem.** Ein gescheiterter Versuch liegt zwischen dir
  und deinem Postausgang, eine gescheiterte Zustellung zwischen Postausgang und Empfänger.

`zustellung['verbunden']` sagt, ob überhaupt gemessen wurde. Ist es `false`, soll die
Oberfläche die Spalte weglassen statt „0 zugestellt" zu behaupten.

Öffnungen und Klicks fehlen bewusst. Eine „Öffnung" ist ein geladenes Zählbild — neben
einer Zustellquote gleichrangig gezeigt, lädt sie zu Schlüssen ein, die die Zahl nicht
hergibt.

## Oberflächen

Drei fertige React-Vorlagen (shadcn/ui, Inertia) liegen im Paket:

```bash
php artisan mass-mailer:install --react
# oder später
php artisan vendor:publish --tag=mass-mailer-react
```

| Datei | Wofür |
|---|---|
| `mass-mailer-postausgang-form.tsx` | Server, Zugangsdaten und Absender einer Ebene |
| `mass-mailer-protokoll.tsx` | Was an wen rausging und was daraus wurde |
| `mass-mailer-auswertung.tsx` | Die Bilanz eines Versands |

**Vorlagen, keine Bausteine.** Sie werden in `resources/js/components/` kopiert und
gehören danach dir — Layout, Routen und Benennung sind in jeder Anwendung anders. Ein
Paket-Formular, das sich beim nächsten `composer update` unter der Hand anders verhält,
wäre in einer Maske, die jemand täglich benutzt, das Gegenteil von hilfreich.

Zwei Stellen sind ausdrücklich zum Ausfüllen gedacht:

- `bezugLabel` am Protokoll — das Paket weiß nicht, wie eine Anmeldung, ein Lead oder ein
  Kundenkontakt bei dir heißt. Ohne diese Funktion zeigt die Tabelle den rohen Typ.
- Das leere Passwortfeld im Postausgang heißt **„nicht ändern"**. Dein Endpunkt muss das
  so behandeln — sonst müsste man das Passwort bei jeder Korrektur am Port neu eintippen,
  und irgendwann tippt jemand daneben.

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
