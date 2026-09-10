<?php

use Peppermint\MassMailer\Models\MailDispatch;
use Peppermint\MassMailer\Services\Versandprotokoll;
use Peppermint\MassMailer\Support\Metadatenschluessel;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;

/** @param  array<string, string>  $metadaten */
function nachrichtMit(array $metadaten, string $rumpf = '<html><body><a href="https://beispiel.de/ziel">Los</a></body></html>'): Email
{
    $nachricht = (new Email)
        ->to('anna@beispiel.de')
        ->subject('Einladung')
        ->html($rumpf);

    foreach ($metadaten as $schluessel => $wert) {
        $nachricht->getHeaders()->add(new MetadataHeader($schluessel, $wert));
    }

    return $nachricht;
}

/**
 * Die Voreinstellung ist AUS, und das ist keine Vorsicht ohne Grund: Der
 * Zugriff auf das Endgeraet des Empfaengers braucht eine Rechtsgrundlage, die
 * nur der Betreiber herstellen kann.
 */
it('misst nichts, solange die Mail es nicht ausdruecklich erlaubt', function () {
    $nachricht = nachrichtMit([]);

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())
        ->toContain('href="https://beispiel.de/ziel"')
        ->not->toContain('messung');
});

it('haengt den Zaehlpixel vor das schliessende body-Tag', function () {
    $nachricht = nachrichtMit([Metadatenschluessel::MESSUNG_OEFFNUNGEN => '1']);

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())
        ->toContain('/messung/oeffnung/')
        ->toMatch('#<img[^>]+/></body>#');
});

/**
 * Ohne `</body>` wird angehaengt. Eine Mail ohne diesen Abschluss ist selten,
 * aber ein verlorener Pixel waere ein stiller Ausfall der Messung.
 */
it('haengt den Pixel auch an einen Rumpf ohne body-Tag', function () {
    $nachricht = nachrichtMit([Metadatenschluessel::MESSUNG_OEFFNUNGEN => '1'], '<p>Hallo</p>');

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())->toContain('/messung/oeffnung/');
});

it('schreibt Links auf die eigene Umleitung um', function () {
    $nachricht = nachrichtMit([Metadatenschluessel::MESSUNG_KLICKS => '1']);

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())
        ->toContain('/messung/klick/')
        ->toContain('signature=')
        ->not->toContain('href="https://beispiel.de/ziel"');
});

/**
 * `mailto:` und `tel:` fuehren nirgendwohin, was sich zaehlen liesse — ueber
 * die Umleitung geschickt waeren sie schlicht kaputt.
 */
it('laesst mailto und tel in Ruhe', function () {
    $nachricht = nachrichtMit(
        [Metadatenschluessel::MESSUNG_KLICKS => '1'],
        '<html><body><a href="mailto:x@y.de">Mail</a><a href="tel:+49123">Anruf</a></body></html>',
    );

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())
        ->toContain('href="mailto:x@y.de"')
        ->toContain('href="tel:+49123"');
});

/**
 * Die Reihenfolge zaehlt: Erst die Links, dann der Pixel. Andersherum liefe der
 * Pixel selbst durch die Link-Umschreibung, und die Messung maesse sich selbst.
 */
it('misst den eigenen Zaehlpixel nicht mit', function () {
    $nachricht = nachrichtMit([
        Metadatenschluessel::MESSUNG_KLICKS => '1',
        Metadatenschluessel::MESSUNG_OEFFNUNGEN => '1',
    ]);

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect(substr_count((string) $nachricht->getHtmlBody(), '/messung/oeffnung/'))->toBe(1);
});

/**
 * Metadaten sind Kopfzeilen und damit immer Strings. Ein Aufrufer, der
 * `(string) $flag` schreibt, uebergibt bei ausgeschalteter Messung `'0'` — und
 * das darf nicht als „an" durchgehen.
 */
it('haelt die Zeichenkette 0 nicht fuer ein Ja', function () {
    $nachricht = nachrichtMit([Metadatenschluessel::MESSUNG_OEFFNUNGEN => '0']);

    app(Versandprotokoll::class)->beginnen($nachricht);

    expect($nachricht->getHtmlBody())->not->toContain('/messung/');
});

it('zaehlt eine Oeffnung und merkt sich den ersten Zeitpunkt', function () {
    $eintrag = MailDispatch::create(['empfaenger' => 'anna@beispiel.de']);

    $url = URL::signedRoute('mass-mailer.messung.oeffnung', ['dispatch' => $eintrag->id]);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get($url)->assertOk();

    $eintrag->refresh();
    $zuerst = $eintrag->geoeffnet_am;

    expect($eintrag->oeffnungen)->toBe(2)
        ->and($zuerst)->not->toBeNull();
});

it('weist eine Messung ohne gueltige Signatur ab', function () {
    $eintrag = MailDispatch::create(['empfaenger' => 'anna@beispiel.de']);

    $this->get('/mail/messung/oeffnung/'.$eintrag->id)->assertForbidden();

    expect($eintrag->refresh()->oeffnungen)->toBe(0);
});

it('leitet einen Klick zum Ziel weiter und vermerkt ihn als Oeffnung', function () {
    $eintrag = MailDispatch::create(['empfaenger' => 'anna@beispiel.de']);

    $url = URL::signedRoute('mass-mailer.messung.klick', [
        'dispatch' => $eintrag->id,
        'ziel' => 'https://beispiel.de/ziel',
    ]);

    $this->get($url)->assertRedirect('https://beispiel.de/ziel');

    $eintrag->refresh();

    expect($eintrag->klicks)->toBe(1)
        // Wer klickt, hat die Mail zwangslaeufig geoeffnet — auch wenn der
        // Pixel nie geladen wurde, weil das Programm Bilder blockiert.
        ->and($eintrag->geoeffnet_am)->not->toBeNull();
});

/**
 * Eine offene Weiterleitung waere genau das: ein Link mit unserer Domain, der
 * irgendwohin fuehrt.
 */
it('leitet nicht auf ein Ziel weiter, das keine http-Adresse ist', function (string $ziel) {
    $eintrag = MailDispatch::create(['empfaenger' => 'anna@beispiel.de']);

    $url = URL::signedRoute('mass-mailer.messung.klick', [
        'dispatch' => $eintrag->id,
        'ziel' => $ziel,
    ]);

    $this->get($url)->assertRedirect(url('/'));
})->with([
    'javascript:alert(1)',
    '//fremde-seite.de',
    'ftp://fremde-seite.de/datei',
    '',
]);
