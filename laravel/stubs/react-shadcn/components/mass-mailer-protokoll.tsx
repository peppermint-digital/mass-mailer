import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ReactNode } from 'react';

/**
 * Das Versandprotokoll — was an wen rausging und was daraus wurde.
 *
 * Aus zwei echten Fassungen destilliert (Peppermint Connect und CRM). Die
 * Felder entsprechen den Spalten des Pakets; verändere sie nur, wenn du das
 * Schema veränderst.
 *
 * ## Die eine Stelle, die deine Anwendung ausfüllt
 *
 * `bezugLabel` — das Paket weiß nicht, wie eine Anmeldung, ein Lead oder ein
 * Kundenkontakt heißt. Ohne diese Funktion zeigt die Tabelle den rohen Typ.
 */
export interface Versandeintrag {
    id: number;
    empfaenger: string;
    art: string | null;
    betreff: string | null;
    status: 'im_versand' | 'verschickt' | 'fehlgeschlagen';
    fehler: string | null;
    verschickt_am: string | null;
    fehlgeschlagen_am: string | null;
    versuche: number;

    /** Was der Anbieter sagt. `null` heißt „nicht gemessen", nicht „nicht angekommen". */
    zustellung_status: 'zugestellt' | 'verzoegert' | 'unzustellbar' | 'abgewiesen' | 'beschwerde' | null;
    zustellung_grund: string | null;
    zustellung_wiederholt: boolean;

    bezug_typ: string | null;
    bezug_id: number | null;
}

interface Props {
    eintraege: Versandeintrag[];
    /** Wie der Bezug in DEINER Anwendung heißt — sonst steht dort der rohe Typ. */
    bezugLabel?: (eintrag: Versandeintrag) => ReactNode;
    /** Wie die Mail-Art in Worten heißt. Ohne die Funktion steht der Schlüssel da. */
    artLabel?: (art: string | null) => string;
    titel?: string;
}

const STATUS_TEXT: Record<Versandeintrag['status'], string> = {
    im_versand: 'Im Versand',
    verschickt: 'Verschickt',
    fehlgeschlagen: 'Fehlgeschlagen',
};

const ZUSTELLUNG_TEXT: Record<string, string> = {
    zugestellt: 'Zugestellt',
    verzoegert: 'Verzögert',
    unzustellbar: 'Unzustellbar',
    abgewiesen: 'Abgewiesen',
    beschwerde: 'Als Werbung gemeldet',
};

export default function MassMailerProtokoll({ eintraege, bezugLabel, artLabel, titel = 'Versandprotokoll' }: Props) {
    if (eintraege.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{titel}</CardTitle>
                </CardHeader>
                <CardContent className="text-muted-foreground py-6 text-center text-sm">
                    Noch keine Mail verschickt.
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{titel}</CardTitle>
                <CardDescription>
                    {/*
                        „Verschickt" heißt: Der Postausgang hat die Mail angenommen — nicht,
                        dass sie angekommen ist. Das gehört dazugesagt, sonst liest man die
                        Spalte als Zustellung.
                    */}
                    „Verschickt" heißt angenommen, nicht zugestellt. Was der Anbieter sagt, steht daneben.
                </CardDescription>
            </CardHeader>

            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Empfänger</TableHead>
                            <TableHead>Art</TableHead>
                            <TableHead>Stand</TableHead>
                            <TableHead>Zustellung</TableHead>
                            <TableHead>Zeitpunkt</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {eintraege.map((eintrag) => (
                            <TableRow key={eintrag.id}>
                                <TableCell>
                                    <div className="font-medium">{eintrag.empfaenger}</div>
                                    {bezugLabel && (
                                        <div className="text-muted-foreground text-xs">{bezugLabel(eintrag)}</div>
                                    )}
                                    {eintrag.betreff && (
                                        <div className="text-muted-foreground text-xs">{eintrag.betreff}</div>
                                    )}
                                </TableCell>

                                <TableCell className="text-muted-foreground">
                                    {artLabel ? artLabel(eintrag.art) : (eintrag.art ?? 'Unbekannt')}
                                </TableCell>

                                <TableCell>
                                    <Badge
                                        variant={
                                            eintrag.status === 'fehlgeschlagen'
                                                ? 'destructive'
                                                : eintrag.status === 'im_versand'
                                                  ? 'secondary'
                                                  : 'default'
                                        }
                                    >
                                        {STATUS_TEXT[eintrag.status]}
                                    </Badge>

                                    {/*
                                        Mehr als ein Anlauf ist eine Information, kein Makel —
                                        „hat beim zweiten Mal geklappt" sieht sonst aus wie
                                        „ging sofort raus".
                                    */}
                                    {eintrag.versuche > 1 && (
                                        <span className="text-muted-foreground ml-2 text-xs">
                                            {eintrag.versuche} Versuche
                                        </span>
                                    )}

                                    {eintrag.fehler && (
                                        <div className="text-destructive mt-1 text-xs">{eintrag.fehler}</div>
                                    )}
                                </TableCell>

                                <TableCell>
                                    {eintrag.zustellung_status === null ? (
                                        // Der wichtigste Unterschied der ganzen Tabelle: leer
                                        // heißt „nicht gemessen", nicht „nicht angekommen".
                                        <span className="text-muted-foreground text-xs">nicht gemessen</span>
                                    ) : (
                                        <>
                                            <span className="text-sm">
                                                {ZUSTELLUNG_TEXT[eintrag.zustellung_status] ?? eintrag.zustellung_status}
                                            </span>
                                            {eintrag.zustellung_wiederholt && (
                                                <div className="text-muted-foreground text-xs">
                                                    nach Wiederholung
                                                </div>
                                            )}
                                            {eintrag.zustellung_grund && (
                                                <div className="text-muted-foreground text-xs">
                                                    {eintrag.zustellung_grund}
                                                </div>
                                            )}
                                        </>
                                    )}
                                </TableCell>

                                <TableCell className="text-muted-foreground text-sm whitespace-nowrap">
                                    {eintrag.verschickt_am ?? eintrag.fehlgeschlagen_am ?? '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}
