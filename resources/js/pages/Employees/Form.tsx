import { useForm } from '@inertiajs/react';
import { ArrowLeftIcon } from '@phosphor-icons/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Checkbox, Input, Select } from '@/components/ui/Field';
import { PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';

type Schedule = Record<string, { start: string; end: string }>;

interface EmployeeFormProps {
    mode: 'create' | 'edit';
    employee: {
        id: number;
        store_id: number;
        first_name: string;
        last_name: string;
        document_type: string;
        document_number: string;
        birth_date: string | null;
        phone: string | null;
        email: string | null;
        address: string | null;
        emergency_contact_name: string | null;
        emergency_contact_phone: string | null;
        position: string;
        contract_type: string;
        hired_at: string | null;
        work_schedule: Schedule;
    } | null;
    stores: { value: number; label: string }[];
    roles: { value: string; label: string; color: string }[];
    canCreateAccess: boolean;
}

const DAYS = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as const;

const DOCUMENT_TYPES = [
    { value: 'CC', label: 'Cédula de ciudadanía' },
    { value: 'CE', label: 'Cédula de extranjería' },
    { value: 'PA', label: 'Pasaporte' },
];

const CONTRACT_TYPES = [
    { value: 'indefinido', label: 'Término indefinido' },
    { value: 'fijo', label: 'Término fijo' },
    { value: 'obra', label: 'Obra o labor' },
    { value: 'aprendiz', label: 'Aprendizaje' },
];

export default function EmployeeForm({ mode, employee, stores, roles, canCreateAccess }: EmployeeFormProps) {
    const { data, setData, post, put, processing, errors } = useForm({
        store_id: employee?.store_id ?? (stores[0]?.value ?? 0),
        first_name: employee?.first_name ?? '',
        last_name: employee?.last_name ?? '',
        document_type: employee?.document_type ?? 'CC',
        document_number: employee?.document_number ?? '',
        birth_date: employee?.birth_date ?? '',
        phone: employee?.phone ?? '',
        email: employee?.email ?? '',
        address: employee?.address ?? '',
        emergency_contact_name: employee?.emergency_contact_name ?? '',
        emergency_contact_phone: employee?.emergency_contact_phone ?? '',
        position: employee?.position ?? '',
        contract_type: employee?.contract_type ?? 'indefinido',
        hired_at: employee?.hired_at ?? new Date().toISOString().slice(0, 10),
        work_schedule: (employee?.work_schedule ?? {}) as Schedule,
        create_access: false as boolean,
        access_email: '',
        access_role: roles[0]?.value ?? '',
        access_pin: '',
    });

    const toggleDay = (day: string, enabled: boolean) => {
        const next = { ...data.work_schedule };

        if (enabled) {
            next[day] = next[day] ?? { start: '07:00', end: '15:00' };
        } else {
            delete next[day];
        }

        setData('work_schedule', next);
    };

    const setHour = (day: string, key: 'start' | 'end', value: string) => {
        setData('work_schedule', {
            ...data.work_schedule,
            [day]: { ...(data.work_schedule[day] ?? { start: '07:00', end: '15:00' }), [key]: value },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('employees.store'));
        } else if (employee) {
            put(route('employees.update', employee.id));
        }
    };

    return (
        <AppLayout title={mode === 'create' ? 'Nuevo empleado' : 'Editar empleado'}>
            <PageHeader
                title={mode === 'create' ? 'Nuevo empleado' : `Editar a ${employee?.first_name} ${employee?.last_name}`}
                description="La ficha laboral es independiente de la cuenta de acceso."
                actions={
                    <ButtonLink href={route('employees.index')} variant="secondary" icon={<ArrowLeftIcon size={16} />}>
                        Volver
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Datos personales" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input label="Nombre" required value={data.first_name} onChange={(event) => setData('first_name', event.target.value)} error={errors.first_name} />
                            <Input label="Apellido" required value={data.last_name} onChange={(event) => setData('last_name', event.target.value)} error={errors.last_name} />
                            <Select
                                label="Tipo de documento"
                                required
                                options={DOCUMENT_TYPES}
                                value={data.document_type}
                                onChange={(event) => setData('document_type', event.target.value)}
                                error={errors.document_type}
                            />
                            <Input
                                label="Número de documento"
                                required
                                value={data.document_number}
                                onChange={(event) => setData('document_number', event.target.value)}
                                error={errors.document_number}
                            />
                            <Input
                                label="Fecha de nacimiento"
                                type="date"
                                value={data.birth_date}
                                onChange={(event) => setData('birth_date', event.target.value)}
                                error={errors.birth_date}
                            />
                            <Input label="Teléfono" value={data.phone} onChange={(event) => setData('phone', event.target.value)} error={errors.phone} />
                            <Input
                                label="Correo personal"
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                error={errors.email}
                                containerClassName="sm:col-span-2"
                            />
                            <Input
                                label="Dirección"
                                value={data.address}
                                onChange={(event) => setData('address', event.target.value)}
                                error={errors.address}
                                containerClassName="sm:col-span-2"
                            />
                            <Input
                                label="Contacto de emergencia"
                                value={data.emergency_contact_name}
                                onChange={(event) => setData('emergency_contact_name', event.target.value)}
                                error={errors.emergency_contact_name}
                            />
                            <Input
                                label="Teléfono de emergencia"
                                value={data.emergency_contact_phone}
                                onChange={(event) => setData('emergency_contact_phone', event.target.value)}
                                error={errors.emergency_contact_phone}
                            />
                        </div>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader title="Vinculación" />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input label="Cargo" required value={data.position} onChange={(event) => setData('position', event.target.value)} error={errors.position} />
                                <Select
                                    label="Sede"
                                    required
                                    options={stores}
                                    value={data.store_id}
                                    onChange={(event) => setData('store_id', Number(event.target.value))}
                                    error={errors.store_id}
                                />
                                <Select
                                    label="Tipo de contrato"
                                    required
                                    options={CONTRACT_TYPES}
                                    value={data.contract_type}
                                    onChange={(event) => setData('contract_type', event.target.value)}
                                    error={errors.contract_type}
                                />
                                <Input
                                    label="Fecha de ingreso"
                                    type="date"
                                    required
                                    value={data.hired_at}
                                    onChange={(event) => setData('hired_at', event.target.value)}
                                    error={errors.hired_at}
                                />
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="Turnos de la semana" description="Marca los días que trabaja y su horario." />
                            <div className="flex flex-col gap-2">
                                {DAYS.map((day) => {
                                    const enabled = Boolean(data.work_schedule[day]);

                                    return (
                                        <div key={day} className="flex flex-wrap items-center gap-3">
                                            <div className="w-32">
                                                <Checkbox
                                                    label={<span className="capitalize">{day}</span>}
                                                    checked={enabled}
                                                    onChange={(event) => toggleDay(day, event.target.checked)}
                                                />
                                            </div>
                                            {enabled && (
                                                <div className="flex items-center gap-2">
                                                    <input
                                                        type="time"
                                                        value={data.work_schedule[day]?.start ?? '07:00'}
                                                        onChange={(event) => setHour(day, 'start', event.target.value)}
                                                        className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 font-mono text-[0.8125rem] text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                    <span className="text-[var(--muted)]">a</span>
                                                    <input
                                                        type="time"
                                                        value={data.work_schedule[day]?.end ?? '15:00'}
                                                        onChange={(event) => setHour(day, 'end', event.target.value)}
                                                        className="h-9 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 font-mono text-[0.8125rem] text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </Card>
                    </div>
                </div>

                {mode === 'create' && canCreateAccess && (
                    <Card>
                        <CardHeader
                            title="Cuenta de acceso"
                            description="Si la creas ahora, se genera una contraseña temporal que se muestra una sola vez."
                        />

                        <Checkbox
                            label="Crear cuenta de acceso para este empleado"
                            checked={data.create_access}
                            onChange={(event) => setData('create_access', event.target.checked)}
                        />

                        {data.create_access && (
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                <Input
                                    label="Correo de acceso"
                                    type="email"
                                    required
                                    value={data.access_email}
                                    onChange={(event) => setData('access_email', event.target.value)}
                                    error={errors.access_email}
                                />
                                <Select
                                    label="Rol"
                                    required
                                    options={roles.map((role) => ({ value: role.value, label: role.label }))}
                                    value={data.access_role}
                                    onChange={(event) => setData('access_role', event.target.value)}
                                    error={errors.access_role}
                                />
                                <Input
                                    label="PIN de caja"
                                    inputMode="numeric"
                                    maxLength={4}
                                    value={data.access_pin}
                                    onChange={(event) => setData('access_pin', event.target.value.replace(/\D/g, ''))}
                                    error={errors.access_pin}
                                    hint="4 dígitos, solo si va a abrir caja."
                                />
                            </div>
                        )}
                    </Card>
                )}

                <div>
                    <Button type="submit" size="lg" loading={processing}>
                        {mode === 'create' ? 'Crear el empleado' : 'Guardar cambios'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
