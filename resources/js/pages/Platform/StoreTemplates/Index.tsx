import { Link, router, useForm } from '@inertiajs/react';
import { CheckCircleIcon, PaintBrushIcon, PencilSimpleIcon, PlusIcon, PowerIcon, StarIcon, StorefrontIcon } from '@phosphor-icons/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select, Textarea } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime } from '@/lib/format';

interface TemplateRow {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    published_at: string | null;
    published_by: string | null;
    followers: number;
    has_unpublished_changes: boolean;
    blocks: number;
    preview: string | null;
}

interface IndexProps {
    templates: TemplateRow[];
    can: { edit: boolean; publish: boolean };
}

/**
 * Plataforma > Plantillas de tienda.
 *
 * El diseño con que nacen las tiendas nuevas. Las que no se han personalizado la siguen y
 * reciben cada publicación; las que se personalizaron se quedaron con su copia.
 */
export default function StoreTemplatesIndex({ templates, can }: IndexProps) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '', description: '', copy_from: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.store_templates.store'), { onSuccess: () => setCreating(false) });
    };

    return (
        <AppLayout title="Plantillas de tienda">
            <PageHeader
                title="Plantillas de tienda"
                description="El diseño con que nacen las tiendas en línea. Las tiendas que la siguen reciben cada publicación; al personalizarla, se independizan."
                actions={
                    can.edit && (
                        <Button icon={<PlusIcon size={16} weight="bold" />} onClick={() => setCreating(true)}>
                            Nueva plantilla
                        </Button>
                    )
                }
            />

            {templates.length === 0 ? (
                <Card>
                    <EmptyState title="Todavía no hay plantillas" description="Crea la primera: las empresas nuevas nacerán con ella." />
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {templates.map((template) => (
                        <li key={template.id}>
                            <Card className="flex h-full flex-col gap-3">
                                <div className="grid aspect-[16/7] place-items-center overflow-hidden rounded-control bg-[var(--surface-2)] text-[var(--muted)]">
                                    {template.preview ? <img src={template.preview} alt="" className="h-full w-full object-cover" /> : <PaintBrushIcon size={36} />}
                                </div>

                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <h2 className="text-[1.0625rem] font-semibold">{template.name}</h2>
                                        {template.is_default && <Badge tone="accent">Por defecto</Badge>}
                                        {!template.is_active && <Badge tone="neutral">Inactiva</Badge>}
                                        {template.has_unpublished_changes && <Badge tone="warn">{template.published_at ? 'Cambios sin publicar' : 'Sin publicar'}</Badge>}
                                    </div>
                                    {template.description && <p className="mt-1 text-[0.8125rem] text-[var(--muted)]">{template.description}</p>}
                                </div>

                                <dl className="grid grid-cols-2 gap-2 text-[0.8125rem]">
                                    <div className="rounded-control bg-[var(--surface-2)] px-3 py-2">
                                        <dt className="flex items-center gap-1 text-[var(--muted)]">
                                            <StorefrontIcon size={14} /> La siguen
                                        </dt>
                                        <dd className="text-[1.125rem] font-semibold tabular">{template.followers}</dd>
                                    </div>
                                    <div className="rounded-control bg-[var(--surface-2)] px-3 py-2">
                                        <dt className="text-[var(--muted)]">Bloques</dt>
                                        <dd className="text-[1.125rem] font-semibold tabular">{template.blocks}</dd>
                                    </div>
                                </dl>

                                <p className="text-[0.75rem] text-[var(--muted)]">
                                    {template.published_at ? `Publicada ${dateTime(template.published_at)}${template.published_by ? ` por ${template.published_by}` : ''}` : 'Nunca se ha publicado.'}
                                </p>

                                <div className="mt-auto flex flex-wrap gap-2">
                                    <Link
                                        href={route('platform.store_templates.edit', template.id)}
                                        className="inline-flex min-h-[2.5rem] items-center gap-1.5 rounded-control bg-[var(--accent)] px-3.5 text-[0.8125rem] font-semibold text-[var(--accent-ink)]"
                                    >
                                        <PencilSimpleIcon size={15} /> Editar
                                    </Link>
                                    {can.publish && !template.is_default && template.is_active && template.published_at && (
                                        <Button variant="secondary" size="sm" icon={<StarIcon size={15} />} onClick={() => router.post(route('platform.store_templates.default', template.id), {}, { preserveScroll: true })}>
                                            Usar por defecto
                                        </Button>
                                    )}
                                    {can.publish && !template.is_default && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon={template.is_active ? <PowerIcon size={15} /> : <CheckCircleIcon size={15} />}
                                            onClick={() => router.post(route('platform.store_templates.toggle', template.id), { is_active: !template.is_active }, { preserveScroll: true })}
                                        >
                                            {template.is_active ? 'Desactivar' : 'Activar'}
                                        </Button>
                                    )}
                                </div>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

            <Modal
                open={creating}
                onClose={() => setCreating(false)}
                title="Nueva plantilla"
                size="md"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreating(false)}>
                            Cancelar
                        </Button>
                        <Button loading={form.processing} onClick={submit}>
                            Crear y editar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <Input label="Nombre" required autoFocus maxLength={80} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} placeholder="Supermercado" />
                    <Textarea label="Descripción" rows={2} maxLength={500} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                    {templates.length > 0 && (
                        <Select
                            label="Partir de"
                            placeholder="Una plantilla en blanco"
                            options={templates.map((template) => ({ value: String(template.id), label: template.name }))}
                            value={form.data.copy_from}
                            onChange={(event) => form.setData('copy_from', event.target.value)}
                        />
                    )}
                </form>
            </Modal>
        </AppLayout>
    );
}
