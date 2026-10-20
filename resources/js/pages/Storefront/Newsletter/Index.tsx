import { DownloadSimpleIcon } from '@phosphor-icons/react';
import { ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { PageHeader, SearchInput } from '@/components/ui/Misc';
import { EmptyState, Pagination, Table, TBody, TD, TH, THead, TR } from '@/components/ui/Table';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/AppLayout';
import { dateTime } from '@/lib/format';
import type { Paginated } from '@/types';

interface Subscription {
    id: number;
    email: string;
    store: string | null;
    consent_at: string | null;
    created_at: string | null;
}

interface NewsletterIndexProps {
    subscriptions: Paginated<Subscription>;
    filters: { q: string; sede: string };
    stores: { value: string; label: string }[];
    total: number;
}

/**
 * Tienda en línea › Suscriptores: quién dejó su correo en el pie de la tienda para recibir
 * novedades, con su autorización de datos. El formulario lo enciende cada sede en su pie.
 */
export default function NewsletterIndex({ subscriptions, filters: initialFilters, stores, total }: NewsletterIndexProps) {
    const { filters, update } = useFilters('storefront.newsletter.index', initialFilters);
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '')).toString();

    return (
        <AppLayout title="Suscriptores">
            <PageHeader
                title="Suscriptores"
                description={`Correos que los clientes dejaron en el pie de la tienda para recibir novedades: ${total} en total. El formulario se enciende en Editor › Pie de página.`}
                actions={
                    <ButtonLink href={`${route('storefront.newsletter.export')}${query ? `?${query}` : ''}`} variant="secondary" icon={<DownloadSimpleIcon size={16} />}>
                        Descargar CSV
                    </ButtonLink>
                }
            />

            <div className="mb-4 flex flex-wrap items-end gap-2">
                <SearchInput value={filters.q} onChange={(event) => update('q', event.target.value)} placeholder="Buscar un correo" containerClassName="w-full sm:w-[18rem]" />
                {stores.length > 1 && (
                    <div className="w-full sm:w-[14rem]">
                        <Select value={filters.sede} onChange={(event) => update('sede', event.target.value)} options={[{ value: '', label: 'Todas las sedes' }, ...stores]} aria-label="Sede" />
                    </div>
                )}
            </div>

            <Card padded={false}>
                {subscriptions.data.length === 0 ? (
                    <EmptyState title="Todavía no hay suscriptores" description="Cuando una sede encienda el formulario de novedades en su pie, los correos llegan aquí." />
                ) : (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Correo</TH>
                                    <TH>Sede</TH>
                                    <TH>Autorizó sus datos</TH>
                                    <TH>Suscrito</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {subscriptions.data.map((item) => (
                                    <TR key={item.id}>
                                        <TD>
                                            <a href={`mailto:${item.email}`} className="font-medium text-[var(--accent)] hover:underline">
                                                {item.email}
                                            </a>
                                        </TD>
                                        <TD>{item.store ?? '—'}</TD>
                                        <TD>{item.consent_at ? dateTime(item.consent_at) : '—'}</TD>
                                        <TD>{item.created_at ? dateTime(item.created_at) : '—'}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination meta={subscriptions} />
                    </>
                )}
            </Card>
        </AppLayout>
    );
}
