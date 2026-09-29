import { router, useForm } from '@inertiajs/react';
import { DownloadSimpleIcon, FileArrowUpIcon, FileCsvIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useMemo } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Badge, type BadgeTone, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

interface ImportType {
    value: string;
    label: string;
    description: string;
    needs_company: boolean;
    columns: { name: string; hint: string; required: boolean }[];
    notes: string[];
}

interface Batch {
    id: number;
    type: string;
    type_label: string;
    company: string | null;
    user: string | null;
    original_filename: string;
    status: 'pending' | 'processing' | 'completed' | 'partial' | 'failed';
    status_label: string;
    rows_total: number;
    rows_success: number;
    rows_failed: number;
    message: string | null;
    has_errors: boolean;
    created_at: string | null;
    finished_at: string | null;
}

interface Preview {
    batch_id: number;
    type: string;
    company: string | null;
    filename: string;
    rows: number;
    created: number;
    updated: number;
    skipped: number;
    errors: string[];
    sample: { line: number; action: 'create' | 'update' | 'skip'; label: string; reason: string | null }[];
}

interface ImportsIndexProps {
    types: ImportType[];
    companies: { value: number; label: string }[];
    batches: Batch[];
    preview: Preview | null;
}

const STATUS_TONES: Record<Batch['status'], BadgeTone> = {
    pending: 'info',
    processing: 'info',
    completed: 'accent',
    partial: 'warn',
    failed: 'danger',
};

const ACTIONS: Record<Preview['sample'][number]['action'], { label: string; tone: BadgeTone }> = {
    create: { label: 'Nuevo', tone: 'accent' },
    update: { label: 'Actualiza', tone: 'info' },
    skip: { label: 'Se omite', tone: 'danger' },
};

/**
 * Plataforma > Carga masiva (CSV). Usa el mismo lector y las mismas maestras del panel; lo
 * propio es la empresa destino, que se elige aqui y no en la barra superior.
 */
