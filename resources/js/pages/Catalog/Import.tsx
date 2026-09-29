import { router, usePage } from '@inertiajs/react';
import {
    ArrowClockwiseIcon,
    ArrowLeftIcon,
    CheckCircleIcon,
    DownloadSimpleIcon,
    ListMagnifyingGlassIcon,
    MagnifyingGlassIcon,
    PackageIcon,
    PlayIcon,
    UploadSimpleIcon,
    WarningCircleIcon,
} from '@phosphor-icons/react';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime, number } from '@/lib/format';
import { csrfHeaders, errorMessage } from '@/lib/http';
import type { BadgeTone } from '@/components/ui/Misc';
import type {
    CatalogImportPageProps,
    Ean,
    ImportRow,
    ItemResult,
    ItemsResponse,
    PreviewResponse,
    PreviewRow,
} from '@/types/catalog';
import type { PageProps } from '@/types';

type Step = 'criteria' | 'preview' | 'progress';

interface Criteria {
    country: string;
    brand: string;
    category: string;
    search: string;
    only_with_image: boolean;
    limit: number;
    category_id: string;
}

const COUNTRIES = ['Colombia', 'Mexico', 'Peru', 'Chile', 'Argentina', 'Ecuador', 'Spain'];

const STATUS_TONES: Record<string, BadgeTone> = {
    new: 'accent',
    outdated: 'info',
    up_to_date: 'neutral',
    deleted: 'warn',
};

const STATUS_LABELS: Record<string, string> = {
    new: 'Nuevo',
    outdated: 'Completa datos',
    up_to_date: 'Ya está al día',
    deleted: 'Eliminado',
};

const RESULT_TONES: Record<ItemResult, BadgeTone> = {
    imported: 'accent',
    updated: 'info',
    skipped: 'neutral',
    failed: 'danger',
};

const RESULT_LABELS: Record<ItemResult, string> = {
    imported: 'Importados',
    updated: 'Actualizados',
    skipped: 'Omitidos',
    failed: 'Fallidos',
};

/** Cada cuánto se le pregunta al servidor por el avance. */
const POLL_MS = 2000;

/**
 * Asistente para traer artículos desde un catálogo externo.
 *
 * Tres pasos: filtrar, mirar lo que hay y traerse lo que sirva. Lo que entra queda apagado y
 * sin precio, porque la fuente no tiene precios y un artículo activo en cero lo regala la caja.
 */
