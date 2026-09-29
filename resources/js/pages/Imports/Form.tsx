import { useForm } from '@inertiajs/react';
import { ArrowLeftIcon, CheckCircleIcon, DownloadSimpleIcon, FileCsvIcon, WarningIcon } from '@phosphor-icons/react';
import { useState, type FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { number } from '@/lib/format';

interface Column {
    name: string;
    hint: string;
    required: boolean;
}

interface ImportResult {
    created: number;
    updated: number;
    skipped: number;
    errors: string[];
}

interface ImportFormProps {
    module: string;
    title: string;
    description: string;
    columns: Column[];
    example: string[];
    notes: string[];
    indexRoute: string;
    submitRoute: string;
    result: ImportResult | null;
}

export default function ImportForm({
    module,
    title,
    description,
    columns,
    example,
    notes,
    indexRoute,
    submitRoute,
    result,
}: ImportFormProps) {
    const { setData, post, processing, errors, progress } = useForm<{ file: File | null }>({ file: null });
    const [chosen, setChosen] = useState<string | null>(null);

    const header = columns.map((column) => column.name).join(';');
    const sample = [header, example.join(';')].join('\n');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route(submitRoute), { forceFormData: true });
    };

    /** La plantilla se arma aqui con las mismas columnas que espera el servidor. */
    const downloadTemplate = () => {
        // El BOM hace que Excel abra las tildes bien en vez de mostrar simbolos raros.
        const blob = new Blob([`﻿${sample}\n`], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = `plantilla-${module}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <AppLayout title={title}>
            <PageHeader
                title={title}
                description={description}
                actions={
                    <ButtonLink href={route(indexRoute)} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            {result && (
                <Card className="mb-4">
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <span className="flex items-center gap-1.5 text-[0.84375rem] text-[var(--text)]">
                            <CheckCircleIcon size={18} weight="fill" className="text-[var(--accent)]" />
                            {number(result.created)} nuevos · {number(result.updated)} actualizados
                        </span>
                        {result.skipped > 0 && (
                            <span className="flex items-center gap-1.5 text-[0.84375rem] text-[var(--warn)]">
                                <WarningIcon size={18} weight="fill" />
                                {number(result.skipped)} filas quedaron por fuera
                            </span>
                        )}
                    </div>

                    {result.errors.length > 0 && (
                        <ul className="mt-3 flex flex-col gap-1 border-t border-[var(--line)] pt-3 text-[0.78125rem] text-[var(--muted)]">
                            {result.errors.map((error) => (
                                <li key={error}>· {error}</li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Archivo" description="CSV separado por punto y coma, como lo guarda Excel." />
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-card border border-dashed border-[var(--line-2)] px-4 py-10 text-center transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]">
                            <FileCsvIcon size={32} className="text-[var(--muted)]" />
                            <span className="text-[0.84375rem] font-medium text-[var(--text)]">
                                {chosen ?? 'Elegir el archivo CSV'}
                            </span>
                            <span className="text-[0.75rem] text-[var(--muted)]">Maximo 2 MB</span>
                            <input
                                type="file"
                                accept=".csv,text/csv"
                                className="hidden"
                                onChange={(event) => {
                                    const file = event.target.files?.[0] ?? null;
                                    setData('file', file);
                                    setChosen(file?.name ?? null);
                                }}
                            />
                        </label>

                        {errors.file && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{errors.file}</p>}

                        {progress && (
                            <div className="h-1.5 overflow-hidden rounded-full bg-[var(--surface-3)]">
                                <div className="h-full bg-[var(--accent)]" style={{ width: `${progress.percentage}%` }} />
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" size="lg" loading={processing} className="flex-1">
                                Importar
                            </Button>
                            <Button
                                type="button"
                                size="lg"
                                variant="secondary"
                                onClick={downloadTemplate}
                                icon={<DownloadSimpleIcon size={16} weight="bold" />}
                            >
                                Plantilla
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card>
                    <CardHeader title="Columnas" description="La primera fila son los nombres, en minusculas y en este orden." />
                    <pre className="overflow-x-auto rounded-control bg-[var(--surface-2)] p-3 font-mono text-[0.75rem] leading-relaxed text-[var(--text)]">
                        {sample}
                    </pre>

                    <ul className="mt-3 flex flex-col gap-1.5">
                        {columns.map((column) => (
                            <li key={column.name} className="flex items-baseline gap-2 text-[0.78125rem]">
                                <span className="font-mono text-[var(--text)]">{column.name}</span>
                                {column.required && <Badge tone="warn">obligatoria</Badge>}
                                <span className="text-[var(--muted)]">{column.hint}</span>
                            </li>
                        ))}
                    </ul>

                    {notes.length > 0 && (
                        <ul className="mt-3 flex flex-col gap-1 border-t border-[var(--line)] pt-3 text-[0.78125rem] text-[var(--muted)]">
                            {notes.map((note) => (
                                <li key={note}>· {note}</li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
