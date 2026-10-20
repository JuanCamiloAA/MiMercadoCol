import { router, usePage } from '@inertiajs/react';
import { type ComponentType, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/ui/Misc';
import { Modal } from '@/components/ui/Modal';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/cn';
import { AppearanceTab } from './tabs/AppearanceTab';
import { BrandTab } from './tabs/BrandTab';
import { CatalogTab } from './tabs/CatalogTab';
import { CompanyTab } from './tabs/CompanyTab';
import { MembershipTab } from './tabs/MembershipTab';
import { CreditTab } from './tabs/CreditTab';
import { NavigationTab } from './tabs/NavigationTab';
import { PosRulesTab } from './tabs/PosRulesTab';
import { PosTab } from './tabs/PosTab';
import { ReceiptTab } from './tabs/ReceiptTab';
import type { SettingsPageProps, TabKey, TabProps } from './types';

interface TabDefinition {
    key: TabKey;
    /** Lo que va en `?pestana=`: asi un enlace o una recarga vuelven a la misma pestana. */
    slug: string;
    label: string;
    component: ComponentType<TabProps>;
}

const TABS: TabDefinition[] = [
    { key: 'company', slug: 'empresa', label: 'Empresa', component: CompanyTab },
    { key: 'membership', slug: 'membresia', label: 'Membresía', component: MembershipTab },
    { key: 'brand', slug: 'marca', label: 'Marca', component: BrandTab },
    { key: 'appearance', slug: 'apariencia', label: 'Apariencia', component: AppearanceTab },
    { key: 'navigation', slug: 'menu', label: 'Menú', component: NavigationTab },
    { key: 'pos', slug: 'caja', label: 'Caja', component: PosTab },
    { key: 'pos_rules', slug: 'reglas', label: 'Reglas de caja', component: PosRulesTab },
    { key: 'receipt', slug: 'tiquete', label: 'Tiquete', component: ReceiptTab },
    { key: 'credit', slug: 'credito', label: 'Crédito', component: CreditTab },
    { key: 'catalog', slug: 'catalogo', label: 'Catálogo', component: CatalogTab },
];

/**
 * Ajustes: la marca, el panel, la caja y el tiquete de la empresa, sin tocar codigo.
 *
 * Cada pestana se guarda por su lado, con su permiso. Las que la persona no puede guardar no se
 * pintan; si no puede guardar ninguna, las ve todas en solo lectura, como antes.
 */
export default function SettingsIndex(props: SettingsPageProps) {
    const { url } = usePage();
    const editableTabs = TABS.filter((tab) => props.editable[tab.key]);
    const readOnly = editableTabs.length === 0;
    const tabs = readOnly ? TABS : editableTabs;

    const [active, setActive] = useState<TabKey>(() => {
        const slug = new URLSearchParams(url.split('?')[1] ?? '').get('pestana');

        return tabs.find((tab) => tab.slug === slug)?.key ?? tabs[0].key;
    });
    const [dirty, setDirty] = useState(false);
    const [pending, setPending] = useState<TabKey | null>(null);

    const current = tabs.find((tab) => tab.key === active) ?? tabs[0];
    const Current = current.component;

    const open = (key: TabKey) => {
        const tab = tabs.find((item) => item.key === key);

        if (!tab) {
            return;
        }

        setActive(key);
        setDirty(false);
        setPending(null);

        // La pestana viaja en la direccion; la visita parcial no recarga nada mas.
        router.get(route('settings.index'), { pestana: tab.slug }, { preserveState: true, preserveScroll: true, replace: true, only: ['editable'] });
    };

    const request = (key: TabKey) => {
        if (key === active) {
            return;
        }

        if (dirty) {
            setPending(key);
        } else {
            open(key);
        }
    };

    return (
        <AppLayout title="Ajustes">
            <PageHeader title="Ajustes" description="La marca, el panel, la caja y el tiquete de la empresa. Cada pestaña se guarda por su lado." />

            <nav aria-label="Pestañas de ajustes" className="mb-5 flex flex-wrap gap-2">
                {tabs.map((tab) => {
                    const selected = tab.key === current.key;

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            aria-current={selected ? 'page' : undefined}
                            onClick={() => request(tab.key)}
                            className={cn(
                                'h-9 rounded-control border px-4 text-[0.875rem] font-semibold transition-colors',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                                selected
                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                    : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
                            )}
                        >
                            {tab.label}
                        </button>
                    );
                })}
            </nav>

            {readOnly && (
                <p className="mb-4 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-[0.8125rem] text-[var(--muted)]">
                    Puedes ver los ajustes, pero no cambiarlos.
                </p>
            )}

            <Current key={current.key} {...props} readOnly={readOnly || !props.editable[current.key]} onDirtyChange={setDirty} />

            <Modal
                open={pending !== null}
                onClose={() => setPending(null)}
                title="Tienes cambios sin guardar"
                description={`Si sales de ${current.label}, se pierden.`}
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setPending(null)}>
                            Seguir editando
                        </Button>
                        <Button variant="danger" onClick={() => pending && open(pending)}>
                            Descartar cambios
                        </Button>
                    </>
                }
            >
                <p className="text-[0.8125rem] text-[var(--muted)]">Guarda primero si quieres conservarlos: cada pestaña tiene su botón abajo.</p>
            </Modal>
        </AppLayout>
    );
}