export default function CatalogImport({ categories, segments, running, history, limits, attribution, can }: CatalogImportPageProps) {
    const errors = (usePage<PageProps>().props.errors ?? {}) as Record<string, string>;

    const [step, setStep] = useState<Step>(running ? 'progress' : 'criteria');
    const [criteria, setCriteria] = useState<Criteria>({
        country: 'Colombia',
        brand: '',
        category: '',
        search: '',
        only_with_image: false,
        limit: limits.default,
        category_id: categories[0] ? String(categories[0].value) : '',
    });

    const [loading, setLoading] = useState(false);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<Ean>>(new Set());
    const [page, setPage] = useState(1);

    const [current, setCurrent] = useState<ImportRow | null>(running);
    const [items, setItems] = useState<ItemsResponse | null>(null);
    const [itemsLoading, setItemsLoading] = useState(false);
    const [itemsPage, setItemsPage] = useState(1);
    const [filter, setFilter] = useState<ItemResult | ''>('');
    const [csv, setCsv] = useState<File | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [runningNow, setRunningNow] = useState<number | null>(null);
    const [showImported, setShowImported] = useState(false);
    // Las imágenes vienen de un servidor de terceros que no siempre está al alcance.
    const [brokenImages, setBrokenImages] = useState<Set<string>>(new Set());

    const set = <K extends keyof Criteria>(key: K, value: Criteria[K]) => setCriteria((c) => ({ ...c, [key]: value }));

    const loadPreview = useCallback(
        (targetPage: number) => {
            setLoading(true);
            setPreviewError(null);

            fetch(route('catalog.import.preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ ...criteria, page: targetPage }),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(await errorMessage(response, 'No se pudo consultar el catálogo externo.'));
                    }

                    return (await response.json()) as PreviewResponse;
                })
                .then((result) => {
                    setPreview(result);
                    setPage(targetPage);
                    setStep('preview');
                })
                .catch((error: Error) => setPreviewError(error.message))
                .finally(() => setLoading(false));
        },
        [criteria],
    );

    const loadItems = useCallback((importId: number, result: ItemResult | '', page = 1) => {
        const query = new URLSearchParams({ page: String(page) });

        if (result !== '') {
            query.set('result', result);
        }

        setItemsLoading(true);

        fetch(`${route('catalog.import.items', importId)}?${query.toString()}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: ItemsResponse | null) => setItems(body))
            .catch(() => setItems(null))
            .finally(() => setItemsLoading(false));
    }, []);

    /**
     * Abre el detalle de una corrida del historial.
     *
     * Es la misma vista del paso 3, con la corrida elegida: el avance arriba y el detalle
     * artículo por artículo abajo. Se reinicia el filtro para no heredar el de la anterior.
     */
    const openDetail = (row: ImportRow) => {
        setCurrent(row);
        setItems(null);
        setFilter('');
        setItemsPage(1);
        setStep('progress');
    };

    // Sondeo del avance. El `clearInterval` del return no es opcional: sin él el intervalo
    // sobrevive al desmontaje y sigue golpeando el servidor con la pantalla cerrada.
    const pollingId = current?.id ?? null;
    const isFinal = current?.is_final ?? true;
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        if (pollingId === null || isFinal) {
            return;
        }

        const tick = () => {
            fetch(route('catalog.import.show', pollingId), { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { import: ImportRow } | null) => {
                    if (body) {
                        setCurrent(body.import);
                    }
                })
                .catch(() => undefined);
        };

        tick();
        timer.current = setInterval(tick, POLL_MS);

        return () => {
            if (timer.current) {
                clearInterval(timer.current);
                timer.current = null;
            }
        };
    }, [pollingId, isFinal]);

    // Al terminar —o al abrir una del historial— se trae el detalle.
    useEffect(() => {
        if (pollingId !== null && isFinal) {
            loadItems(pollingId, filter, itemsPage);
        }
    }, [pollingId, isFinal, filter, itemsPage, loadItems]);

    /**
     * Mantiene al día la corrida que se está mirando.
     *
     * Después de «Importar ahora» Inertia recarga las props, pero la corrida abierta vive en
     * estado local: sin esto seguiría diciendo «En espera» con el botón puesto, cuando ya
     * terminó.
     */
    useEffect(() => {
        if (current === null) {
            return;
        }

        const fresh = [running, ...history].find((row) => row !== null && row.id === current.id);

        if (fresh && fresh.status !== current.status) {
            setCurrent(fresh);
        }
    }, [running, history, current]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!can.run) {
            return;
        }

        setSubmitting(true);

        const payload = {
            source: csv ? 'csv' : 'openfoodfacts',
            country: criteria.country,
            brand: criteria.brand,
            category: criteria.category,
            search: criteria.search,
            only_with_image: criteria.only_with_image,
            limit: criteria.limit,
            category_id: criteria.category_id,
            // Siempre texto: un `Number()` aquí se comería los ceros de la izquierda del EAN.
            eans: Array.from(selected),
            ...(csv ? { file: csv } : {}),
        };

        router.post(route('catalog.import.store'), payload, {
            forceFormData: csv !== null,
            onSuccess: () => {
                setStep('progress');
                setSelected(new Set());
                setCsv(null);
            },
            onError: () => toast.error('No se pudo lanzar la importación.'),
            onFinish: () => setSubmitting(false),
        });
    };

    /**
     * Corre la importación en el acto, sin cola.
     *
     * La petición no vuelve hasta que termina: es lento a propósito, porque la fuente solo
     * admite una consulta por segundo. Por eso el botón se queda cargando y no se puede
     * disparar dos veces.
     */
    const runNow = (importId: number) => {
        setRunningNow(importId);

        router.post(
            route('catalog.import.run', importId),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRunningNow(null),
            },
        );
    };

    const toggle = (ean: Ean) =>
        setSelected((current) => {
            const next = new Set(current);
            next.has(ean) ? next.delete(ean) : next.add(ean);

            return next;
        });

    /*
     * Lo que ya entró y la fuente no puede mejorar no se vuelve a ofrecer.
     *
     * Volver a listarlo obliga a revisar a mano, fila por fila, qué es nuevo de verdad, y esa
     * es exactamente la tarea que este asistente venía a evitar. Se puede volver a mostrar,
     * pero no por defecto.
     */
    const rows = (preview?.products ?? []).filter((row) => showImported || row.status !== 'up_to_date');
    // Se cuenta aparte del filtro: con el interruptor encendido sigue habiendo que ofrecer
    // apagarlo, y `products.length - rows.length` daría cero.
    const hidden = (preview?.products ?? []).filter((row) => row.status === 'up_to_date').length;

    const usable = rows.filter((row): row is PreviewRow & { ean: Ean } => row.usable && row.ean !== null);
    const allSelected = usable.length > 0 && usable.every((row) => selected.has(row.ean));

    const toggleAll = () =>
        setSelected((current) => {
            const next = new Set(current);
            usable.forEach((row) => (allSelected ? next.delete(row.ean) : next.add(row.ean)));

            return next;
        });

    /**
     * El detalle completo, no la página que se está viendo.
     *
     * Lo arma el servidor: el listado viene paginado de a 50, y una importación de quinientos
     * artículos exportada desde el navegador saldría recortada con nombre de completa.
     */
    const exportCsv = () => {
        if (!current) {
            return;
        }

        const query = filter === '' ? '' : `?result=${filter}`;

        window.location.href = `${route('catalog.import.export', current.id)}${query}`;
    };

    const blocked = running !== null && !running.is_final;

    return (
        <AppLayout title="Importar artículos">
            <PageHeader
                title="Importar artículos"
                description="Trae artículos desde un catálogo público para arrancar sin digitar miles de códigos a mano."
                actions={
                    step !== 'criteria' ? (
                        <Button variant="secondary" icon={<ArrowLeftIcon size={15} />} onClick={() => setStep('criteria')}>
                            Volver a los filtros
                        </Button>
                    ) : undefined
                }
            />

            <ol className="mb-4 flex flex-wrap gap-1.5">
                {(
                    [
                        ['criteria', '1. Filtros'],
                        ['preview', '2. Qué hay'],
                        ['progress', '3. Resultado'],
                    ] as const
                ).map(([key, label]) => (
                    <li
                        key={key}
                        className={cn(
                            'rounded-control px-3 py-1.5 text-[0.78125rem] font-medium',
                            step === key ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'bg-[var(--surface-2)] text-[var(--muted)]',
                        )}
                    >
                        {label}
                    </li>
                ))}
            </ol>

            {blocked && step !== 'progress' && (
                <p className="mb-4 flex items-center gap-2 rounded-control bg-[var(--warn-soft)] px-3 py-2.5 text-[0.8125rem] font-medium text-[var(--warn)]">
                    <ArrowClockwiseIcon size={16} className="animate-spin" />
                    Ya hay una importación en curso para tu empresa. Espera a que termine antes de lanzar otra.
                </p>
            )}

            {step === 'criteria' && (
                <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                    <Card>
                        <CardHeader
                            title="Qué buscar"
                            description="Entre más específico el filtro, menos ruido trae la búsqueda."
                        />

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Select
                                label="País"
                                required
                                options={COUNTRIES.map((country) => ({ value: country, label: country }))}
                                value={criteria.country}
                                onChange={(event) => set('country', event.target.value)}
                                error={errors.country}
                            />
                            <Input
                                label="Marca"
                                value={criteria.brand}
                                onChange={(event) => set('brand', event.target.value)}
                                placeholder="Alpina"
                                hint="Opcional."
                            />
                            <Select
                                label="Segmento"
                                options={segments}
                                placeholder="Todos los segmentos"
                                value={criteria.category}
                                onChange={(event) => set('category', event.target.value)}
                                hint="Filtra lo que trae la fuente. Es lo que decide qué aparece abajo."
                            />
                            <Input
                                label="Búsqueda libre"
                                value={criteria.search}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="arroz"
                                hint="Opcional."
                            />
                            <Input
                                label="Cuántos traer como máximo"
                                type="number"
                                min={1}
                                max={limits.max}
                                required
                                value={criteria.limit}
                                onChange={(event) => set('limit', Number(event.target.value))}
                                error={errors.limit}
                                hint={`Hasta ${number(limits.max)}.`}
                            />
                            <Select
                                label="Dónde guardarlos en tu catálogo"
                                required
                                options={categories}
                                placeholder={categories.length === 0 ? 'Crea una categoría primero' : undefined}
                                value={criteria.category_id}
                                onChange={(event) => set('category_id', event.target.value)}
                                error={errors.category_id}
                                hint="Esto no filtra: es la categoría tuya donde quedan los que traigas."
                            />
                        </div>

                        <div className="mt-3">
                            <Checkbox
                                label="Solo artículos con imagen"
                                checked={criteria.only_with_image}
                                onChange={(event) => set('only_with_image', event.target.checked)}
                            />
                        </div>

                        {previewError && (
                            <p className="mt-3 flex items-center gap-2 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                                <WarningCircleIcon size={15} weight="fill" />
                                {previewError}
                            </p>
                        )}

                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                loading={loading}
                                disabled={categories.length === 0}
                                icon={<MagnifyingGlassIcon size={15} weight="bold" />}
                                onClick={() => loadPreview(1)}
                            >
                                Previsualizar
                            </Button>
                        </div>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader title="O sube un archivo" description="La salida del volcado filtrado, con columnas ean, nombre, marca, presentacion, categoria e imagen." />

                            <input
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) => setCsv(event.target.files?.[0] ?? null)}
                                className="block w-full text-[0.78125rem] text-[var(--muted)] file:mr-3 file:min-h-[2.5rem] file:rounded-control file:border-0 file:bg-[var(--surface-2)] file:px-3 file:text-[0.78125rem] file:font-medium file:text-[var(--text)]"
                            />

                            {errors.file && <p className="mt-1.5 text-[0.75rem] text-[var(--danger)]">{errors.file}</p>}

                            {csv && (
                                <Button
                                    type="submit"
                                    className="mt-3"
                                    loading={submitting}
                                    disabled={!can.run || blocked || criteria.category_id === ''}
                                    icon={<UploadSimpleIcon size={15} weight="bold" />}
                                >
                                    Importar «{csv.name}»
                                </Button>
                            )}

                            <p className="mt-2 text-[0.71875rem] text-[var(--muted)]">
                                Hasta {number(Math.round(limits.csv_max_kb / 1024))} MB. Se lee por partes, no se carga entero en memoria.
                            </p>
                        </Card>

                        <Card>
                            <CardHeader title="Qué hace y qué no" />
                            <ul className="flex list-disc flex-col gap-1.5 pl-4 text-[0.78125rem] leading-relaxed text-[var(--muted)]">
                                <li>Los artículos entran <strong className="text-[var(--text)]">apagados y sin precio</strong>: la fuente no tiene precios. Actívalos cuando los tarifes.</li>
                                <li>Si el artículo ya existe, solo se completan los campos vacíos. Lo que edites a mano no se pisa.</li>
                                <li>Nunca se crean categorías solas.</li>
                                <li>El costo, el impuesto y las existencias siempre son tuyos.</li>
                            </ul>
                        </Card>

                        {errors.source && (
                            <p className="rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                                {errors.source}
                            </p>
                        )}
                    </div>
                </form>
            )}

            {step === 'preview' && preview && (
                <Card padded={false}>
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                        <div>
                            <p className="text-[0.875rem] font-semibold">Se encontraron {number(preview.count)} artículos</p>
                            <p className="text-[0.75rem] text-[var(--muted)]">
                                Página {preview.page} · {number(preview.worth)} por traer · {selected.size} seleccionado
                                {selected.size === 1 ? '' : 's'}
                                {hidden > 0 && (
                                    <>
                                        {' · '}
                                        <button
                                            type="button"
                                            onClick={() => setShowImported(!showImported)}
                                            className="font-medium text-[var(--accent)] hover:underline"
                                        >
                                            {showImported
                                                ? 'ocultar los que ya están al día'
                                                : `${number(hidden)} ya en tu catálogo, ocultos`}
                                        </button>
                                    </>
                                )}
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="secondary"
                                size="sm"
                                disabled={page <= 1 || loading}
                                onClick={() => loadPreview(page - 1)}
                            >
                                Anterior
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                disabled={loading || preview.products.length < preview.page_size}
                                onClick={() => loadPreview(page + 1)}
                            >
                                Siguiente
                            </Button>
                            <Button
                                size="sm"
                                loading={submitting}
                                disabled={!can.run || blocked || selected.size === 0}
                                icon={<CheckCircleIcon size={15} weight="bold" />}
                                onClick={submit}
                            >
                                Importar {selected.size > 0 ? number(selected.size) : ''} seleccionados
                            </Button>
                        </div>
                    </div>

                    {rows.length === 0 ? (
                        <EmptyState
                            title={hidden > 0 ? 'Todo lo de esta página ya está en tu catálogo' : 'Ese filtro no trajo nada'}
                            description={
                                hidden > 0
                                    ? 'Pasa a la siguiente página, cambia de segmento, o muestra los que ya están al día.'
                                    : 'Prueba con otra marca, otro segmento o quita la búsqueda libre.'
                            }
                            icon={<PackageIcon size={24} />}
                            action={
                                hidden > 0 ? (
                                    <Button variant="secondary" onClick={() => setShowImported(true)}>
                                        Mostrar los {number(hidden)} que ya están
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>
                                        <input
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={toggleAll}
                                            aria-label="Seleccionar todos"
                                            className="size-4 accent-[var(--accent)]"
                                        />
                                    </TH>
                                    <TH>Artículo</TH>
                                    <TH>Código</TH>
                                    <TH>Marca</TH>
                                    <TH>Presentación</TH>
                                    <TH>Categoría</TH>
                                    <TH align="center">Estado</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {rows.map((row, index) => (
                                    <TR key={row.ean ?? `row-${index}`} className={row.usable ? undefined : 'opacity-55'}>
                                        <TD>
                                            <input
                                                type="checkbox"
                                                disabled={!row.usable || row.ean === null}
                                                checked={row.ean !== null && selected.has(row.ean)}
                                                onChange={() => row.ean && toggle(row.ean)}
                                                aria-label={row.name ?? 'Artículo'}
                                                className="size-4 accent-[var(--accent)]"
                                            />
                                        </TD>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                {row.image && !brokenImages.has(row.image) ? (
                                                    <img
                                                        src={row.image}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        // Servidor de terceros: no se le manda de dónde viene la petición.
                                                        referrerPolicy="no-referrer"
                                                        onError={() =>
                                                            setBrokenImages((current) => new Set(current).add(row.image as string))
                                                        }
                                                        className="size-9 shrink-0 rounded-chip object-cover"
                                                    />
                                                ) : (
                                                    <span className="grid size-9 shrink-0 place-items-center rounded-chip bg-[var(--surface-2)] text-[var(--muted)]">
                                                        <PackageIcon size={15} />
                                                    </span>
                                                )}
                                                <span className="min-w-0">
                                                    <span className="block truncate text-[0.8125rem] font-medium">{row.name ?? 'Sin nombre'}</span>
                                                    {row.reason && <span className="text-[0.71875rem] text-[var(--warn)]">{row.reason}</span>}
                                                    {row.current_name && row.current_name !== row.name && (
                                                        <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                                            En tu catálogo: {row.current_name}
                                                        </span>
                                                    )}
                                                </span>
                                            </div>
                                        </TD>
                                        <TD className="font-mono text-[0.75rem] tabular">{row.ean ?? '—'}</TD>
                                        <TD className="text-[0.78125rem]">{row.brand ?? '—'}</TD>
                                        <TD className="text-[0.78125rem]">{row.packaging ?? '—'}</TD>
                                        <TD className="text-[0.78125rem] text-[var(--muted)]">{row.category ?? '—'}</TD>
                                        <TD align="center">
                                            <Badge tone={STATUS_TONES[row.status] ?? 'neutral'}>{STATUS_LABELS[row.status]}</Badge>
                                            {row.adds.length > 0 && (
                                                <span className="mt-0.5 block text-[0.6875rem] text-[var(--muted)]">
                                                    le pone {[...new Set(row.adds)].join(', ')}
                                                </span>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}

                    {/*
                     * Las fotos las sirve un servidor de terceros. Si esta red no lo alcanza,
                     * decirlo evita que parezca que los artículos vienen sin imagen.
                     */}
                    {brokenImages.size >= 3 && (
                        <p className="border-t border-[var(--line)] px-4 py-2.5 text-[0.75rem] text-[var(--muted)]">
                            No se están pudiendo cargar las fotos desde{' '}
                            <code className="font-mono">images.openfoodfacts.org</code>. Es cosa de la red, no del catálogo: los
                            artículos se importan igual y la foto queda guardada para cuando el servidor vuelva.
                        </p>
                    )}
                </Card>
            )}

            {step === 'progress' && (
                <div className="flex flex-col gap-4">
                    {current === null ? (
                        <EmptyState
                            title="Todavía no has lanzado ninguna importación"
                            description="Vuelve a los filtros, previsualiza y elige qué traer."
                            icon={<PackageIcon size={24} />}
                        />
                    ) : (
                        <Card>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-[0.875rem] font-semibold">{current.status_label}</p>
                                    <p className="text-[0.75rem] text-[var(--muted)]">
                                        {current.source_label} · {current.criteria_summary}
                                    </p>
                                </div>

                                {!current.is_final && can.cancel && (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            router.post(route('catalog.import.cancel', current.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        Cancelar
                                    </Button>
                                )}
                            </div>

                            <div className="h-2 w-full overflow-hidden rounded-full bg-[var(--surface-2)]">
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-[width] duration-500',
                                        current.status === 'failed' ? 'bg-[var(--danger)]' : 'bg-[var(--accent)]',
                                    )}
                                    style={{ width: `${current.progress}%` }}
                                />
                            </div>

                            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                                {(
                                    [
                                        ['detected', 'Detectados'],
                                        ['imported', 'Importados'],
                                        ['updated', 'Actualizados'],
                                        ['skipped', 'Omitidos'],
                                        ['failed', 'Fallidos'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <div key={key} className="rounded-control bg-[var(--surface-2)] px-3 py-2">
                                        <p className="font-mono text-[1.125rem] font-semibold tabular">{number(current.totals[key])}</p>
                                        <p className="text-[0.71875rem] text-[var(--muted)]">{label}</p>
                                    </div>
                                ))}
                            </div>

                            {current.status === 'pending' && (
                                <div className="mt-3 rounded-control bg-[var(--surface-2)] px-3 py-2.5">
                                    <p className="text-[0.75rem] text-[var(--muted)]">
                                        En espera de que la cola la tome. Si no tienes un{' '}
                                        <code className="font-mono">php artisan queue:work --queue=imports</code> corriendo, córrela
                                        aquí mismo: la página se queda esperando hasta que termine.
                                    </p>

                                    {can.run && (
                                        <Button
                                            size="sm"
                                            className="mt-2"
                                            loading={runningNow === current.id}
                                            disabled={runningNow !== null}
                                            icon={<PlayIcon size={14} weight="fill" />}
                                            onClick={() => runNow(current.id)}
                                        >
                                            Importar ahora, sin la cola
                                        </Button>
                                    )}
                                </div>
                            )}

                            {errors.status && (
                                <p className="mt-3 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                                    {errors.status}
                                </p>
                            )}

                            {current.error_message && (
                                <p className="mt-3 flex items-start gap-2 rounded-control bg-[var(--danger-soft)] px-3 py-2 text-[0.78125rem] font-medium text-[var(--danger)]">
                                    <WarningCircleIcon size={15} weight="fill" className="mt-0.5 shrink-0" />
                                    {current.error_message}
                                </p>
                            )}

                            {current.is_final && current.totals.imported > 0 && (
                                <p className="mt-3 rounded-control bg-[var(--accent-soft)] px-3 py-2 text-[0.78125rem] font-medium text-[var(--accent)]">
                                    Los artículos quedaron apagados y sin precio. Ponles precio y actívalos para que lleguen a la caja.
                                </p>
                            )}
                        </Card>
                    )}

                    {current?.is_final && items && (
                        <Card padded={false}>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                                <div className="flex flex-wrap gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setFilter('');
                                            setItemsPage(1);
                                        }}
                                        className={cn(
                                            'min-h-[2.125rem] rounded-control px-2.5 text-[0.78125rem] font-medium',
                                            filter === '' ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)]',
                                        )}
                                    >
                                        Todos
                                    </button>
                                    {(Object.keys(RESULT_LABELS) as ItemResult[]).map((result) => (
                                        <button
                                            key={result}
                                            type="button"
                                            onClick={() => {
                                                setFilter(result);
                                                // Filtrar y quedarse en la página 3 de una lista
                                                // más corta mostraría una tabla vacía.
                                                setItemsPage(1);
                                            }}
                                            className={cn(
                                                'min-h-[2.125rem] rounded-control px-2.5 text-[0.78125rem] font-medium',
                                                filter === result ? 'bg-[var(--accent-soft)] text-[var(--accent)]' : 'text-[var(--muted)]',
                                            )}
                                        >
                                            {RESULT_LABELS[result]} ({number(items.counts[result] ?? 0)})
                                        </button>
                                    ))}
                                </div>

                                <Button variant="secondary" size="sm" icon={<DownloadSimpleIcon size={15} />} onClick={exportCsv}>
                                    Exportar el detalle
                                </Button>
                            </div>

                            {items.items.data.length === 0 ? (
                                <EmptyState title="Nada que mostrar aquí" description="Prueba con otro filtro." icon={<PackageIcon size={24} />} />
                            ) : (
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Código</TH>
                                            <TH>Artículo</TH>
                                            <TH align="center">Resultado</TH>
                                            <TH>Motivo</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {items.items.data.map((item) => (
                                            <TR key={item.id}>
                                                <TD className="font-mono text-[0.75rem] tabular">{item.ean ?? '—'}</TD>
                                                <TD className="text-[0.8125rem]">
                                                    {item.product ? (
                                                        <a
                                                            href={route('products.show', item.product.id)}
                                                            className="font-medium text-[var(--accent)] hover:underline"
                                                        >
                                                            {item.product.name}
                                                        </a>
                                                    ) : (
                                                        (item.name ?? '—')
                                                    )}
                                                </TD>
                                                <TD align="center">
                                                    <Badge tone={RESULT_TONES[item.result]}>{item.result_label}</Badge>
                                                </TD>
                                                <TD className="text-[0.78125rem] text-[var(--muted)]">{item.reason ?? '—'}</TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            )}

                            {/* Una importación de quinientos artículos no cabe en una página. */}
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--line)] px-4 py-3">
                                <p className="text-[0.75rem] text-[var(--muted)]">
                                    {items.items.total === 0
                                        ? 'Sin artículos'
                                        : `${number(items.items.from ?? 0)}–${number(items.items.to ?? 0)} de ${number(items.items.total)}`}
                                </p>

                                {items.items.last_page > 1 && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={itemsLoading || items.items.current_page <= 1}
                                            onClick={() => setItemsPage((page) => Math.max(1, page - 1))}
                                        >
                                            Anterior
                                        </Button>
                                        <span className="font-mono text-[0.75rem] text-[var(--muted)] tabular">
                                            {number(items.items.current_page)} / {number(items.items.last_page)}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={itemsLoading || items.items.current_page >= items.items.last_page}
                                            onClick={() => setItemsPage((page) => page + 1)}
                                        >
                                            Siguiente
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </Card>
                    )}
                </div>
            )}

            {history.length > 0 && step === 'criteria' && (
                <Card className="mt-4" padded={false}>
                    <div className="border-b border-[var(--line)] px-4 py-3">
                        <p className="text-[0.8125rem] font-semibold">Importaciones anteriores</p>
                    </div>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Cuándo</TH>
                                <TH>Criterios</TH>
                                <TH>Quién</TH>
                                <TH align="center">Resultado</TH>
                                <TH align="right">Artículos</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {history.map((row) => (
                                <TR key={row.id}>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{dateTime(row.created_at)}</TD>
                                    <TD className="text-[0.78125rem]">{row.criteria_summary}</TD>
                                    <TD className="text-[0.78125rem] text-[var(--muted)]">{row.user ?? '—'}</TD>
                                    <TD align="center">
                                        <Badge tone={row.status === 'failed' ? 'danger' : row.status === 'completed' ? 'accent' : 'neutral'}>
                                            {row.status_label}
                                        </Badge>
                                    </TD>
                                    <TD align="right" className="font-mono text-[0.78125rem] tabular">
                                        {number(row.totals.imported)} nuevos · {number(row.totals.updated)} al día
                                    </TD>
                                    <TD align="right">
                                        <div className="flex items-center justify-end gap-1.5">
                                            {row.status === 'pending' && can.run && (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    loading={runningNow === row.id}
                                                    disabled={runningNow !== null}
                                                    icon={<PlayIcon size={14} weight="fill" />}
                                                    onClick={() => runNow(row.id)}
                                                    title="La corre ahora mismo, sin esperar la cola"
                                                >
                                                    Importar ahora
                                                </Button>
                                            )}

                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                icon={<ListMagnifyingGlassIcon size={15} />}
                                                onClick={() => openDetail(row)}
                                                title="Qué pasó con cada artículo"
                                            >
                                                Ver detalle
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}

            <p className="mt-4 text-center text-[0.71875rem] text-[var(--muted)]">
                <a href={attribution.url} target="_blank" rel="noreferrer" className="hover:underline">
                    {attribution.label}
                </a>
            </p>
        </AppLayout>
    );
}
