import { useForm } from '@inertiajs/react';
import { FlaskIcon, MoonIcon, SunIcon } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { MapCanvas } from '@/components/maps/MapCanvas';
import { storePin } from '@/components/maps/markers';
import type { MapConfig, MapErrorReason, MapHandle, MapTheme } from '@/components/maps/types';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { csrfHeaders } from '@/lib/http';
import { usePermission } from '@/lib/permissions';

type Provider = 'osm' | 'google' | 'maptiler' | 'mapbox' | 'custom';

interface Option {
    value: string;
    label: string;
}

interface ProviderOption {
    value: Provider;
    label: string;
    description: string;
    needs_key: boolean;
    styles?: Option[];
    dark_options?: Option[];
}

interface PreviewPoint {
    id: number;
    name: string;
    company: string;
    accent: string;
    accent_ink: string;
    latitude: number;
    longitude: number;
}

// Alias y no interfaz: `useForm` pide un tipo con firma de índice implícita.
type MapForm = {
    provider: Provider;
    osm_dark: string;
    google_key: string;
    google_map_id: string;
    google_map_type: string;
    maptiler_key: string;
    maptiler_style: string;
    maptiler_dark: string;
    mapbox_token: string;
    mapbox_style: string;
    mapbox_dark: string;
    custom_url: string;
    custom_dark_url: string;
    custom_attribution: string;
    custom_max_zoom: number;
    custom_tile_size: number;
    default_lat: string;
    default_lng: string;
    default_zoom: number;
};

interface MapsIndexProps {
    settings: Record<keyof MapForm, string | number | null>;
    providers: ProviderOption[];
    map: MapConfig;
    points: PreviewPoint[];
}

const ERRORS: Record<MapErrorReason, string> = {
    tiles: 'Las teselas no cargan con estos datos. Revisa la llave, que tu dominio esté autorizado en la consola del proveedor y que el estilo exista en tu cuenta.',
    auth: 'Google rechazó la llave. Revisa que tenga activa la API «Maps JavaScript», la facturación, y que tu dominio esté en sus restricciones.',
    load: 'No se pudo descargar el mapa. Revisa la conexión o si un bloqueador de anuncios lo impide.',
    'other-key': 'Google ya está cargado con otra llave en esta página. Recarga la página para probar la nueva.',
};

const str = (value: string | number | null | undefined, fallback = ''): string => (value === null || value === undefined ? fallback : String(value));

/**
 * Plataforma > Mapas: con qué proveedor se pintan el buscador de la landing y el mapa del
 * formulario de sedes. Se prueba antes de guardar, con las mismas sedes que ve el público.
 */
