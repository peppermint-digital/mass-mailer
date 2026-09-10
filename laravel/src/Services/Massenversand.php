<?php

namespace Peppermint\MassMailer\Services;

use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Peppermint\MassMailer\Models\Versandkampagne;
use Peppermint\MassMailer\Support\Empfaenger;
use Peppermint\MassMailer\Support\Kampagnenentwurf;

/**
 * Verschickt eine Mail an viele — gedrosselt und nachvollziehbar.
 *
 * Zwei Dinge macht dieser Dienst, und beide waeren beim Aufrufer leicht
 * vergessen:
 *
 * 1. **Die Kampagnenzeile entsteht VOR dem Einreihen**, damit jede einzelne
 *    Mail ihre Nummer mitnehmen kann. Sonst liesse sich im Protokoll nicht
 *    sagen, zu welchem Versand eine Zeile gehoert — und genau danach sucht
 *    hinterher jemand.
 * 2. **Eingereiht wird gestaffelt**, ueber den {@see Versandplan}. 500
 *    Empfaenger auf einen Schlag heisst, dass der Worker sie so schnell
 *    hinausschickt, wie er kann — und ein geteiltes Postfach lehnt das ab einer
 *    bestimmten Menge ab. Was abgelehnt wird, landet als fehlgeschlagener Job,
 *    wo es niemand sucht.
 *
 * ## Warum die Empfaengerliste von aussen kommt
 *
 * Wer die Empfaenger sind, weiss nur die Anwendung: In Connect sind es
 * Anmeldungen nach Status oder Gruppe, im CRM Leads in einer Phase. Dafuer
 * einen Vertrag zu bauen hiesse, eine Abfrage durch eine Schnittstelle zu
 * reichen, die nichts damit anfangen kann — der Aufrufer uebergibt sie einfach.
 *
 * ## Warum das Mailable von aussen kommt
 *
 * Aus demselben Grund. Das Paket weiss nicht, wie die Mail der Anwendung
 * aussieht. Es gibt dem Erbauer die Kampagne mit, damit er die Metadaten daraus
 * uebernehmen kann ({@see Versandkampagne::metadaten()}).
 */
class Massenversand
{
    public function __construct(private readonly Versandplan $plan) {}

    /**
     * @param  iterable<Empfaenger>  $empfaenger
     * @param  Closure(Empfaenger, Versandkampagne): Mailable  $mailBauen
     */
    public function verschicken(
        Kampagnenentwurf $entwurf,
        iterable $empfaenger,
        Closure $mailBauen,
    ): Versandkampagne {
        // Erst einsammeln, dann reservieren: Der Versandplan braucht die
        // ANZAHL, um den Zeitraum zu belegen. Bei einem Generator waere sie
        // vorher nicht bekannt — und eine Reservierung „auf Verdacht" wuerde
        // entweder zu viel belegen (die naechste Kampagne wartet grundlos) oder
        // zu wenig (dann ueberholen sie sich doch).
        $liste = $this->erreichbare($empfaenger);

        $kampagne = $this->kampagneAnlegen($entwurf, count($liste));

        $start = $this->plan->reservieren($entwurf->bereich, count($liste));
        $proStunde = $this->plan->proStunde($entwurf->bereich);

        foreach ($liste as $position => $person) {
            Mail::to($person->adresse)->later(
                $start->addSeconds($this->plan->versatzInSekunden($position, $proStunde)),
                $mailBauen($person, $kampagne),
            );
        }

        return $kampagne;
    }

    /**
     * Nur die mit einer Adresse — und als Liste mit luckenlosen Nummern.
     *
     * Eine leere Adresse ist kein Fehler, sondern ein Alltagsfall: ein Kontakt
     * ohne hinterlegte Mail. Ihn zu ueberspringen ist richtig; abzubrechen
     * wuerde die uebrigen 2500 Mails mitnehmen.
     *
     * `array_values` ist hier nicht Kosmetik: Die Position geht als Versatz in
     * die Staffelung ein. Bliebe eine Luecke, entstuende an dieser Stelle eine
     * Pause im Versand — der Plan haette den Platz reserviert, aber niemand
     * nutzte ihn.
     *
     * @param  iterable<Empfaenger>  $empfaenger
     * @return array<int, Empfaenger>
     */
    private function erreichbare(iterable $empfaenger): array
    {
        $liste = [];

        foreach ($empfaenger as $person) {
            if ($person->erreichbar()) {
                $liste[] = $person;
            }
        }

        return $liste;
    }

    private function kampagneAnlegen(Kampagnenentwurf $entwurf, int $anzahl): Versandkampagne
    {
        return Versandkampagne::create([
            'mandant_id' => $entwurf->mandantId ?? $this->mandantAusBereich($entwurf),
            'bereich_typ' => $entwurf->bereich?->getMorphClass(),
            'bereich_id' => $entwurf->bereich?->getKey(),
            'vorlage_id' => $entwurf->vorlageId,
            'ausgeloest_von_id' => $entwurf->ausgeloestVonId,
            'betreff' => $entwurf->betreff,
            'rumpf' => $entwurf->rumpf,
            'filter' => $entwurf->filter,
            'empfaenger_anzahl' => $anzahl,
            'gestartet_am' => now(),
        ]);
    }

    /**
     * Der Mandant, wenn ihn der Entwurf nicht nennt.
     *
     * Ueber einen konfigurierbaren Spaltennamen am Bereich, nicht ueber eine
     * feste Annahme: In Connect heisst das Feld `organization_id`, anderswo
     * anders. Fehlt es, bleibt der Mandant leer — eine Kampagne ohne Mandant
     * ist besser als eine mit dem falschen.
     */
    private function mandantAusBereich(Kampagnenentwurf $entwurf): ?int
    {
        if ($entwurf->bereich === null) {
            return null;
        }

        $spalte = (string) config('mass-mailer.mandant.spalte', 'organization_id');

        $wert = $entwurf->bereich->getAttribute($spalte);

        return $wert === null ? null : (int) $wert;
    }
}
