import { BarcodeIcon, CashRegisterIcon, LockKeyIcon, MagnifyingGlassIcon, ShoppingCartIcon, type Icon } from '@phosphor-icons/react';
import { BrandMark } from '@/components/brand/BrandMark';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';
import { TilePhoto } from '@/pages/Pos/components/TilePhoto';
import type { Option, PosSample, PreviewTokens, RailKey, SettingsValues, ShortcutAction } from '../types';
import { layoutVars, themeVars } from './vars';

type PosDraft = Pick<
    SettingsValues,
    'pos.ticket_side' | 'pos.catalog_view' | 'pos.tile_size' | 'pos.tile_style' | 'pos.total_size' | 'pos.rail' | 'pos.shortcuts' | 'pos.ui_scale'
>;

interface PosPreviewProps {
    draft: PosDraft;
    theme: 'light' | 'dark';
    /** La marca guardada: la caja se ve con el color y la tipografia que ya tiene la empresa. */
    brand: Pick<SettingsValues, 'brand.accent' | 'brand.accent_dark' | 'appearance.font_body' | 'appearance.font_display' | 'appearance.font_numeric' | 'appearance.radius' | 'appearance.density'>;
    mark: string | null;
    tokens: PreviewTokens;
    sample: PosSample;
    railLabels: Option[];
    shortcutLabels: Option[];
}

const RAIL_ICONS: Record<RailKey, Icon> = {
    sale: ShoppingCartIcon,
    opening: CashRegisterIcon,
    closing: LockKeyIcon,
};

/** Columnas de la cuadricula segun el tamano de las teclas, igual que en `Pos/Sale`. */
const GRID_COLUMNS = { sm: 'grid-cols-4', md: 'grid-cols-3', lg: 'grid-cols-2' } as const;

/** Cuantas teclas caben en la vista previa segun su tamano. */
const GRID_COUNT = { sm: 8, md: 6, lg: 4 } as const;

/**
 * Con foto grande, una sola fila que llena el alto. La miniatura es ancha y bajita: una tecla
 * cuadrada mas su pie no cabe entera, y la fila de abajo asomaria cortada.
 */
const PHOTO_COUNT = { sm: 4, md: 3, lg: 2 } as const;

/**
 * La caja en miniatura con la disposicion del borrador: de que lado va el tiquete, como se ve
 * el catalogo, el tamano del total, el orden del riel y las teclas de cada atajo.
 */
