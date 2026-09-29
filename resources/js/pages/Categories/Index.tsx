import { router, useForm } from '@inertiajs/react';
import { PencilSimpleIcon, PlusIcon, SquaresFourIcon, TrashIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { type FormEvent, useState } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { EmptyState, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { StoreIcon } from '@/components/storefront/icons';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { usePermission } from '@/lib/permissions';

interface CategoryRow {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
    image: string | null;
    sort_order: number;
    is_active: boolean;
    products_count: number;
    parent: { id: number; name: string } | null;
}

interface CategoriesIndexProps {
    categories: CategoryRow[];
    parents: { value: number; label: string }[];
    /** Los íconos de la tienda en línea. */
    icons: string[];
}

export default function CategoriesIndex({ categories, parents, icons }: CategoriesIndexProps) {
    const { can } = usePermission();
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [toDelete, setToDelete] = useState<CategoryRow | null>(null);
    const [busy, setBusy] = useState(false);

    const form = useForm({
        name: '',
        parent_id: '' as string | number,
        icon: '',
        sort_order: 0,
        is_active: true as boolean,
        image: null as File | null,
        remove_image: false as boolean,
    });

    const openCreate = () => {
        form.setData({ name: '', parent_id: '', icon: '', sort_order: categories.length + 1, is_active: true, image: null, remove_image: false });
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (category: CategoryRow) => {
        form.setData({
            name: category.name,
            parent_id: category.parent?.id ?? '',
            icon: category.icon ?? '',
            sort_order: category.sort_order,
            is_active: category.is_active,
            image: null,
            remove_image: false,
        });
        form.clearErrors();
        setEditing(category);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Con foto viaja como formulario: un PUT con archivo se manda como POST con `_method`.
        if (editing) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('categories.update', editing.id), {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => setEditing(null),
            });
        } else {
            form.transform((data) => data);
            form.post(route('categories.store'), {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => setCreating(false),
            });
        }
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        setBusy(true);
        router.delete(route('categories.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setToDelete(null);
            },
        });
    };

    const modalOpen = creating || editing !== null;

    return (
        <AppLayout title="Categorias">
            <PageHeader
                title="Categorias"
                description="Organizan el catalogo, el punto de venta y la tienda en linea."
                actions={
                    <>
                        {can('categories.index.import') && (
                            <ButtonLink href={route('categories.import.form')} variant="secondary" icon={<UploadSimpleIcon size={16} />}>
                                Importar
                            </ButtonLink>
                        )}
                        {can('categories.index.create') && (
                            <Button onClick={openCreate} icon={<PlusIcon size={16} weight="bold" />}>
                                Nueva categoria
                            </Button>
                        )}
                    </>
                }
            />

            {categories.length === 0 ? (
                <EmptyState
                    title="Todavia no hay categorias"
                    description="Crea la primera para poder registrar articulos."
                    icon={<SquaresFourIcon size={24} />}
                />
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>Categoria</TH>
                            <TH>Depende de</TH>
                            <TH align="center">Orden</TH>
                            <TH align="center">Articulos</TH>
                            <TH align="center">Estado</TH>
                            <TH align="right">Acciones</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {categories.map((category) => (
                            <TR key={category.id}>
                                <TD>
                                    <div className="flex items-center gap-2.5">
                                        <span className="grid size-9 shrink-0 place-items-center overflow-hidden rounded-full bg-[var(--accent-soft)] text-[var(--accent)]">
                                            {category.image ? <img src={category.image} alt="" className="h-full w-full object-cover" /> : <StoreIcon name={category.icon ?? 'Basket'} size={18} weight="fill" />}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[0.84375rem] font-medium text-[var(--text)]">{category.name}</span>
                                            <span className="block font-mono text-[0.71875rem] text-[var(--muted)]">{category.slug}</span>
                                        </span>
                                    </div>
                                </TD>
                                <TD className="text-[0.78125rem] text-[var(--muted)]">{category.parent?.name ?? '—'}</TD>
                                <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                    {category.sort_order}
                                </TD>
                                <TD align="center" className="font-mono text-[0.8125rem] tabular">
                                    {category.products_count}
                                </TD>
                                <TD align="center">
                                    {category.is_active ? <Badge tone="accent">Activa</Badge> : <Badge tone="neutral">Inactiva</Badge>}
                                </TD>
                                <TD align="right">
                                    <div className="flex items-center justify-end gap-1">
                                        {can('categories.index.edit') && (
                                            <Button size="sm" variant="ghost" icon={<PencilSimpleIcon size={15} />} onClick={() => openEdit(category)} />
                                        )}
                                        {can('categories.index.delete') && category.products_count === 0 && (
                                            <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={() => setToDelete(category)} />
                                        )}
                                    </div>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}

            <Modal
                open={modalOpen}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? 'Editar la categoria' : 'Nueva categoria'}
                size="sm"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setCreating(false);
                                setEditing(null);
                            }}
                        >
                            Cancelar
                        </Button>
                        <Button onClick={submit} loading={form.processing}>
                            Guardar
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Input
                        label="Nombre"
                        required
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />
                    <Select
                        label="Depende de"
                        options={parents.filter((parent) => parent.value !== editing?.id)}
                        placeholder="Categoria principal"
                        value={form.data.parent_id}
                        onChange={(event) => form.setData('parent_id', event.target.value)}
                        error={form.errors.parent_id}
                    />
                    <Input
                        label="Orden"
                        type="number"
                        min={0}
                        value={form.data.sort_order}
                        onChange={(event) => form.setData('sort_order', Number(event.target.value))}
                        error={form.errors.sort_order}
                    />
                    <div>
                        <p className="mb-1.5 text-[0.78125rem] font-medium text-[var(--text)]">Ícono en la tienda en línea</p>
                        <div className="grid max-h-[8.5rem] grid-cols-8 gap-1 overflow-y-auto rounded-control border border-[var(--line)] p-1.5">
                            {icons.map((icon) => (
                                <button
                                    key={icon}
                                    type="button"
                                    title={icon}
                                    aria-label={icon}
                                    aria-pressed={form.data.icon === icon}
                                    onClick={() => form.setData('icon', form.data.icon === icon ? '' : icon)}
                                    className={cn(
                                        'grid aspect-square min-h-[2.125rem] place-items-center rounded-chip transition-colors',
                                        form.data.icon === icon ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--text)]',
                                    )}
                                >
                                    <StoreIcon name={icon} size={16} />
                                </button>
                            ))}
                        </div>
                        {form.errors.icon && <p className="mt-1 text-[0.75rem] text-[var(--danger)]">{form.errors.icon}</p>}
                    </div>

                    <div>
                        <p className="mb-1.5 text-[0.78125rem] font-medium text-[var(--text)]">Foto en la tienda en línea</p>
                        {editing?.image && !form.data.remove_image && !form.data.image && (
                            <div className="mb-2 flex items-center gap-3">
                                <img src={editing.image} alt="" className="size-14 rounded-full object-cover" />
                                <Button size="sm" variant="ghost" onClick={() => form.setData('remove_image', true)}>
                                    Quitar la foto
                                </Button>
                            </div>
                        )}
                        <input
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp"
                            onChange={(event) => form.setData('image', event.target.files?.[0] ?? null)}
                            className="block w-full text-[0.8125rem] file:mr-3 file:rounded-control file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-2 file:text-[0.8125rem] file:font-medium"
                        />
                        <p className="mt-1 text-[0.71875rem] text-[var(--muted)]">Opcional. Sin foto, la tienda muestra el ícono.</p>
                        {form.errors.image && <p className="mt-1 text-[0.75rem] text-[var(--danger)]">{form.errors.image}</p>}
                    </div>

                    <Checkbox
                        label="Categoria activa"
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                    />
                </form>
            </Modal>

            <ConfirmDialog
                open={toDelete !== null}
                onClose={() => setToDelete(null)}
                onConfirm={confirmDelete}
                loading={busy}
                title="Eliminar la categoria"
                confirmLabel="Eliminar"
                message={
                    <>
                        Vas a eliminar <strong>{toDelete?.name}</strong>. Solo se puede si no tiene articulos ni subcategorias.
                    </>
                }
            />
        </AppLayout>
    );
}
