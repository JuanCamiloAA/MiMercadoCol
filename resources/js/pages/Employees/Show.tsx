import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, KeyIcon, PencilSimpleIcon, PlusIcon, ProhibitIcon, ShieldCheckIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader, StatCard } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Avatar, Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { date, money, number } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

interface EmployeeShowProps {
    employee: {
        id: number;
        first_name: string;
        last_name: string;
        full_name: string;
        document_type: string;
        document_number: string;
        phone: string | null;
        email: string | null;
        address: string | null;
        birth_date: string | null;
        emergency_contact_name: string | null;
        emergency_contact_phone: string | null;
        position: string;
        contract_type: string;
        hired_at: string | null;
        terminated_at: string | null;
        status: string;
        work_schedule: Record<string, { start: string; end: string }>;
        store: { id: number; name: string } | null;
        user: {
            id: number;
            email: string;
            is_active: boolean;
            has_pin: boolean;
            password_change_required: boolean;
            last_login_at: string | null;
            role: { name: string; display_name: string; color: string } | null;
        } | null;
    };
    performance: { sales_total: number; tickets: number; has_account: boolean };
    roles: { value: string; label: string; color: string }[];
}

export default function EmployeeShow({ employee, performance, roles }: EmployeeShowProps) {
    const { can } = usePermission();
    const { flash } = usePage<PageProps>().props;
    const [modal, setModal] = useState<'access' | 'role' | 'pin' | null>(null);

    useEffect(() => {
        if (flash?.temporary_password) {
            toast.success(`Contrasena temporal: ${flash.temporary_password}`, {
                duration: 20000,
                description: 'Copiala ahora: no se vuelve a mostrar.',
            });
        }
    }, [flash?.temporary_password]);

    const accessForm = useForm({
        email: employee.email ?? '',
        role: roles[0]?.value ?? '',
        pin: '',
    });

    const roleForm = useForm({ role: employee.user?.role?.name ?? roles[0]?.value ?? '' });
    const pinForm = useForm({ pin: '' });

    const submitAccess = (event: FormEvent) => {
        event.preventDefault();
        accessForm.post(route('employees.access.store', employee.id), {
            preserveScroll: true,
            onSuccess: () => setModal(null),
        });
    };

    const submitRole = (event: FormEvent) => {
        event.preventDefault();
        roleForm.patch(route('employees.access.change_role', employee.id), {
            preserveScroll: true,
            onSuccess: () => setModal(null),
        });
    };

    const submitPin = (event: FormEvent) => {
        event.preventDefault();
        pinForm.post(route('employees.access.reset_pin', employee.id), {
            preserveScroll: true,
            onSuccess: () => {
                pinForm.reset();
                setModal(null);
            },
        });
    };

    const scheduleDays = Object.entries(employee.work_schedule ?? {});

    return (
        <AppLayout title={employee.full_name}>
            <PageHeader
                title={employee.full_name}
                description={`${employee.position}${employee.store ? ` · Sede ${employee.store.name}` : ''}`}
                breadcrumb={
                    <Link href={route('employees.index')} className="hover:text-[var(--accent)]">
                        Empleados
                    </Link>
                }
                actions={
                    <>
                        <ButtonLink href={route('employees.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                            Volver
                        </ButtonLink>
                        {can('employees.index.edit') && (
                            <ButtonLink href={route('employees.edit', employee.id)} icon={<PencilSimpleIcon size={16} />}>
                                Editar
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[330px_1fr]">
                <div className="flex flex-col gap-4">
                    <Card>
                        <div className="flex items-center gap-3">
                            <Avatar name={employee.full_name} size={52} />
                            <div className="min-w-0">
                                <p className="truncate text-[0.9375rem] font-semibold text-[var(--text)]">{employee.full_name}</p>
                                <p className="font-mono text-[0.78125rem] text-[var(--muted)] tabular">
                                    {employee.document_type} {employee.document_number}
                                </p>
                                <span className="mt-1 inline-block">
                                    {employee.status === 'active' ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Inactivo</Badge>}
                                </span>
                            </div>
                        </div>

                        <dl className="mt-4 flex flex-col gap-2.5 border-t border-[var(--line)] pt-4 text-[0.8125rem]">
                            <Row label="Telefono" value={employee.phone} mono />
                            <Row label="Correo" value={employee.email} />
                            <Row label="Direccion" value={employee.address} />
                            <Row label="Nacimiento" value={employee.birth_date ? date(employee.birth_date) : null} />
                            <Row label="Contrato" value={employee.contract_type} />
                            <Row label="Ingreso" value={employee.hired_at ? date(employee.hired_at) : null} />
                            {employee.terminated_at && <Row label="Retiro" value={date(employee.terminated_at)} />}
                            <Row label="Emergencia" value={employee.emergency_contact_name} />
                            <Row label="Tel. emergencia" value={employee.emergency_contact_phone} mono />
                        </dl>

                        <div className="mt-4 flex gap-2 border-t border-[var(--line)] pt-4">
                            {employee.status === 'active' && can('employees.index.deactivate') && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={<ProhibitIcon size={15} />}
                                    onClick={() => router.patch(route('employees.deactivate', employee.id), {}, { preserveScroll: true })}
                                >
                                    Desactivar
                                </Button>
                            )}
                            {employee.status !== 'active' && can('employees.index.reactivate') && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.patch(route('employees.reactivate', employee.id), {}, { preserveScroll: true })}
                                >
                                    Reactivar
                                </Button>
                            )}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Turnos de la semana" />
                        {scheduleDays.length === 0 ? (
                            <p className="text-[0.8125rem] text-[var(--muted)]">Sin horario registrado.</p>
                        ) : (
                            <ul className="flex flex-col gap-1.5 text-[0.8125rem]">
                                {scheduleDays.map(([day, hours]) => (
                                    <li key={day} className="flex items-center justify-between">
                                        <span className="capitalize text-[var(--muted)]">{day}</span>
                                        <span className="font-mono tabular">
                                            {hours.start} – {hours.end}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader
                            title="Acceso al sistema"
                            description="La cuenta con la que entra esta persona al sistema."
                            action={
                                !employee.user && can('employees.access.create') ? (
                                    <Button onClick={() => setModal('access')} icon={<PlusIcon size={16} weight="bold" />}>
                                        Crear cuenta
                                    </Button>
                                ) : undefined
                            }
                        />

                        {!employee.user ? (
                            <p className="text-[0.8125rem] text-[var(--muted)]">
                                Este empleado todavia no puede entrar al sistema. Crearle la cuenta le genera una contrasena temporal.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <InfoBox label="Correo de acceso" value={employee.user.email} />
                                    <InfoBox
                                        label="Rol"
                                        value={
                                            employee.user.role ? (
                                                <Badge dotColor={employee.user.role.color} tone="neutral">
                                                    {employee.user.role.display_name}
                                                </Badge>
                                            ) : (
                                                'Sin rol'
                                            )
                                        }
                                    />
                                    <InfoBox
                                        label="Estado"
                                        value={employee.user.is_active ? <Badge tone="accent">Activo</Badge> : <Badge tone="neutral">Desactivado</Badge>}
                                    />
                                    <InfoBox
                                        label="PIN de caja"
                                        value={employee.user.has_pin ? <Badge tone="accent">Definido</Badge> : <Badge tone="warn">Sin PIN</Badge>}
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 border-t border-[var(--line)] pt-4">
                                    {can('employees.access.toggle') && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => router.patch(route('employees.access.toggle', employee.id), {}, { preserveScroll: true })}
                                        >
                                            {employee.user.is_active ? 'Desactivar acceso' : 'Activar acceso'}
                                        </Button>
                                    )}
                                    {can('employees.access.change_role') && (
                                        <Button variant="secondary" size="sm" icon={<ShieldCheckIcon size={15} />} onClick={() => setModal('role')}>
                                            Cambiar rol
                                        </Button>
                                    )}
                                    {can('employees.access.reset_password') && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            icon={<KeyIcon size={15} />}
                                            onClick={() => router.post(route('employees.access.reset_password', employee.id), {}, { preserveScroll: true })}
                                        >
                                            Restablecer contrasena
                                        </Button>
                                    )}
                                    {can('employees.access.reset_pin') && (
                                        <Button variant="secondary" size="sm" onClick={() => setModal('pin')}>
                                            Restablecer PIN
                                        </Button>
                                    )}
                                    {can('users.edit.permissions') && (
                                        <ButtonLink href={route('users.permissions', employee.user.id)} variant="ghost" size="sm">
                                            Ver permisos
                                        </ButtonLink>
                                    )}
                                </div>
                            </div>
                        )}
                    </Card>

                    {performance.has_account && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <StatCard label="Ventas del mes" value={money(performance.sales_total)} />
                            <StatCard label="Tiquetes del mes" value={number(performance.tickets)} tone="neutral" />
                        </div>
                    )}
                </div>
            </div>

            <Modal
                open={modal === 'access'}
                onClose={() => setModal(null)}
                title="Crear la cuenta de acceso"
                description="Se genera una contrasena temporal que se muestra una sola vez."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitAccess} loading={accessForm.processing}>
                            Crear la cuenta
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitAccess} className="flex flex-col gap-4">
                    <Input
                        label="Correo de acceso"
                        type="email"
                        required
                        value={accessForm.data.email}
                        onChange={(event) => accessForm.setData('email', event.target.value)}
                        error={accessForm.errors.email}
                    />
                    <Select
                        label="Rol"
                        required
                        options={roles.map((role) => ({ value: role.value, label: role.label }))}
                        value={accessForm.data.role}
                        onChange={(event) => accessForm.setData('role', event.target.value)}
                        error={accessForm.errors.role}
                    />
                    <Input
                        label="PIN de caja"
                        inputMode="numeric"
                        maxLength={4}
                        value={accessForm.data.pin}
                        onChange={(event) => accessForm.setData('pin', event.target.value.replace(/\D/g, ''))}
                        error={accessForm.errors.pin}
                        hint="4 digitos, solo si va a abrir caja."
                    />
                </form>
            </Modal>

            <Modal
                open={modal === 'role'}
                onClose={() => setModal(null)}
                title="Cambiar el rol de la cuenta"
                description="Los permisos de la persona se reemplazan por los de la plantilla del rol."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitRole} loading={roleForm.processing}>
                            Cambiar el rol
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitRole}>
                    <Select
                        label="Rol"
                        required
                        options={roles.map((role) => ({ value: role.value, label: role.label }))}
                        value={roleForm.data.role}
                        onChange={(event) => roleForm.setData('role', event.target.value)}
                        error={roleForm.errors.role}
                    />
                </form>
            </Modal>

            <Modal
                open={modal === 'pin'}
                onClose={() => setModal(null)}
                title="Restablecer el PIN de caja"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={submitPin} loading={pinForm.processing}>
                            Guardar el PIN
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitPin}>
                    <Input
                        label="PIN nuevo"
                        inputMode="numeric"
                        maxLength={4}
                        required
                        value={pinForm.data.pin}
                        onChange={(event) => pinForm.setData('pin', event.target.value.replace(/\D/g, ''))}
                        error={pinForm.errors.pin}
                        hint="4 digitos."
                    />
                </form>
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value, mono }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
            <dd className={`text-right ${mono ? 'font-mono text-[0.78125rem] tabular' : ''}`}>{value || '—'}</dd>
        </div>
    );
}

function InfoBox({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2.5">
            <p className="text-[0.71875rem] uppercase tracking-wide text-[var(--muted)]">{label}</p>
            <div className="mt-1 text-[0.84375rem] text-[var(--text)]">{value}</div>
        </div>
    );
}
