import { router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, PowerIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { landingIcon } from '@/components/landing/landing-icons';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Toggle } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { usePermission } from '@/lib/permissions';

interface CategoryRow {
    id: number;
    name: string;
    slug: string;
    icon: string;
    is_active: boolean;
    sort_order: number;
    companies_count: number;
}

/**
 * Plataforma > Clasificaciones: el tipo de comercio de cada empresa. Se elige al crearla o al
 * contratar, y la landing agrupa por aquí las tiendas y las empresas.
 */
export default function BusinessCategoriesIndex({ categories, icons }: { categories: CategoryRow[]; icons: string[] }) {
    const { can } = usePermission();
    const [editing, setEditing] = useState<CategoryRow | 'new' | null>(null);

    return (
        <AppLayout title="Clasificaciones">
            <PageHeader
                title="Clasificaciones de empresa"
                description="Alimentos, textiles, belleza… Cada empresa tiene una: se elige al crearla o al contratar, y la landing agrupa las tiendas por aquí."
                actions={
                    can('platform.business_categories.create') && (
                        <Button onClick={() => setEditing('new')}>
                            <PlusIcon size={16} /> Nueva clasificación
                        </Button>
                    )
                }
            />

            <Card padded={false}>
                {categories.length === 0 ? (
                    <EmptyState title="No hay clasificaciones" description="Crea al menos una para poder crear o contratar empresas." />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>Clasificación</TH>
                                <TH align="right">Orden</TH>
                                <TH align="right">Empresas</TH>
                                <TH>Estado</TH>
                                <TH />
                            </TR>
                        </THead>
                        <TBody>
                            {categories.map((category) => {
                                const CategoryIcon = landingIcon(category.icon);

                                return (
                                    <TR key={category.id}>
                                        <TD>
                                            <span className="flex items-center gap-2.5">
                                                <span className="grid size-9 shrink-0 place-items-center rounded-control bg-[var(--accent-soft)] text-[var(--accent)]">
                                                    <CategoryIcon size={18} />
                                                </span>
                                                <span>
                                                    <span className="block font-semibold">{category.name}</span>
                                                    <span className="block font-mono text-[0.75rem] text-[var(--muted)]">{category.slug}</span>
                                                </span>
                                            </span>
                                        </TD>
                                        <TD align="right">
                                            <span className="font-mono tabular">{category.sort_order}</span>
                                        </TD>
                                        <TD align="right">
                                            <span className="font-mono tabular">{category.companies_count}</span>
                                        </TD>
                                        <TD>{category.is_active ? <Badge tone="accent">Activa</Badge> : <Badge tone="neutral">Desactivada</Badge>}</TD>
                                        <TD align="right">
                                            <div className="flex justify-end gap-1">
                                                {can('platform.business_categories.edit') && (
                                                    <button
                                                        type="button"
                                                        title="Editar"
                                                        onClick={() => setEditing(category)}
                                                        className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        <PencilSimpleIcon size={16} />
                                                    </button>
                                                )}
                                                {can('platform.business_categories.deactivate') && (
                                                    <button
                                                        type="button"
                                                        title={category.is_active ? 'Desactivar' : 'Activar'}
                                                        onClick={() => router.post(route('platform.business_categories.toggle', category.id), {}, { preserveScroll: true })}
                                                        className="grid size-8 place-items-center rounded-control text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                                    >
                                                        <PowerIcon size={16} />
                                                    </button>
                                                )}
                                            </div>
                                        </TD>
                                    </TR>
                                );
                            })}
                        </TBody>
                    </Table>
                )}
            </Card>
            <p className="mt-3 text-[0.78125rem] text-[var(--muted)]">Desactivar una clasificación no se la quita a nadie: solo deja de ofrecerse al crear o contratar.</p>

            {editing !== null && <CategoryDialog category={editing === 'new' ? null : editing} icons={icons} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function CategoryDialog({ category, icons, onClose }: { category: CategoryRow | null; icons: string[]; onClose: () => void }) {
    const form = useForm({
        name: category?.name ?? '',
        icon: category?.icon ?? icons[0] ?? 'Storefront',
        is_active: category?.is_active ?? true,
        sort_order: category ? String(category.sort_order) : '0',
    });
    const { data, setData, errors } = form;

    const submit = () => {
        form.transform((values) => ({ ...values, sort_order: Number(values.sort_order || 0) }));

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (category) {
            form.put(route('platform.business_categories.update', category.id), options);
        } else {
            form.post(route('platform.business_categories.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={category ? category.name : 'Nueva clasificación'}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-[1fr_7rem]">
                <Input label="Nombre" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name ?? (errors as Record<string, string>).slug} placeholder="Textiles y confección" />
                <Input label="Orden" inputMode="numeric" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value.replace(/\D/g, ''))} error={errors.sort_order} />
            </div>

            <fieldset className="mt-4">
                <legend className="mb-2 text-[0.8125rem] font-medium">Ícono</legend>
                <div className="grid grid-cols-6 gap-1.5 sm:grid-cols-9" role="radiogroup" aria-label="Ícono">
                    {icons.map((name) => {
                        const IconComponent = landingIcon(name);
                        const selected = data.icon === name;

                        return (
                            <button
                                key={name}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                title={name}
                                onClick={() => setData('icon', name)}
                                className={cn(
                                    'grid aspect-square place-items-center rounded-control border transition-colors',
                                    selected ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]' : 'border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                                )}
                            >
                                <IconComponent size={20} />
                            </button>
                        );
                    })}
                </div>
                {errors.icon && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.icon}</p>}
            </fieldset>

            <div className="mt-4">
                <Toggle checked={data.is_active} onChange={(value) => setData('is_active', value)} label="Activa (se ofrece al crear y al contratar)" />
                {errors.is_active && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.is_active}</p>}
            </div>
        </Modal>
    );
}
