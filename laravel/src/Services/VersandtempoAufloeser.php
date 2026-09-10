<?php

namespace Peppermint\MassMailer\Services;

use Illuminate\Database\Eloquent\Model;
use Peppermint\MassMailer\Contracts\Kaskade;

/**
 * Beantwortet die Frage des Massenversands: „Wie viele Mails darf ich je Stunde
 * einreihen?"
 *
 * Dieselbe {@see Kaskade} wie beim Postausgang, damit sich niemand eine zweite
 * Kette merken muss. Antwortet keine Ebene, gilt
 * `config('mass-mailer.tempo.pro_stunde')`.
 *
 * Warum ueberhaupt gedrosselt wird: 500 Empfaenger auf einmal einzureihen
 * heisst, dass der Worker sie so schnell hinausschickt, wie er kann. Geteilte
 * Postfaecher lehnen das ab einer bestimmten Menge ab — und was abgelehnt wird,
 * landet als fehlgeschlagener Job, wo es niemand sucht. **Die Drosselung
 * verlaengert den Versand, damit er vollstaendig bleibt.**
 *
 * ## Warum je Stunde und nicht je Minute
 *
 * Weil die Grenze der Anbieter je Stunde gilt (Mailgun drosselt neue Konten auf
 * 100/Stunde). Stand die Einstellung in Minuten, musste jeder den Wert aus dem
 * Vertrag erst umrechnen — und ein Tippfehler um den Faktor 60 sieht im Feld
 * aus wie eine gewoehnliche Zahl.
 */
class VersandtempoAufloeser
{
    /**
     * Wenn nirgends etwas Sinnvolles steht. Siehe {@see self::mindestens()}.
     *
     * 60/Stunde und nicht mehr: Das ist der Wert, der in Connect am 01.09.2026
     * ueber einen ganzen Tag nachweislich durchlief, und er laesst unter einer
     * 100er-Grenze noch Luft fuer die Mails, die nebenher entstehen —
     * Bestaetigungen und Rechnungen zaehlen beim Anbieter mit.
     */
    public const NOTNAGEL = 60;

    public function __construct(private readonly Kaskade $kaskade) {}

    public function fuer(?Model $bereich): int
    {
        foreach ($this->kaskade->ebenen($bereich) as $ebene) {
            $wert = $this->anEbene($ebene);

            if ($wert !== null) {
                return $this->mindestens($wert);
            }
        }

        return $this->ausKonfiguration();
    }

    /**
     * Das Tempo, das an dieser Ebene hinterlegt ist — oder `null`.
     *
     * Ueber `getAttribute` und einen konfigurierbaren Spaltennamen, statt eine
     * eigene Tabelle mitzubringen: Das Tempo ist eine Eigenschaft der Ebene,
     * und die gehoert dem Host. Fehlt die Spalte an einem Modell, kommt `null`
     * zurueck und die naechste Stufe uebernimmt — kein Fehler, denn nicht jede
     * Ebene muss das Tempo ueberhaupt kennen.
     */
    public function anEbene(Model $ebene): ?int
    {
        // Ein leerer Eintrag darf nicht in `getAttribute('')` muenden — das
        // gaebe still `null` und damit fuer jede Ebene „kein Tempo hinterlegt".
        $spalte = config('mass-mailer.tempo.spalte');
        $spalte = is_string($spalte) && $spalte !== '' ? $spalte : 'mail_rate_per_hour';

        $wert = $ebene->getAttribute($spalte);

        return $wert === null ? null : (int) $wert;
    }

    public function ausKonfiguration(): int
    {
        return $this->mindestens((int) config('mass-mailer.tempo.pro_stunde'));
    }

    /**
     * Der Versatz der Mail Nummer `$position` (ab 0) in Sekunden.
     *
     * Gleichmaessig verteilt und nicht in Bloecken: Bei 60/Stunde geht eine Mail
     * je Minute raus, bei 80 eine alle 45 Sekunden.
     *
     * Der frueher benutzte Block-Versatz („je angefangenes Kontingent eine
     * Minute") ist gegen eine Stundengrenze das falsche Muster. Er schickt das
     * ganze Kontingent in der ersten Sekunde des Zeitraums hinaus und wartet
     * dann — bei einem Stundenlimit heisst das: die Grenze ist nach wenigen
     * Sekunden erreicht, und der Rest der Stunde laeuft gegen eine Sperre.
     */
    public function versatzInSekunden(int $position, int $proStunde): int
    {
        return (int) round($position * 3600 / $this->mindestens($proStunde));
    }

    /**
     * Eine Rate von 0 oder weniger hiesse „nie versenden": eine Division durch 0
     * bricht ab, und ein negativer Wert schoebe die Mails in die Vergangenheit.
     * Beides ist als Einstellung nicht gemeint — hier stuende dann jemand vor
     * einem Versand, der gar nicht erst losgeht, ohne Fehlermeldung.
     */
    private function mindestens(?int $wert): int
    {
        return $wert !== null && $wert > 0 ? $wert : self::NOTNAGEL;
    }
}
