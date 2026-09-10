<?php

namespace Peppermint\MassMailer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Peppermint\MassMailer\Models\MailDispatch;

/**
 * Die zwei Endpunkte, die eine Messung entgegennehmen.
 *
 * Beide haengen an signierten Adressen: ohne Signatur liesse sich die Statistik
 * von aussen mit erfundenen Oeffnungen fuellen, indem jemand die laufenden
 * Nummern durchprobiert.
 *
 * Beide antworten IMMER, auch wenn die Zeile nicht existiert oder das Zaehlen
 * scheitert. Ein Empfaenger, der auf einen Link klickt, darf nie eine
 * Fehlerseite sehen, nur weil die Buchfuehrung dahinter hakt — dieselbe Regel
 * wie beim Protokoll selbst.
 */
class MessungController extends Controller
{
    /** Ein 1×1-Pixel, transparent. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function oeffnung(MailDispatch $dispatch): Response
    {
        // Nur die erste Oeffnung setzt den Zeitstempel; der Zaehler laeuft
        // weiter. „Wann zuerst" und „wie oft" sind zwei verschiedene Fragen,
        // und die zweite verraet nebenbei den Unterschied zwischen einem
        // Menschen und einem Sicherheits-Scanner, der die Mail durchklickt.
        $dispatch->forceFill([
            'geoeffnet_am' => $dispatch->geoeffnet_am ?? now(),
            'oeffnungen' => $dispatch->oeffnungen + 1,
        ])->saveQuietly();

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            // Ohne das liefert ein Postfach-Proxy das Bild aus seinem
            // Zwischenspeicher, und jede weitere Oeffnung bliebe ungezaehlt.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function klick(Request $request, MailDispatch $dispatch): RedirectResponse
    {
        $ziel = (string) $request->query('ziel', '');

        $dispatch->forceFill([
            'geklickt_am' => $dispatch->geklickt_am ?? now(),
            'klicks' => $dispatch->klicks + 1,
            // Wer klickt, hat die Mail zwangslaeufig geoeffnet — auch wenn der
            // Pixel nie geladen wurde, weil das Programm Bilder blockiert. Ohne
            // diese Zeile stuende „nie geoeffnet, aber geklickt" in der Akte,
            // und das liest sich wie ein Fehler.
            'geoeffnet_am' => $dispatch->geoeffnet_am ?? now(),
        ])->saveQuietly();

        // Nur http(s) und nur absolute Adressen: eine offene Weiterleitung
        // waere sonst genau das — ein Link mit unserer Domain, der irgendwohin
        // fuehrt. Im Zweifel zurueck auf die Startseite.
        $erlaubt = filter_var($ziel, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($ziel, PHP_URL_SCHEME), ['http', 'https'], true);

        return redirect()->away($erlaubt ? $ziel : url('/'));
    }
}
