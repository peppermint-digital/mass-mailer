<?php

use Peppermint\MassMailer\Support\Zustellfehler;

/**
 * Die wichtigste Unterscheidung im ganzen Thema — und die einzige, bei der ein
 * Fehler einen Menschen dauerhaft von der Post abschneidet.
 */
it('sperrt bei Meldungen, die sich nicht mehr aendern', function (string $meldung) {
    expect(Zustellfehler::ausMeldung($meldung))->toBeTrue();
})->with([
    '550 5.1.1 user unknown',
    'Recipient not found',
    'mailbox unavailable',
    'domain not found',
    // Der Code allein genuegt, auch ohne Stichwort aus der Liste.
    'SMTP-Antwort 554 abgelehnt',
]);

it('sperrt nicht, wenn es morgen wieder gehen kann', function (string $meldung) {
    expect(Zustellfehler::ausMeldung($meldung))->toBeFalse();
})->with([
    '452 mailbox full',
    'over quota',
    'greylisted, try again later',
    'connection timeout',
    'rate limit exceeded',
]);

/**
 * Der Fall, fuer den die Stichwortliste ueberhaupt existiert: Manche Server
 * melden ein volles Postfach mit einem 5xx-Code. Formal falsch, in der Praxis
 * haeufig — und wer hier dem Code folgt, sperrt eine gesunde Adresse.
 */
it('laesst das Stichwort gegen einen falschen 5xx-Code gewinnen', function () {
    expect(Zustellfehler::ausMeldung('550 5.2.2 mailbox full'))->toBeFalse();
});

/**
 * Im Zweifel nicht sperren: Eine kaputte Adresse faellt beim naechsten Versand
 * wieder auf, eine faelschlich gesperrte nie.
 */
it('sperrt nicht bei einer Meldung, die es nicht einordnen kann', function () {
    expect(Zustellfehler::ausMeldung('Etwas ist schiefgelaufen'))->toBeFalse();
});

/**
 * Die Zahlensuche darf nur echte SMTP-Codes treffen. Ohne Wortgrenzen wuerde
 * eine Nachrichtengroesse von 1550000 Byte als „550" gelesen.
 */
it('haelt eine beliebige Zahl nicht fuer einen SMTP-Code', function () {
    expect(Zustellfehler::ausMeldung('Nachricht zu gross: 1550000 Byte'))->toBeFalse();
});

it('entscheidet fuer eine Ausnahme genauso wie fuer ihren Text', function () {
    expect(Zustellfehler::istDauerhaft(new RuntimeException('550 user unknown')))->toBeTrue()
        ->and(Zustellfehler::istDauerhaft(new RuntimeException('451 try again')))->toBeFalse();
});
