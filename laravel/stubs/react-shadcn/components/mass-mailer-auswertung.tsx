import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * Die Bilanz eines Versands — wie er gelaufen ist, ohne die Liste durchzugehen.
 *
 * Die Props sind genau das, was `Kampagnenauswertung::fuer($kampagne)`
 * zurückgibt. Reich das Ergebnis unverändert durch:
 *
 * ```php
 * return Inertia::render('…', app(Kampagnenauswertung::class)->fuer($kampagne));
 * ```
 *
 * Was hier bewusst NICHT steht: Öffnungen und Klicks. Eine „Öffnung" ist ein
 * geladenes Zählbild — neben einer Zustellquote gleichrangig gezeigt, lüden sie
 * zu Schlüssen ein, die die Zahl nicht hergibt.
 */
export interface Auswertung {
    verschickt: number;
    zugestellt: number;
    /** `null`, solange nichts verschickt ist — nicht 0 %. */
    zustellquote: number | null;
    ruecklaeufer_dauerhaft: number;
    ruecklaeufer_voruebergehend: number;
    graylisting: number;
    graylisting_zugestellt: number;
    versuche_gesamt: number;
    mit_wiederholung: number;
    laufzeit_minuten: number | null;
    rate_je_stunde: number | null;
    haeufigste_gruende: { grund: string; anzahl: number }[];
}

export interface Versuchsbilanz {
    gesamt: number;
    gescheitert: number;
    /** Ohne Ergebnis = abgebrochen, nicht gescheitert. */
    abgebrochen: number;
    gruende: { grund: string; anzahl: number }[];
}

interface Props {
    auswertung: Auswertung;
    versuche: Versuchsbilanz;
}

export default function MassMailerAuswertung({ auswertung, versuche }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Auswertung</CardTitle>
                <CardDescription>Wie dieser Versand gelaufen ist — ohne die Liste durchzugehen.</CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Kennzahl
                        label="Zustellquote"
                        wert={auswertung.zustellquote !== null ? `${auswertung.zustellquote} %` : '—'}
                        hinweis={`${auswertung.zugestellt} von ${auswertung.verschickt} verschickten`}
                    />

                    {/*
                        Getrennt und nicht als eine Summe: „dauerhaft" heißt, die Adresse ist
                        tot und wird gesperrt; „vorübergehend" heißt, der Server war
                        beschäftigt und es läuft weiter. Wer beides zusammenzählt, hält einen
                        gesunden Versand für kaputt.
                    */}
                    <Kennzahl
                        label="Rückläufer dauerhaft"
                        wert={auswertung.ruecklaeufer_dauerhaft.toString()}
                        hinweis="Adresse gesperrt"
                    />
                    <Kennzahl
                        label="Vorübergehend"
                        wert={auswertung.ruecklaeufer_voruebergehend.toString()}
                        hinweis="löst sich meist von selbst"
                    />
                    <Kennzahl
                        label="Tempo"
                        wert={auswertung.rate_je_stunde !== null ? `${auswertung.rate_je_stunde}/h` : '—'}
                        hinweis={
                            auswertung.laufzeit_minuten !== null
                                ? `seit ${Math.round(auswertung.laufzeit_minuten / 60)} Stunden`
                                : ''
                        }
                    />
                </div>

                {auswertung.mit_wiederholung > 0 && (
                    <p className="text-muted-foreground text-sm">
                        <strong className="text-foreground">{auswertung.mit_wiederholung}</strong>{' '}
                        {auswertung.mit_wiederholung === 1 ? 'Mail brauchte' : 'Mails brauchten'} mehr als einen
                        Anlauf — {auswertung.versuche_gesamt} Sendeversuche insgesamt. Wiederholungen sind normal;
                        sie bedeuten nicht, dass etwas verloren ging.
                    </p>
                )}

                {/*
                    Graylisting: Der Empfängerserver lehnt einen unbekannten Absender beim
                    ersten Mal ab und nimmt ihn beim zweiten Mal an. Am Ende steht
                    „zugestellt", beim Anbieter steht ein Fehlschlag — beide Zahlen stimmen,
                    und ohne diese Zeile widersprechen sie sich scheinbar.
                */}
                {auswertung.graylisting > 0 && (
                    <div className="flex flex-col gap-1 border-t pt-4">
                        <span className="text-muted-foreground text-sm">
                            {auswertung.graylisting} Empfänger haben die Mail erst im zweiten Anlauf angenommen
                            {auswertung.graylisting_zugestellt === auswertung.graylisting
                                ? ' — alle davon erfolgreich zugestellt.'
                                : ` — davon ${auswertung.graylisting_zugestellt} zugestellt.`}
                        </span>
                        <p className="text-muted-foreground text-xs">
                            Übliches Verhalten von Spamfiltern (Graylisting). Der Versanddienst zählt diese als
                            Fehlschlag — deshalb kann seine Statistik mehr Fehler zeigen als diese Seite, ohne dass
                            eine Mail verloren ging.
                        </p>
                    </div>
                )}

                {/*
                    Die Sendeversuche sind eine ANDERE Frage als die Zustellung: Ein
                    gescheiterter Versuch ist ein Problem zwischen uns und dem Postausgang,
                    eine gescheiterte Zustellung eines zwischen Postausgang und Empfänger.
                    Beides in einen Topf zu werfen hieße, einen Serverfehler bei uns als tote
                    Adresse auszuweisen.
                */}
                {versuche.gesamt > auswertung.verschickt && (
                    <div className="flex flex-col gap-1 border-t pt-4">
                        <span className="text-muted-foreground text-sm">
                            Sendeversuche: {versuche.gesamt} für {auswertung.verschickt} Mails
                            {versuche.gescheitert > 0 && ` · ${versuche.gescheitert} gescheitert und wiederholt`}
                            {versuche.abgebrochen > 0 && ` · ${versuche.abgebrochen} abgebrochen`}
                        </span>
                        <Gruende eintraege={versuche.gruende} />
                    </div>
                )}

                {auswertung.haeufigste_gruende.length > 0 && (
                    <div className="flex flex-col gap-1 border-t pt-4">
                        <span className="text-muted-foreground text-sm">Häufigste Meldungen:</span>
                        <Gruende eintraege={auswertung.haeufigste_gruende} />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function Kennzahl({ label, wert, hinweis }: { label: string; wert: string; hinweis?: string }) {
    return (
        <div className="flex flex-col gap-0.5 rounded-lg border p-3">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className="text-2xl font-semibold">{wert}</span>
            {hinweis && <span className="text-muted-foreground text-xs">{hinweis}</span>}
        </div>
    );
}

function Gruende({ eintraege }: { eintraege: { grund: string; anzahl: number }[] }) {
    return (
        <>
            {eintraege.map((g) => (
                <div key={g.grund} className="text-muted-foreground flex gap-2 text-xs">
                    <span className="text-foreground font-medium">{g.anzahl}×</span>
                    <span className="break-words">{g.grund}</span>
                </div>
            ))}
        </>
    );
}
