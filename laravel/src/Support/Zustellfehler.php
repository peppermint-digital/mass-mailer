<?php

namespace Peppermint\MassMailer\Support;

use Throwable;

/**
 * Ist dieser Zustellfehler dauerhaft — oder geht es morgen wieder? (#4726)
 *
 * **Die wichtigste Unterscheidung im ganzen Rueckläufer-Thema.** Ein volles
 * Postfach, ein Server in Wartung, eine Graylisting-Verzoegerung: alles
 * voruebergehend. Wer daraufhin die Adresse sperrt, nimmt einem Teilnehmer
 * dauerhaft die Post, weil sein Postfach an einem Dienstagnachmittag voll war
 * — und niemand merkt es, denn Stille sieht aus wie Zustellung.
 *
 * Umgekehrt: „user unknown" wird auch beim zehnten Versuch nicht besser. Diese
 * Adresse weiter anzuschreiben schadet der Absender-Reputation, und genau
 * daran haengt, ob die uebrigen Mails im Posteingang landen.
 *
 * ## Woran es sich erkennen laesst
 *
 * SMTP kennt die Trennung selbst: **5xx ist endgueltig, 4xx ist vorlaeufig.**
 * Das ist die verlaesslichste Quelle — verlaesslicher als jede Stichwortliste,
 * denn den Code schickt der empfangende Server.
 *
 * Die Stichwoerter darunter sind der Rueckfall fuer Meldungen ohne Code. Sie
 * sind bewusst KURZ gehalten: Eine lange Liste trifft irgendwann das Falsche,
 * und im Zweifel ist „nicht sperren" die harmlosere Antwort — eine kaputte
 * Adresse faellt beim naechsten Versand wieder auf, eine faelschlich gesperrte
 * nie.
 */
class Zustellfehler
{
    /**
     * Meldungen, die eine Adresse endgueltig unbrauchbar machen.
     *
     * @var array<int, string>
     */
    private const ENDGUELTIG = [
        'user unknown',
        'no such user',
        'unknown user',
        'recipient not found',
        'address rejected',
        'does not exist',
        'mailbox unavailable',
        'invalid recipient',
        'no mailbox',
        'unrouteable address',
        'domain not found',
        'does not comply with addr-spec',
    ];

    /**
     * Meldungen, die ausdruecklich NICHT sperren duerfen.
     *
     * Sie stehen hier, obwohl 4xx sie ohnehin abfangen sollte: Manche Server
     * melden ein volles Postfach mit einem 5xx-Code, was formal falsch und in
     * der Praxis haeufig ist. Diese Liste gewinnt gegen den Code.
     *
     * @var array<int, string>
     */
    private const VORUEBERGEHEND = [
        'mailbox full',
        'over quota',
        'quota exceeded',
        'insufficient storage',
        'try again',
        'temporarily',
        'temporary',
        'greylist',
        'rate limit',
        'too many',
        'timeout',
        'connection',
    ];

    public static function istDauerhaft(Throwable $fehler): bool
    {
        return self::ausMeldung($fehler->getMessage());
    }

    /**
     * Dieselbe Entscheidung fuer eine reine Textmeldung.
     *
     * Getrennt, weil die Rueckmeldungen des Anbieters (Webhooks) keine
     * Ausnahme mitbringen, sondern nur Text — und dieselbe Regel gelten muss.
     */
    public static function ausMeldung(string $meldung): bool
    {
        $text = mb_strtolower($meldung);

        // Zuerst: Was voruebergehend ist, bleibt es — auch wenn der Server
        // faelschlich einen 5xx-Code danebenstellt.
        foreach (self::VORUEBERGEHEND as $muster) {
            if (str_contains($text, $muster)) {
                return false;
            }
        }

        foreach (self::ENDGUELTIG as $muster) {
            if (str_contains($text, $muster)) {
                return true;
            }
        }

        // Der SMTP-Code als verlaesslichste Quelle: 5xx endgueltig, 4xx
        // vorlaeufig. Gesucht wird ein dreistelliger Code am Wortanfang —
        // sonst traefe die Suche jede Zahl in der Meldung, etwa eine
        // Nachrichtengroesse oder eine Zeilennummer.
        if (preg_match('/\b5\d{2}\b/', $text) === 1) {
            return true;
        }

        // Alles Uebrige gilt als voruebergehend. Im Zweifel nicht sperren: Eine
        // kaputte Adresse faellt beim naechsten Versand wieder auf, eine
        // faelschlich gesperrte nie.
        return false;
    }
}
