import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { Toggle } from '@/components/ui/Field';
import { Badge, PageHeader } from '@/components/ui/Misc';
import AppLayout from '@/layouts/AppLayout';
import { usePermission } from '@/lib/permissions';

interface ProviderRow {
    provider: string;
    label: string;
    capabilities: string[];
    enabled: boolean;
    accounts: number;
    accounts_enabled: number;
}

interface Props {
    providers: ProviderRow[];
    capabilityLabels: Record<string, string>;
}

/**
 * Qué pasarelas pueden conectar las empresas a su propia cuenta para cobrar sus ventas. Apagar
 * una la saca de todas las cajas y tiendas sin borrar las cuentas: al encenderla vuelven solas.
 * No es la pasarela de la plataforma, que cobra las membresías.
 */
export default function CompanyGateways({ providers, capabilityLabels }: Props) {
    const { can } = usePermission();
    const canEdit = can('platform.gateway.edit');
    const form = useForm({ providers: providers.filter((row) => row.enabled).map((row) => row.provider) });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('platform.company_gateways.update'), { preserveScroll: true });
    };

    return (
        <AppLayout title="Pasarelas para empresas">
            <PageHeader
                title="Pasarelas para empresas"
                description="Las que cada empresa puede conectar a su propia cuenta para cobrar sus ventas. La plata va a la empresa, no a la plataforma."
            />

            <form onSubmit={submit}>
                <Card>
                    <CardHeader title="Proveedores" description="Apagar uno lo quita de todas las cajas y tiendas al instante; las cuentas de las empresas quedan guardadas." />
                    <ul className="divide-y divide-[var(--line)]">
                        {providers.map((row) => (
                            <li key={row.provider} className="flex flex-wrap items-center gap-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-[0.9375rem] font-semibold">{row.label}</p>
                                    <div className="mt-1 flex flex-wrap gap-1.5">
                                        {row.capabilities.map((capability) => (
                                            <Badge key={capability}>{capabilityLabels[capability] ?? capability}</Badge>
                                        ))}
                                    </div>
                                    <p className="mt-1 text-[0.75rem] text-[var(--muted)]">
                                        {row.accounts === 0 ? 'Ninguna empresa la ha conectado.' : `${row.accounts} cuenta${row.accounts === 1 ? '' : 's'} conectada${row.accounts === 1 ? '' : 's'}, ${row.accounts_enabled} encendida${row.accounts_enabled === 1 ? '' : 's'}.`}
                                    </p>
                                </div>
                                <Toggle
                                    checked={form.data.providers.includes(row.provider)}
                                    disabled={!canEdit}
                                    onChange={(checked) =>
                                        form.setData('providers', checked ? [...form.data.providers, row.provider] : form.data.providers.filter((provider) => provider !== row.provider))
                                    }
                                    label={form.data.providers.includes(row.provider) ? 'Disponible' : 'Apagada'}
                                />
                            </li>
                        ))}
                    </ul>
                    {canEdit && (
                        <div className="mt-4 flex justify-end">
                            <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                                Guardar
                            </Button>
                        </div>
                    )}
                </Card>
            </form>
        </AppLayout>
    );
}