export default function MapsIndex({ settings, providers, map, points }: MapsIndexProps) {
    const { can } = usePermission();
    const canEdit = can('platform.maps.edit');
    const saved = map.provider as Provider;
    const [preview, setPreview] = useState<{ config: MapConfig; draft: boolean }>({ config: map, draft: false });
    const [previewTheme, setPreviewTheme] = useState<MapTheme>('light');
    const [previewError, setPreviewError] = useState<MapErrorReason | null>(null);
    const [testing, setTesting] = useState(false);

    const form = useForm<MapForm>({
        provider: (str(settings.provider, 'osm') as Provider) || 'osm',
        osm_dark: str(settings.osm_dark, 'filter'),
        google_key: str(settings.google_key),
        google_map_id: str(settings.google_map_id),
        google_map_type: str(settings.google_map_type, 'roadmap'),
        maptiler_key: str(settings.maptiler_key),
        maptiler_style: str(settings.maptiler_style, 'streets-v2'),
        maptiler_dark: str(settings.maptiler_dark, 'streets-v2-dark'),
        mapbox_token: str(settings.mapbox_token),
        mapbox_style: str(settings.mapbox_style, 'mapbox/streets-v12'),
        mapbox_dark: str(settings.mapbox_dark, 'mapbox/dark-v11'),
        custom_url: str(settings.custom_url),
        custom_dark_url: str(settings.custom_dark_url),
        custom_attribution: str(settings.custom_attribution),
        custom_max_zoom: Number(settings.custom_max_zoom ?? 19),
        custom_tile_size: Number(settings.custom_tile_size ?? 256),
        default_lat: str(settings.default_lat),
        default_lng: str(settings.default_lng),
        default_zoom: Number(settings.default_zoom ?? 6),
    });

    // Al guardar llega la configuración nueva: la vista previa vuelve a mostrar lo guardado.
    const savedKey = JSON.stringify(map);

    useEffect(() => {
        setPreview({ config: JSON.parse(savedKey) as MapConfig, draft: false });
    }, [savedKey]);

    const provider = providers.find((item) => item.value === form.data.provider) ?? providers[0];

    const test = async () => {
        setTesting(true);
        form.clearErrors();

        try {
            const response = await fetch(route('platform.maps.preview'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                credentials: 'same-origin',
                body: JSON.stringify(form.data),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                form.setError(Object.fromEntries(Object.entries(body.errors).map(([field, messages]) => [field, messages[0]])) as Record<keyof MapForm, string>);

                return;
            }

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const body = (await response.json()) as { map: MapConfig };
            setPreview({ config: body.map, draft: true });
        } catch {
            toast.error('No se pudo probar. Intenta de nuevo.');
        } finally {
            setTesting(false);
        }
    };

    const save = () => form.put(route('platform.maps.update'), { preserveScroll: true });

    const field = <K extends keyof MapForm>(name: K) => ({
        value: form.data[name] as string | number,
        error: form.errors[name],
        disabled: !canEdit,
    });

    return (
        <AppLayout title="Mapas">
            <PageHeader title="Mapas" description="Con qué se pintan el buscador de tiendas de la landing y el mapa para ubicar sedes." />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:items-start">
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader title="Proveedor" description="Uno solo para toda la plataforma. Las llaves de los demás se conservan." />
                        <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Proveedor de mapas">
                            {providers.map((item) => {
                                const selected = form.data.provider === item.value;
                                const body = (
                                    <>
                                        <span className="flex w-full items-center justify-between gap-2">
                                            <span className="text-[0.9375rem] font-semibold">{item.label}</span>
                                            {saved === item.value && <Badge tone="accent">En uso</Badge>}
                                        </span>
                                        <span className="text-[0.78125rem] text-[var(--muted)]">{item.description}</span>
                                        <span className="mt-1 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--muted)]">{item.needs_key ? 'Pide llave' : 'Sin llave'}</span>
                                    </>
                                );
                                const classes = cn(
                                    'flex flex-col items-start gap-1 rounded-card border p-3 text-left transition-colors',
                                    selected ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] bg-[var(--surface)]',
                                );

                                return canEdit ? (
                                    <button
                                        key={item.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        onClick={() => form.setData('provider', item.value)}
                                        className={cn(classes, !selected && 'hover:bg-[var(--surface-2)]')}
                                    >
                                        {body}
                                    </button>
                                ) : (
                                    <div key={item.value} role="radio" aria-checked={selected} className={classes}>
                                        {body}
                                    </div>
                                );
                            })}
                        </div>
                        {form.errors.provider && <p className="mt-2 text-[0.8125rem] font-medium text-[var(--danger)]">{form.errors.provider}</p>}
                    </Card>

                    <Card>
                        <CardHeader title={provider.label} description={provider.description} />
                        <div className="grid gap-3 sm:grid-cols-2">
                            {form.data.provider === 'osm' && (
                                <Select label="En modo oscuro" options={provider.dark_options ?? []} {...field('osm_dark')} onChange={(event) => form.setData('osm_dark', event.target.value)} />
                            )}

                            {form.data.provider === 'google' && (
                                <>
                                    <Input
                                        label="Llave de API"
                                        required
                                        autoComplete="off"
                                        spellCheck={false}
                                        hint="En Google Cloud > Credenciales. Restríngela a tu dominio y a «Maps JavaScript API»."
                                        containerClassName="sm:col-span-2"
                                        {...field('google_key')}
                                        onChange={(event) => form.setData('google_key', event.target.value)}
                                    />
                                    <Input
                                        label="Map ID"
                                        autoComplete="off"
                                        spellCheck={false}
                                        hint="Opcional. Se crea en Google Cloud > Administración de mapas. Sin él se usa el de demostración, que es solo para pruebas."
                                        {...field('google_map_id')}
                                        onChange={(event) => form.setData('google_map_id', event.target.value)}
                                    />
                                    <Select label="Tipo de mapa" options={provider.styles ?? []} {...field('google_map_type')} onChange={(event) => form.setData('google_map_type', event.target.value)} />
                                </>
                            )}

                            {form.data.provider === 'maptiler' && (
                                <>
                                    <Input
                                        label="Llave"
                                        required
                                        autoComplete="off"
                                        spellCheck={false}
                                        hint="En cloud.maptiler.com > API keys. Restringe los orígenes a tu dominio."
                                        containerClassName="sm:col-span-2"
                                        {...field('maptiler_key')}
                                        onChange={(event) => form.setData('maptiler_key', event.target.value)}
                                    />
                                    <Select label="Estilo" options={provider.styles ?? []} {...field('maptiler_style')} onChange={(event) => form.setData('maptiler_style', event.target.value)} />
                                    <Select label="En modo oscuro" options={provider.dark_options ?? []} {...field('maptiler_dark')} onChange={(event) => form.setData('maptiler_dark', event.target.value)} />
                                </>
                            )}

                            {form.data.provider === 'mapbox' && (
                                <>
                                    <Input
                                        label="Token público"
                                        required
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder="pk."
                                        hint="El que empieza por «pk.», en account.mapbox.com. Restringe las URL a tu dominio. El secreto (sk.) nunca va aquí."
                                        containerClassName="sm:col-span-2"
                                        {...field('mapbox_token')}
                                        onChange={(event) => form.setData('mapbox_token', event.target.value)}
                                    />
                                    <Select label="Estilo" options={provider.styles ?? []} {...field('mapbox_style')} onChange={(event) => form.setData('mapbox_style', event.target.value)} />
                                    <Select label="En modo oscuro" options={provider.dark_options ?? []} {...field('mapbox_dark')} onChange={(event) => form.setData('mapbox_dark', event.target.value)} />
                                </>
                            )}

                            {form.data.provider === 'custom' && (
                                <>
                                    <Input
                                        label="Dirección de las teselas"
                                        required
                                        spellCheck={false}
                                        placeholder="https://teselas.tudominio.com/{z}/{x}/{y}.png"
                                        hint="Con {z}, {x} y {y}. {s} (subdominio) y {r} (pantallas de alta densidad) son opcionales."
                                        containerClassName="sm:col-span-2"
                                        {...field('custom_url')}
                                        onChange={(event) => form.setData('custom_url', event.target.value)}
                                    />
                                    <Input
                                        label="Dirección para el modo oscuro"
                                        spellCheck={false}
                                        hint="Opcional. Sin ella, el modo oscuro oscurece la clara."
                                        containerClassName="sm:col-span-2"
                                        {...field('custom_dark_url')}
                                        onChange={(event) => form.setData('custom_dark_url', event.target.value)}
                                    />
                                    <Input
                                        label="Crédito"
                                        required
                                        placeholder="© OpenStreetMap"
                                        hint="El que exige el proveedor. Sale en la esquina del mapa, como texto."
                                        containerClassName="sm:col-span-2"
                                        {...field('custom_attribution')}
                                        onChange={(event) => form.setData('custom_attribution', event.target.value)}
                                    />
                                    <Input
                                        label="Acercamiento máximo"
                                        type="number"
                                        min={1}
                                        max={22}
                                        {...field('custom_max_zoom')}
                                        onChange={(event) => form.setData('custom_max_zoom', Number(event.target.value))}
                                    />
                                    <Select
                                        label="Tamaño de tesela"
                                        options={[
                                            { value: 256, label: '256 px' },
                                            { value: 512, label: '512 px' },
                                        ]}
                                        {...field('custom_tile_size')}
                                        onChange={(event) => form.setData('custom_tile_size', Number(event.target.value))}
                                    />
                                </>
                            )}
                        </div>
                        {provider.needs_key && (
                            <p className="mt-3 rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.78125rem] text-[var(--muted)]">
                                Esta llave viaja al navegador de cada visitante: así funcionan los mapas. Lo que la protege es restringirla a tu dominio en la consola del proveedor.
                            </p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Punto de partida" description="Donde arranca un mapa que todavía no tiene sedes para mostrar." />
                        <div className="flex flex-col gap-3">
                            {canEdit && (
                                <LocationPicker
                                    config={map}
                                    latitude={form.data.default_lat}
                                    longitude={form.data.default_lng}
                                    zoom={form.data.default_zoom}
                                    hint="Toca el mapa o arrastra el pin hasta el centro que quieras."
                                    onChange={(latitude, longitude) => form.setData((data) => ({ ...data, default_lat: latitude, default_lng: longitude }))}
                                />
                            )}
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Input label="Latitud" inputMode="decimal" {...field('default_lat')} onChange={(event) => form.setData('default_lat', event.target.value.replace(',', '.'))} />
                                <Input label="Longitud" inputMode="decimal" {...field('default_lng')} onChange={(event) => form.setData('default_lng', event.target.value.replace(',', '.'))} />
                                <Input
                                    label="Acercamiento"
                                    type="number"
                                    min={2}
                                    max={18}
                                    hint="6 muestra el país; 12, una ciudad."
                                    {...field('default_zoom')}
                                    onChange={(event) => form.setData('default_zoom', Number(event.target.value))}
                                />
                            </div>
                        </div>
                    </Card>

                    {canEdit && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="secondary" icon={<FlaskIcon size={16} />} onClick={test} loading={testing}>
                                Probar sin guardar
                            </Button>
                            <Button onClick={save} loading={form.processing} disabled={!form.isDirty}>
                                Guardar
                            </Button>
                        </div>
                    )}
                </div>

                <Card className="xl:sticky xl:top-4">
                    <CardHeader
                        title="Vista previa"
                        description={points.length > 0 ? `Las ${points.length} sedes con ubicación que hoy venden en línea.` : 'Todavía no hay sedes vendiendo con ubicación: se ve el punto de partida.'}
                        action={
                            previewError ? (
                                <Badge tone="danger">No cargó</Badge>
                            ) : preview.draft ? (
                                <Badge tone="warn">Probando sin guardar</Badge>
                            ) : (
                                <Badge tone="accent">Lo guardado</Badge>
                            )
                        }
                    />
                    <div className="mb-3 flex gap-1" role="radiogroup" aria-label="Tema de la vista previa">
                        {(['light', 'dark'] as const).map((value) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={previewTheme === value}
                                onClick={() => setPreviewTheme(value)}
                                className={cn(
                                    'inline-flex h-8 items-center gap-1.5 rounded-control border px-3 text-[0.8125rem] font-semibold',
                                    previewTheme === value ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] hover:bg-[var(--surface-2)]',
                                )}
                            >
                                {value === 'light' ? <SunIcon size={14} /> : <MoonIcon size={14} />}
                                {value === 'light' ? 'Claro' : 'Oscuro'}
                            </button>
                        ))}
                    </div>
                    <PreviewMap config={preview.config} theme={previewTheme} points={points} onError={setPreviewError} />
                    {previewError && <p className="mt-3 text-[0.8125rem] text-[var(--danger)]">{ERRORS[previewError]}</p>}
                </Card>
            </div>
        </AppLayout>
    );
}

function PreviewMap({ config, theme, points, onError }: { config: MapConfig; theme: MapTheme; points: PreviewPoint[]; onError: (reason: MapErrorReason | null) => void }) {
    const [handle, setHandle] = useState<MapHandle | null>(null);

    useEffect(() => {
        if (!handle) {
            return;
        }

        handle.setMarkers(
            points.map((point) => ({
                key: `s${point.id}`,
                position: { lat: point.latitude, lng: point.longitude },
                element: storePin(point),
                title: `${point.company} — Sede ${point.name}`,
            })),
        );
        handle.fitTo(points.map((point) => ({ lat: point.latitude, lng: point.longitude })));
    }, [handle, points]);

    return (
        <MapCanvas
            config={config}
            theme={theme}
            gestures="cooperative"
            label="Vista previa del mapa"
            onReady={setHandle}
            onError={onError}
            errorContent={() => <p>El mapa no cargó con estos datos.</p>}
            className="h-[26rem] rounded-card border border-[var(--line)]"
        />
    );
}