export default function PlatformImports({ types, companies, batches, preview }: ImportsIndexProps) {
    const { can } = usePermission();
    const canRun = can('platform.imports.run');
    const form = useForm<{ type: string; company_id: string; file: File | null }>({ type: types[0]?.value ?? '', company_id: '', file: null });
    const current = useMemo(() => types.find((type) => type.value === form.data.type), [types, form.data.type]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.imports.preview'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('file', null) });
    };

    return (
        <AppLayout title="Carga masiva">
            <PageHeader
                title="Carga masiva (CSV)"
                description="Empresas completas o maestras de una empresa, desde un archivo. Primero se revisa, después se procesa."
                actions={
                    <a
                        href={route('platform.imports.templates')}
                        className="inline-flex h-10 items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-4 text-sm font-medium text-[var(--text)] hover:bg-[var(--surface-3)]"
                    >
                        <DownloadSimpleIcon size={16} /> Todas las plantillas (ZIP)
                    </a>
                }
            />

            {preview && <PreviewCard preview={preview} canRun={canRun} />}

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                {canRun && (
                    <Card>
                        <CardHeader title="Nueva carga" />
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <div>
                                <p className="mb-2 text-[0.8125rem] font-medium">1. Qué vas a cargar</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {types.map((type) => (
                                        <button
                                            key={type.value}
                                            type="button"
                                            onClick={() => form.setData('type', type.value)}
                                            className={cn(
                                                'rounded-control border px-3 py-2 text-left text-[0.84375rem] font-medium transition-colors',
                                                form.data.type === type.value
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                    : 'border-[var(--line)] bg-[var(--surface-2)] hover:border-[var(--line-2)]',
                                            )}
                                        >
                                            {type.label}
                                        </button>
                                    ))}
                                </div>
                                {form.errors.type && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{form.errors.type}</p>}
                            </div>

                            {current?.needs_company ? (
                                <div>
                                    <p className="mb-2 text-[0.8125rem] font-medium">2. Empresa destino</p>
                                    <Select
                                        value={form.data.company_id}
                                        onChange={(event) => form.setData('company_id', event.target.value)}
                                        options={companies.map((company) => ({ value: String(company.value), label: company.label }))}
                                        placeholder="Elige la empresa"
                                        hint="No depende de la empresa elegida arriba: la carga va a esta."
                                        error={form.errors.company_id}
                                    />
                                </div>
                            ) : (
                                <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-[0.8125rem] text-[var(--muted)]">
                                    2. Cada fila crea una empresa nueva: no hay empresa destino.
                                </p>
                            )}

                            <div>
                                <p className="mb-2 text-[0.8125rem] font-medium">3. Archivo</p>
                                <label className="flex cursor-pointer items-center gap-3 rounded-control border border-dashed border-[var(--line-2)] bg-[var(--surface-2)] px-4 py-3 text-[0.84375rem] hover:border-[var(--accent)]">
                                    <FileArrowUpIcon size={22} className="shrink-0 text-[var(--muted)]" />
                                    <span className="min-w-0 truncate">{form.data.file ? form.data.file.name : 'Elige el CSV (separado por ; o ,)'}</span>
                                    <input
                                        type="file"
                                        accept=".csv,text/csv"
                                        className="sr-only"
                                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                    />
                                </label>
                                {form.errors.file && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{form.errors.file}</p>}
                            </div>

                            <div>
                                <Button type="submit" loading={form.processing} disabled={!form.data.file}>
                                    Revisar el archivo
                                </Button>
                            </div>
                        </form>
                    </Card>
                )}

                {current && (
                    <Card>
                        <CardHeader
                            title={`Formato: ${current.label}`}
                            description={current.description}
                            action={
                                <a
                                    href={route('platform.imports.template', current.value)}
                                    className="inline-flex items-center gap-1.5 text-[0.8125rem] font-medium text-[var(--accent)] hover:underline"
                                >
                                    <FileCsvIcon size={16} /> Plantilla
                                </a>
                            }
                        />
                        <ul className="flex flex-col divide-y divide-[var(--line)] text-[0.8125rem]">
                            {current.columns.map((column) => (
                                <li key={column.name} className="flex flex-wrap items-baseline gap-x-3 py-1.5">
                                    <code className="font-mono text-[0.78125rem] font-semibold">{column.name}</code>
                                    {column.required && <Badge tone="warn">Obligatoria</Badge>}
                                    <span className="text-[var(--muted)]">{column.hint}</span>
                                </li>
                            ))}
                        </ul>
                        {current.notes.length > 0 && (
                            <ul className="mt-3 flex flex-col gap-1 text-[0.78125rem] text-[var(--muted)]">
                                {current.notes.map((note) => (
                                    <li key={note}>• {note}</li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </div>

            <Card padded={false} className="mt-5">
                <div className="p-5 pb-3">
                    <CardHeader title="Historial" description="Las últimas 30 cargas, con el archivo original y el reporte de errores." className="mb-0" />
                </div>
                {batches.length === 0 ? (
                    <EmptyState title="Todavía no hay cargas" />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Fecha</TH>
                                <TH>Tipo</TH>
                                <TH>Empresa</TH>
                                <TH>Archivo</TH>
                                <TH align="right">Filas</TH>
                                <TH align="right">Entraron</TH>
                                <TH align="right">Omitidas</TH>
                                <TH>Estado</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {batches.map((batch) => (
                                <TR key={batch.id}>
                                    <TD>
                                        <span className="text-[0.8125rem]">{batch.created_at ? dateTime(batch.created_at) : '—'}</span>
                                        <span className="block text-[0.75rem] text-[var(--muted)]">{batch.user ?? ''}</span>
                                    </TD>
                                    <TD>{batch.type_label}</TD>
                                    <TD>{batch.company ?? '—'}</TD>
                                    <TD>
                                        <a href={route('platform.imports.original', batch.id)} className="text-[0.8125rem] font-medium text-[var(--accent)] hover:underline">
                                            {batch.original_filename}
                                        </a>
                                        {batch.has_errors && (
                                            <a
                                                href={route('platform.imports.errors', batch.id)}
                                                className="mt-0.5 flex items-center gap-1 text-[0.75rem] font-medium text-[var(--danger)] hover:underline"
                                            >
                                                <WarningCircleIcon size={13} /> Errores (CSV)
                                            </a>
                                        )}
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{batch.rows_total}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{batch.rows_success}</span>
                                    </TD>
                                    <TD align="right">
                                        <span className="font-mono tabular">{batch.rows_failed}</span>
                                    </TD>
                                    <TD>
                                        <Badge tone={STATUS_TONES[batch.status] ?? 'neutral'}>{batch.status_label}</Badge>
                                        {batch.message && <span className="mt-0.5 block max-w-[16rem] text-[0.75rem] text-[var(--muted)]">{batch.message}</span>}
                                        {canRun && batch.status === 'pending' && (
                                            <span className="mt-1 flex gap-2 text-[0.75rem] font-medium">
                                                <button
                                                    type="button"
                                                    className="text-[var(--accent)] hover:underline"
                                                    onClick={() => router.post(route('platform.imports.process', batch.id), {}, { preserveScroll: true })}
                                                >
                                                    Procesar
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-[var(--muted)] hover:underline"
                                                    onClick={() => router.delete(route('platform.imports.discard', batch.id), { preserveScroll: true })}
                                                >
                                                    Descartar
                                                </button>
                                            </span>
                                        )}
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}

function PreviewCard({ preview, canRun }: { preview: Preview; canRun: boolean }) {
    const process = () => router.post(route('platform.imports.process', preview.batch_id), {}, { preserveScroll: true });
    const discard = () => router.delete(route('platform.imports.discard', preview.batch_id), { preserveScroll: true });

    return (
        <Card className="mb-5 border-[var(--accent)]">
            <CardHeader
                title={`Vista previa: ${preview.filename}`}
                description={`${preview.company ? `Para ${preview.company} · ` : ''}Nada se ha guardado todavía.`}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Stat label="Filas" value={preview.rows} />
                <Stat label="Nuevos" value={preview.created} tone="accent" />
                <Stat label="Actualizan" value={preview.updated} />
                <Stat label="Se omiten" value={preview.skipped} tone={preview.skipped > 0 ? 'danger' : undefined} />
            </div>

            {preview.sample.length > 0 && (
                <div className="mb-4 overflow-x-auto rounded-control border border-[var(--line)]">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Fila</TH>
                                <TH>Registro</TH>
                                <TH>Qué pasa</TH>
                                <TH>Motivo</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {preview.sample.map((row) => (
                                <TR key={row.line}>
                                    <TD>
                                        <span className="font-mono tabular">{row.line}</span>
                                    </TD>
                                    <TD>{row.label}</TD>
                                    <TD>
                                        <Badge tone={ACTIONS[row.action].tone}>{ACTIONS[row.action].label}</Badge>
                                    </TD>
                                    <TD>
                                        <span className="text-[0.8125rem] text-[var(--muted)]">{row.reason ?? ''}</span>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </div>
            )}

            {preview.errors.length > 0 && (
                <ul className="mb-4 flex flex-col gap-1 text-[0.8125rem] text-[var(--danger)]">
                    {preview.errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}

            {canRun && (
                <div className="flex flex-wrap gap-2">
                    {preview.created + preview.updated > 0 && (
                        <Button onClick={process}>
                            Procesar {preview.created + preview.updated} {preview.created + preview.updated === 1 ? 'fila' : 'filas'}
                        </Button>
                    )}
                    <Button variant="secondary" onClick={discard}>
                        Descartar
                    </Button>
                </div>
            )}
        </Card>
    );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'accent' | 'danger' }) {
    return (
        <div className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2">
            <p className="text-[0.75rem] text-[var(--muted)]">{label}</p>
            <p
                className={cn(
                    'font-mono text-[1.25rem] font-semibold tabular',
                    tone === 'accent' && 'text-[var(--accent)]',
                    tone === 'danger' && 'text-[var(--danger)]',
                )}
            >
                {value}
            </p>
        </div>
    );
}