export function PosPreview({ draft, theme, brand, mark, tokens, sample, railLabels, shortcutLabels }: PosPreviewProps) {
    const label = (options: Option[], value: string) => options.find((option) => option.value === value)?.label ?? value;
    const key = (action: ShortcutAction) => draft['pos.shortcuts'][action];
    const products = sample.products.length > 0 ? sample.products : [];
    const lines = products.slice(0, 2);
    const total = lines.reduce((sum, product) => sum + product.price, 0);
    const shortcuts: ShortcutAction[] = ['customer', 'discount', 'hold', 'resume'];
    const tileStyle = draft['pos.tile_style'];

    const ticket = (
        <div className="flex w-[40%] shrink-0 flex-col border-[var(--line)] bg-[var(--surface)]" style={{ [draft['pos.ticket_side'] === 'left' ? 'borderRight' : 'borderLeft']: '1px solid var(--line)' }}>
            <div className="border-b border-[var(--line)] px-2.5 py-1.5">
                <p className="text-[0.625rem] font-semibold">Tiquete en curso</p>
                <p className="text-[0.53125rem] text-[var(--muted)]">
                    {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'}
                </p>
            </div>
            <ul className="min-h-0 flex-1 divide-y divide-[var(--line)] overflow-hidden">
                {lines.map((product) => (
                    <li key={product.name} className="flex items-center justify-between gap-2 px-2.5 py-1.5">
                        <span className="truncate text-[0.59375rem] font-medium">{product.name}</span>
                        <span className="font-mono text-[0.59375rem] font-semibold tabular">{money(product.price)}</span>
                    </li>
                ))}
            </ul>
            <div className="border-t border-[var(--line)] px-2.5 py-2">
                <div className="flex items-center justify-between">
                    <span className="text-[0.625rem] font-semibold">TOTAL</span>
                    <span className={cn('font-mono font-semibold leading-none text-[var(--accent)] tabular', draft['pos.total_size'] === 'large' ? 'text-[1.375rem]' : 'text-[1.0625rem]')}>
                        {money(total)}
                    </span>
                </div>
                <span className="mt-2 flex h-8 items-center justify-center gap-1.5 rounded-[var(--r-control)] bg-[var(--accent)] text-[0.65625rem] font-semibold text-[var(--accent-ink)]">
                    Cobrar · {money(total)}
                    <span className="rounded-sm bg-black/15 px-1 font-mono text-[0.53125rem]">{key('pay')}</span>
                </span>
            </div>
        </div>
    );

    const catalog = (
        <div className="flex min-w-0 flex-1 flex-col p-2.5">
            <div className="mb-2 flex h-7 shrink-0 items-center gap-1.5 rounded-[var(--r-control)] border border-[var(--line)] bg-[var(--surface-2)] px-2 text-[0.59375rem] text-[var(--muted)]">
                <MagnifyingGlassIcon size={11} />
                Buscar artículo por nombre o SKU
            </div>

            {draft['pos.catalog_view'] === 'search' ? (
                <div className="grid flex-1 place-items-center text-center text-[var(--muted)]">
                    <span className="flex flex-col items-center gap-1.5">
                        <BarcodeIcon size={22} />
                        <span className="text-[0.59375rem]">Escanea o busca: el catálogo aparece al escribir.</span>
                    </span>
                </div>
            ) : (
                <>
                    <div className="mb-2 flex shrink-0 gap-1 overflow-hidden">
                        {['Todas', ...sample.categories.slice(0, 3)].map((category, index) => (
                            <span
                                key={category}
                                className={cn(
                                    'h-5 shrink-0 rounded-[var(--r-chip)] border px-2 text-[0.53125rem] leading-5 font-medium',
                                    index === 0 ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]' : 'border-[var(--line)]',
                                )}
                            >
                                {category}
                            </span>
                        ))}
                    </div>

                    {draft['pos.catalog_view'] === 'list' ? (
                        <ul className="flex flex-col gap-1 overflow-hidden">
                            {products.slice(0, 6).map((product) => (
                                <li key={product.name} className="flex items-center justify-between gap-2 rounded-[var(--r-control)] border border-[var(--line)] bg-[var(--surface)] px-2 py-1.5">
                                    <span className="truncate text-[0.59375rem] font-medium">{product.name}</span>
                                    <span className="font-mono text-[0.59375rem] font-semibold text-[var(--accent)] tabular">{money(product.price)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div
                            className={cn(
                                'grid min-h-0 flex-1 gap-1.5 overflow-hidden',
                                GRID_COLUMNS[draft['pos.tile_size']],
                                tileStyle === 'compact' ? 'auto-rows-min content-start' : 'grid-rows-[minmax(0,1fr)]',
                            )}
                        >
                            {products.slice(0, (tileStyle === 'compact' ? GRID_COUNT : PHOTO_COUNT)[draft['pos.tile_size']]).map((product) =>
                                tileStyle === 'compact' ? (
                                    <div
                                        key={product.name}
                                        className={cn(
                                            'flex flex-col justify-between rounded-[var(--r-card)] border border-[var(--line)] bg-[var(--surface)] p-1.5',
                                            draft['pos.tile_size'] === 'lg' ? 'min-h-16' : draft['pos.tile_size'] === 'md' ? 'min-h-12' : 'min-h-10',
                                        )}
                                    >
                                        <span className="flex items-start gap-1">
                                            {product.thumb_url && <img src={product.thumb_url} alt="" className="size-4 shrink-0 rounded-[var(--r-chip)] object-cover" />}
                                            <span className="line-clamp-2 text-[0.53125rem] leading-tight font-medium">{product.name}</span>
                                        </span>
                                        <span className="font-mono text-[0.59375rem] font-semibold text-[var(--accent)] tabular">{money(product.price)}</span>
                                    </div>
                                ) : (
                                    <div key={product.name} className="flex flex-col overflow-hidden rounded-[var(--r-card)] border border-[var(--line)] bg-[var(--surface)]">
                                        <TilePhoto
                                            src={product.thumb_url}
                                            name={product.name}
                                            mode={tileStyle}
                                            nameClassName="text-[0.6875rem]"
                                            className="aspect-auto min-h-0 flex-1"
                                        />
                                        <span className="flex flex-col gap-0.5 px-1.5 py-1">
                                            {product.thumb_url && <span className="truncate text-[0.53125rem] leading-tight font-medium">{product.name}</span>}
                                            <span className="font-mono text-[0.59375rem] font-semibold text-[var(--accent)] tabular">{money(product.price)}</span>
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>
                    )}
                </>
            )}
        </div>
    );

    return (
        <div
            data-theme={theme}
            inert
            aria-label="Vista previa de la caja"
            style={{ ...themeVars(brand['brand.accent'], brand['brand.accent_dark'], theme, tokens), ...layoutVars({ ...brand, 'appearance.density': 'cozy' }, tokens) }}
            className="overflow-hidden rounded-card border border-[var(--line)] bg-[var(--bg)] text-[var(--text)] select-none"
        >
            <div className="flex h-[19rem]" style={{ zoom: draft['pos.ui_scale'] / 100 }}>
                <div className="flex w-12 shrink-0 flex-col items-center gap-1.5 border-r border-[var(--line)] bg-[var(--surface)] py-2">
                    <BrandMark src={mark} className="mb-1 size-7 rounded-chip" iconClassName="size-4" ink="var(--accent-ink)" />
                    {draft['pos.rail'].map((item, index) => {
                        const Icon = RAIL_ICONS[item];

                        return (
                            <span
                                key={item}
                                className={cn(
                                    'flex size-10 flex-col items-center justify-center gap-0.5 rounded-control text-[0.46875rem] font-medium',
                                    index === draft['pos.rail'].indexOf('sale') ? 'bg-[var(--accent)] text-[var(--accent-ink)]' : 'text-[var(--muted)]',
                                )}
                            >
                                <Icon size={14} weight={item === 'sale' ? 'fill' : 'regular'} />
                                {label(railLabels, item)}
                            </span>
                        );
                    })}
                </div>

                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="flex h-10 shrink-0 items-center gap-1.5 overflow-hidden border-b border-[var(--line)] bg-[var(--surface)] px-2.5">
                        <span className="flex h-6 w-24 shrink-0 items-center gap-1 rounded-[var(--r-control)] border border-[var(--line)] bg-[var(--surface-2)] px-1.5 text-[0.53125rem] text-[var(--muted)]">
                            <BarcodeIcon size={10} />
                            Código
                            <span className="ml-auto font-mono text-[0.46875rem]">{key('scan')}</span>
                        </span>
                        <span className="ml-auto flex gap-1">
                            {shortcuts.map((action) => (
                                <span key={action} className="flex h-6 items-center gap-1 rounded-[var(--r-control)] border border-[var(--line)] bg-[var(--surface-2)] px-1.5 text-[0.53125rem] font-medium">
                                    {shortcutLabels.find((option) => option.value === action)?.short ?? label(shortcutLabels, action)}
                                    <span className="rounded-xs bg-[var(--surface-3)] px-0.5 font-mono text-[0.46875rem] text-[var(--muted)]">{key(action)}</span>
                                </span>
                            ))}
                        </span>
                    </div>

                    <div className="flex min-h-0 flex-1">
                        {draft['pos.ticket_side'] === 'left' ? (
                            <>
                                {ticket}
                                {catalog}
                            </>
                        ) : (
                            <>
                                {catalog}
                                {ticket}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
