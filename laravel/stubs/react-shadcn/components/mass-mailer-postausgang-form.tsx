import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/react';

/**
 * Der eigene Postausgang einer Ebene — Server, Zugangsdaten, Absender.
 *
 * Die Felder entsprechen den Spalten, die `MassMailerSchema::postausgangTabelle()`
 * anlegt. Sie sind vom Paket vorgegeben; hier gibt es nichts zu erfinden.
 *
 * ## Was NICHT hereinkommt
 *
 * Die Zugangsdaten. Das Modell hält sie in `$hidden` — die Maske erfährt nur,
 * DASS ein Passwort hinterlegt ist (`hatPasswort`). Reiche sie auch dann nicht
 * durch, wenn es bequemer wäre: Eine Inertia-Prop landet im Quelltext der
 * Seite.
 *
 * ## Ein leeres Passwortfeld heißt „nicht ändern"
 *
 * Sonst müsste man das Passwort bei jeder Korrektur am Port neu eintippen —
 * und irgendwann tippt jemand daneben, und der Versand steht still. Der
 * Endpunkt deiner Anwendung muss das entsprechend behandeln.
 */
export interface PostausgangWerte {
    host: string;
    port: number;
    encryption: 'tls' | 'ssl' | null;
    from_address: string | null;
    from_name: string | null;
    is_active: boolean;
    hatPasswort: boolean;
}

interface Props {
    /** Der gespeicherte Stand — `null`, wenn diese Ebene keinen eigenen hat. */
    werte: PostausgangWerte | null;
    /** Wohin gespeichert wird (PUT). */
    speichernUrl: string;
    /** Wohin gelöscht wird (DELETE) — ohne Angabe erscheint kein Entfernen-Knopf. */
    entfernenUrl?: string;
    /** Vorschlag für die Absenderadresse, wenn noch keine gespeichert ist. */
    absenderVorschlag?: string | null;
    idPrefix?: string;
}

export default function MassMailerPostausgangForm({
    werte,
    speichernUrl,
    entfernenUrl,
    absenderVorschlag = null,
    idPrefix = 'postausgang',
}: Props) {
    const form = useForm({
        host: werte?.host ?? '',
        port: werte?.port ?? 587,
        encryption: werte?.encryption ?? 'tls',
        username: '',
        // Startet immer leer — siehe Kommentar oben.
        password: '',
        from_address: werte?.from_address ?? absenderVorschlag ?? '',
        from_name: werte?.from_name ?? '',
        is_active: werte?.is_active ?? true,
    });

    const speichern = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(speichernUrl, { preserveScroll: true });
    };

    return (
        <form onSubmit={speichern} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor={`${idPrefix}-host`}>Server</Label>
                    <Input
                        id={`${idPrefix}-host`}
                        value={form.data.host}
                        onChange={(e) => form.setData('host', e.target.value)}
                        placeholder="smtp.eu.mailgun.org"
                    />
                    {form.errors.host && <p className="text-destructive text-sm">{form.errors.host}</p>}
                </div>

                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-port`}>Port</Label>
                    <Input
                        id={`${idPrefix}-port`}
                        type="number"
                        value={form.data.port}
                        onChange={(e) => form.setData('port', Number(e.target.value))}
                    />
                    {form.errors.port && <p className="text-destructive text-sm">{form.errors.port}</p>}
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div className="space-y-2">
                    <Label>Verschlüsselung</Label>
                    {/*
                        Laravel wertet einen Schlüssel `encryption` nicht mehr aus — es leitet
                        das Schema aus dem Port ab (465 → smtps, sonst smtp). Das Paket
                        übersetzt die Auswahl in `scheme`; ohne diese Übersetzung hinge die
                        Verschlüsselung am Port statt an der Einstellung.
                    */}
                    <Select value={form.data.encryption ?? 'tls'} onValueChange={(v) => form.setData('encryption', v)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="tls">TLS (Port 587)</SelectItem>
                            <SelectItem value="ssl">SSL (Port 465)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-user`}>Benutzername</Label>
                    <Input
                        id={`${idPrefix}-user`}
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                        placeholder="postmaster@mg.deine-domain.de"
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-pass`}>Passwort</Label>
                    <Input
                        id={`${idPrefix}-pass`}
                        type="password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        placeholder={werte?.hatPasswort ? 'hinterlegt — leer lassen' : ''}
                    />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-from`}>Absenderadresse</Label>
                    {/*
                        Ein fremder Postausgang nimmt eine Mail meist nur an, wenn sie von
                        einer seiner eigenen Adressen kommt — der Absender gehört deshalb zu
                        den Zugangsdaten und nicht in die Weltkonfiguration.
                    */}
                    <Input
                        id={`${idPrefix}-from`}
                        value={form.data.from_address}
                        onChange={(e) => form.setData('from_address', e.target.value)}
                    />
                    {form.errors.from_address && (
                        <p className="text-destructive text-sm">{form.errors.from_address}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-fromname`}>Absendername</Label>
                    <Input
                        id={`${idPrefix}-fromname`}
                        value={form.data.from_name}
                        onChange={(e) => form.setData('from_name', e.target.value)}
                    />
                </div>
            </div>

            <div className="flex items-center gap-2">
                <Switch
                    id={`${idPrefix}-aktiv`}
                    checked={form.data.is_active}
                    onCheckedChange={(v) => form.setData('is_active', v)}
                />
                {/*
                    Abgeschaltet heißt „nicht vorhanden": Die Ebene fällt auf die nächste
                    zurück. Ohne diesen Hinweis liest sich der Schalter wie „Versand aus".
                */}
                <Label htmlFor={`${idPrefix}-aktiv`} className="font-normal">
                    Aktiv — abgeschaltet wird über den nächsthöheren Weg verschickt
                </Label>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>
                    Speichern
                </Button>

                {werte && entfernenUrl && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => form.delete(entfernenUrl, { preserveScroll: true })}
                    >
                        Entfernen
                    </Button>
                )}
            </div>
        </form>
    );
}
