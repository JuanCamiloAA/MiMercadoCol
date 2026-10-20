import { router, useForm } from '@inertiajs/react';
import { ArrowsClockwiseIcon, CheckCircleIcon, CopyIcon, PencilSimpleIcon, PlugIcon, PlusIcon, PowerIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input, Select } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/Table';
import AppLayout from '@/layouts/AppLayout';
import { dateTime } from '@/lib/format';
import { usePermission } from '@/lib/permissions';
import PaymentTabs from '@/pages/PaymentMethods/components/PaymentTabs';

interface GatewayField {
    key: string;
    label: string;
    secret: boolean;
    required: boolean;
    hint?: string;
}

interface Provider {
    provider: string;
    label: string;
    fields: GatewayField[];
    capabilities: string[];
    webhook_instructions: string;
    enabled: boolean;
}

interface Account {
    id: number;
    provider: string;
    provider_label: string;
    environment: string;
    environment_label: string;
    label: string | null;
    public_fields: Record<string, string>;
    /** Solo enmascaradas: el valor nunca viaja al navegador. */
    secret_fields: Record<string, string>;
    webhook_url: string;
    webhook_instructions: string | null;
    capabilities: string[];
    is_enabled: boolean;
    provider_enabled: boolean;
    verified_at: string | null;
    last_error: string | null;
    methods_count: number;
}

interface Props {
    accounts: Account[];
    providers: Provider[];
    environments: { value: string; label: string }[];
    capabilityLabels: Record<string, string>;
}

/**
 * Las cuentas de la empresa en las pasarelas. La plata de sus ventas va a esas cuentas, no a la
 * plataforma: la pasarela de la membresía es otra cosa y la maneja la plataforma.
 */
