import { router, useForm } from '@inertiajs/react';
import { CheckCircleIcon, CopyIcon, PlugsConnectedIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Input, Toggle } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { dateTime } from '@/lib/format';
import { usePermission } from '@/lib/permissions';

type Environment = 'sandbox' | 'production';

interface CredentialBlock {
    public_key: string | null;
    is_complete: boolean;
    expected_prefix: string;
    updated_at: string | null;
    secrets: { private_key: string | null; events_secret: string | null; integrity_secret: string | null };
}

interface GatewayProps {
    settings: { environment: Environment; is_enabled: boolean; is_usable: boolean; updated_at: string | null };
    credentials: Record<Environment, CredentialBlock>;
    environments: { value: Environment; label: string }[];
    webhookUrl: string;
}

const SECRET_LABELS = {
    private_key: 'Llave privada',
    events_secret: 'Secreto de eventos',
    integrity_secret: 'Secreto de integridad',
} as const;

/**
 * Plataforma > Pasarela de pagos (Wompi). Las llaves de cada entorno se guardan por separado; los
 * secretos nunca vuelven a la pantalla, solo como terminan.
 */
export default function GatewayIndex({ settings, credentials, environments, webhookUrl }: GatewayProps) {
    const { can } = usePermission();
    const canEdit = can('platform.gateway.edit');
    const form = useForm({ environment: settings.environment, is_enabled: settings.is_enabled });

    const save = () => form.put(route('platform.gateway.update'), { preserveScroll: true });

    const copy = () => {
        void navigator.clipboard?.writeText(webhookUrl).then(() => toast.success('URL copiada.'));
    };

    return (
        <AppLayout title="Pasarela de pagos">
            <PageHeader
                title="Pasarela de pagos"
                description="Wompi de la plataforma: cobra las membresías y las contrataciones en línea. Las ventas de cada empresa van por sus propias cuentas (Pasarelas para empresas)."
            />

            <div className="flex flex-col gap-5">
                <Card>
                    <CardHeader
                        title="Estado"
                        description={settings.updated_at ? `Último cambio ${dateTime(settings.updated_at)}` : 'Sin cambios todavía.'}
                        action={
                            settings.is_usable ? (
                                <Badge tone="accent">Cobrando en {settings.environment === 'production' ? 'producción' : 'pruebas'}</Badge>
                            ) : (
                                <Badge tone="warn">Cobros apagados</Badge>
                            )
                        }
                    />

                    <div className="flex flex-col gap-4">
                        <div>
                            <p className="mb-2 text-[0.8125rem] font-medium">Entorno en uso</p>
                            <div className="flex flex-wrap gap-2">
                                {environments.map((environment) => (
                                    <button
                                        key={environment.value}
                                        type="button"
                                        disabled={!canEdit}
                                        onClick={() => form.setData('environment', environment.value)}
                                        className={cn(
                                            'h-9 rounded-control border px-4 text-[0.875rem] font-semibold transition-colors',
                                            form.data.environment === environment.value
                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                                : 'border-[var(--line)] bg-[var(--surface)] hover:bg-[var(--surface-2)]',
                                        )}
                                    >
                                        {environment.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {canEdit && (
                            <Toggle
                                checked={form.data.is_enabled}
                                onChange={(value) => form.setData('is_enabled', value)}
                                label="Cobros habilitados"
                                hint="Apagado, no se muestra ningún botón de pago en la plataforma."
                            />
                        )}
                        {form.errors.is_enabled && <p className="text-[0.8125rem] font-medium text-[var(--danger)]">{form.errors.is_enabled}</p>}

                        <div>
                            <p className="mb-1 text-[0.8125rem] font-medium">URL de eventos (webhook)</p>
                            <p className="mb-2 text-[0.75rem] text-[var(--muted)]">Pégala en el panel de Wompi, en «Eventos», para cada entorno.</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="min-w-0 break-all rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 font-mono text-[0.8125rem]">{webhookUrl}</code>
                                <Button variant="secondary" size="sm" onClick={copy}>
                                    <CopyIcon size={14} /> Copiar
                                </Button>
                            </div>
                        </div>

                        {canEdit && (
                            <div>
                                <Button onClick={save} loading={form.processing} disabled={!form.isDirty}>
                                    Guardar estado
                                </Button>
                            </div>
                        )}
                    </div>
                </Card>

                <div className="grid gap-5 xl:grid-cols-2">
                    {environments.map((environment) => (
                        <CredentialsCard
                            key={environment.value}
                            environment={environment.value}
                            label={environment.label}
                            block={credentials[environment.value]}
                            active={settings.environment === environment.value}
                            canEdit={canEdit}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function CredentialsCard({ environment, label, block, active, canEdit }: { environment: Environment; label: string; block: CredentialBlock; active: boolean; canEdit: boolean }) {
    const form = useForm({ public_key: block.public_key ?? '', private_key: '', events_secret: '', integrity_secret: '' });
    const [testing, setTesting] = useState(false);

    const save = () =>
        form.put(route('platform.gateway.credentials', environment), {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, private_key: '', events_secret: '', integrity_secret: '' }),
        });

    const test = () => {
        setTesting(true);
        router.post(route('platform.gateway.test', environment), {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    };

    return (
        <Card>
            <CardHeader
                title={label}
                description={block.updated_at ? `Llaves actualizadas ${dateTime(block.updated_at)}` : 'Sin llaves cargadas.'}
                action={
                    <div className="flex items-center gap-2">
                        {active && <Badge tone="info">En uso</Badge>}
                        {block.is_complete ? (
                            <Badge tone="accent">
                                <CheckCircleIcon size={12} weight="fill" /> Completas
                            </Badge>
                        ) : (
                            <Badge tone="warn">
                                <WarningCircleIcon size={12} /> Incompletas
                            </Badge>
                        )}
                    </div>
                }
            />

            <div className="flex flex-col gap-3">
                <Input
                    label="Llave pública"
                    placeholder={`${block.expected_prefix}…`}
                    value={form.data.public_key}
                    onChange={(e) => form.setData('public_key', e.target.value)}
                    error={form.errors.public_key}
                    disabled={!canEdit}
                    autoComplete="off"
                />
                {(Object.keys(SECRET_LABELS) as (keyof typeof SECRET_LABELS)[]).map((field) => (
                    <Input
                        key={field}
                        type="password"
                        label={SECRET_LABELS[field]}
                        hint={block.secrets[field] ? `Guardada: ${block.secrets[field]}. Déjalo vacío para conservarla.` : 'Sin cargar.'}
                        value={form.data[field]}
                        onChange={(e) => form.setData(field, e.target.value)}
                        error={form.errors[field]}
                        disabled={!canEdit}
                        autoComplete="new-password"
                    />
                ))}

                <div className="flex flex-wrap gap-2 pt-1">
                    {canEdit && (
                        <Button onClick={save} loading={form.processing}>
                            Guardar llaves
                        </Button>
                    )}
                    {block.public_key && (
                        <Button variant="secondary" onClick={test} loading={testing}>
                            <PlugsConnectedIcon size={16} /> Probar conexión
                        </Button>
                    )}
                </div>
            </div>
        </Card>
    );
}