export default function PaymentGateways({ accounts, providers, environments, capabilityLabels }: Props) {
    const { can } = usePermission();
    const canEdit = can('payment_methods.gateways.edit');
    const [connecting, setConnecting] = useState(false);
    const [editing, setEditing] = useState<Account | null>(null);
    const [rotating, setRotating] = useState<Account | null>(null);

    return (
        <AppLayout title="Pasarelas de pago">
            <PageHeader
                title="Medios de pago"
                description="Conecta las cuentas de tu empresa en las pasarelas. Lo que se cobra por ellas llega a tu cuenta, no a la plataforma."
                actions={
                    canEdit && providers.length > 0 ? (
                        <Button icon={<PlusIcon size={16} />} onClick={() => setConnecting(true)}>
                            Conectar una pasarela
                        </Button>
                    ) : undefined
                }
            />

            <PaymentTabs active="gateways" />

            {accounts.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={<PlugIcon size={24} />}
                        title="No hay pasarelas conectadas"
                        description={
                            providers.length === 0
                                ? 'La plataforma todavía no tiene pasarelas disponibles para empresas.'
                                : 'Conecta tu cuenta de Wompi, Mercado Pago, PayU, ePayco o Bold para cobrar con QR, link, datáfono integrado o en la tienda en línea.'
                        }
                    />
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {accounts.map((account) => (
                        <Card key={account.id}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h2 className="text-[1rem] font-semibold">{account.label || account.provider_label}</h2>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                        <Badge>{account.provider_label}</Badge>
                                        <Badge tone={account.environment === 'production' ? 'accent' : 'info'}>{account.environment_label}</Badge>
                                        {account.is_enabled ? <Badge tone="accent">Encendida</Badge> : <Badge tone="warn">Apagada</Badge>}
                                        {!account.provider_enabled && <Badge tone="danger">Apagada por la plataforma</Badge>}
                                    </div>
                                </div>
                                {account.methods_count > 0 && (
                                    <span className="text-[0.75rem] text-[var(--muted)]">
                                        {account.methods_count} medio{account.methods_count === 1 ? '' : 's'} de pago
                                    </span>
                                )}
                            </div>

                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {account.capabilities.map((capability) => (
                                    <Badge key={capability} tone="neutral">
                                        {capabilityLabels[capability] ?? capability}
                                    </Badge>
                                ))}
                            </div>

                            <dl className="mt-4 grid gap-1.5 text-[0.8125rem]">
                                {Object.entries(account.public_fields).map(([key, value]) => (
                                    <div key={key} className="flex justify-between gap-3">
                                        <dt className="text-[var(--muted)]">{fieldLabel(providers, account.provider, key)}</dt>
                                        <dd className="truncate font-mono text-[0.75rem]">{value}</dd>
                                    </div>
                                ))}
                                {Object.entries(account.secret_fields).map(([key, value]) => (
                                    <div key={key} className="flex justify-between gap-3">
                                        <dt className="text-[var(--muted)]">{fieldLabel(providers, account.provider, key)}</dt>
                                        <dd className="font-mono text-[0.75rem]">{value}</dd>
                                    </div>
                                ))}
                            </dl>

                            <div className="mt-4 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-3">
                                <p className="text-[0.75rem] font-semibold uppercase tracking-wide text-[var(--muted)]">URL del webhook</p>
                                <div className="mt-1 flex items-center gap-2">
                                    <code className="min-w-0 flex-1 truncate font-mono text-[0.75rem]">{account.webhook_url}</code>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        icon={<CopyIcon size={14} />}
                                        onClick={() => void navigator.clipboard?.writeText(account.webhook_url).then(() => toast.success('URL copiada.'))}
                                    >
                                        Copiar
                                    </Button>
                                </div>
                                {account.webhook_instructions && <p className="mt-1.5 text-[0.75rem] text-[var(--muted)]">{account.webhook_instructions}</p>}
                            </div>

                            <p className="mt-3 flex items-center gap-1.5 text-[0.75rem]">
                                {account.verified_at ? (
                                    <>
                                        <CheckCircleIcon size={14} weight="fill" className="text-[var(--accent)]" />
                                        <span className="text-[var(--muted)]">Conexión probada el {dateTime(account.verified_at)}</span>
                                    </>
                                ) : account.last_error ? (
                                    <>
                                        <WarningCircleIcon size={14} weight="fill" className="text-[var(--danger)]" />
                                        <span className="text-[var(--danger)]">{account.last_error}</span>
                                    </>
                                ) : (
                                    <span className="text-[var(--muted)]">Todavía no se ha probado la conexión.</span>
                                )}
                            </p>

                            <div className="mt-4 flex flex-wrap gap-2">
                                {can('payment_methods.gateways.test') && (
                                    <Button size="sm" variant="secondary" onClick={() => router.post(route('payment_methods.gateways.test', account.id), {}, { preserveScroll: true })}>
                                        Probar conexión
                                    </Button>
                                )}
                                {canEdit && (
                                    <>
                                        <Button size="sm" variant="secondary" icon={<PencilSimpleIcon size={14} />} onClick={() => setEditing(account)}>
                                            Cambiar llaves
                                        </Button>
                                        <Button size="sm" variant="secondary" icon={<ArrowsClockwiseIcon size={14} />} onClick={() => setRotating(account)}>
                                            Nueva URL de webhook
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant={account.is_enabled ? 'outline' : 'primary'}
                                            icon={<PowerIcon size={14} />}
                                            onClick={() => router.patch(route('payment_methods.gateways.toggle', account.id), {}, { preserveScroll: true })}
                                        >
                                            {account.is_enabled ? 'Apagar' : 'Encender'}
                                        </Button>
                                    </>
                                )}
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            {canEdit && (
                <>
                    <ConnectModal open={connecting} onClose={() => setConnecting(false)} providers={providers} environments={environments} />
                    <EditKeysModal account={editing} onClose={() => setEditing(null)} providers={providers} />
                    <ConfirmDialog
                        open={rotating !== null}
                        onClose={() => setRotating(null)}
                        onConfirm={() => rotating && router.post(route('payment_methods.gateways.rotate', rotating.id), {}, { preserveScroll: true, onSuccess: () => setRotating(null) })}
                        title="Cambiar la URL del webhook"
                        message="La URL actual deja de funcionar en el acto. Tendrás que pegar la nueva en el panel de la pasarela, o los pagos no se confirmarán solos."
                        confirmLabel="Cambiarla"
                        tone="danger"
                    />
                </>
            )}
        </AppLayout>
    );
}

function fieldLabel(providers: Provider[], provider: string, key: string): string {
    return providers.find((item) => item.provider === provider)?.fields.find((field) => field.key === key)?.label ?? key;
}

function ConnectModal({ open, onClose, providers, environments }: { open: boolean; onClose: () => void; providers: Provider[]; environments: { value: string; label: string }[] }) {
    const form = useForm({ provider: providers[0]?.provider ?? '', environment: environments[0]?.value ?? 'sandbox', label: '', fields: {} as Record<string, string> });
    const provider = providers.find((item) => item.provider === form.data.provider);
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('payment_methods.gateways.store'), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Conectar una pasarela"
            description="Las llaves están en el panel de la pasarela. Las secretas se guardan cifradas y no se vuelven a mostrar."
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="gateway-connect-form" loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <form id="gateway-connect-form" onSubmit={submit} className="flex flex-col gap-3" autoComplete="off">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Select
                        label="Pasarela"
                        required
                        options={providers.map((item) => ({ value: item.provider, label: item.label }))}
                        value={form.data.provider}
                        onChange={(event) => form.setData((current) => ({ ...current, provider: event.target.value, fields: {} }))}
                        error={errors.provider}
                    />
                    <Select label="Entorno" required options={environments} value={form.data.environment} onChange={(event) => form.setData('environment', event.target.value)} error={errors.environment} />
                </div>
                <Input label="Nombre (opcional)" maxLength={80} value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} error={errors.label} placeholder="Cuenta principal" />

                {provider?.fields.map((field) => (
                    <Input
                        key={field.key}
                        label={field.label}
                        required={field.required}
                        type={field.secret ? 'password' : 'text'}
                        autoComplete="off"
                        value={form.data.fields[field.key] ?? ''}
                        onChange={(event) => form.setData('fields', { ...form.data.fields, [field.key]: event.target.value })}
                        error={errors[`fields.${field.key}`]}
                        hint={field.hint}
                    />
                ))}
            </form>
        </Modal>
    );
}

function EditKeysModal({ account, onClose, providers }: { account: Account | null; onClose: () => void; providers: Provider[] }) {
    const form = useForm({ label: '', fields: {} as Record<string, string> });
    const provider = providers.find((item) => item.provider === account?.provider);
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (account) {
            // Lo publico se puede ver y editar; lo secreto empieza vacio y vacio es «no lo cambio».
            form.setData({ label: account.label ?? '', fields: { ...account.public_fields } });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [account?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (account) {
            form.put(route('payment_methods.gateways.update', account.id), { preserveScroll: true, onSuccess: () => onClose() });
        }
    };

    return (
        <Modal
            open={account !== null}
            onClose={onClose}
            title="Cambiar llaves"
            description="Una llave secreta vacía se conserva como está. Al guardar hay que volver a probar la conexión."
            size="lg"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" form="gateway-edit-form" loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <form id="gateway-edit-form" onSubmit={submit} className="flex flex-col gap-3" autoComplete="off">
                <Input label="Nombre (opcional)" maxLength={80} value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} error={errors.label} />
                {(provider?.fields ?? []).map((field) => (
                    <Input
                        key={field.key}
                        label={field.label}
                        type={field.secret ? 'password' : 'text'}
                        autoComplete="off"
                        value={form.data.fields[field.key] ?? ''}
                        placeholder={field.secret ? (account?.secret_fields[field.key] ? `Guardada (${account.secret_fields[field.key]}). Escribe solo para reemplazarla.` : 'Sin guardar') : undefined}
                        onChange={(event) => form.setData('fields', { ...form.data.fields, [field.key]: event.target.value })}
                        error={errors[`fields.${field.key}`]}
                        hint={field.hint}
                    />
                ))}
            </form>
        </Modal>
    );
}
