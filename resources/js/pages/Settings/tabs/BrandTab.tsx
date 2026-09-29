import { Link, router } from '@inertiajs/react';
import { CheckIcon, ImageIcon, TrashIcon, UploadSimpleIcon, WarningCircleIcon } from '@phosphor-icons/react';
import { useRef, useState } from 'react';
import { Input } from '@/components/ui/Field';
import { cn } from '@/lib/cn';
import { contrast, ink, isHex, LIGHT, normalize, palette, ratio } from '@/lib/color';
import { useFeature, usePermission } from '@/lib/permissions';
import { CheckRow, FieldBlock, PreviewFrame, SaveBar, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import { PanelPreview, type PanelDraft } from '../preview/PanelPreview';
import type { BrandAssets, SettingKey, TabProps } from '../types';

export const BRAND_KEYS = ['brand.display_name', 'brand.tagline', 'brand.accent', 'brand.accent_dark'] as const satisfies readonly SettingKey[];

type Slot = 'mark' | 'logo_light' | 'logo_dark';

/**
 * Ajustes > Marca: como se llama la empresa en pantalla, su color y sus imagenes.
 *
 * El color se ajusta solo donde haria falta: si no se lee como texto, la interfaz lo acerca a
 * negro (tema claro) o a blanco (tema oscuro). El logo y el isotipo conservan el original.
 */
export function BrandTab({ settings, defaults, readOnly, onDirtyChange, brandAssets, swatches, company, previewTokens, posSample }: TabProps) {
    const { can } = usePermission();
    const hasFeature = useFeature();
    const { form, data, errors, set, save, reset } = useSettingsForm(BRAND_KEYS, settings, onDirtyChange);
    const [manualDark, setManualDark] = useState(settings['brand.accent_dark'] !== null);

    const accent = isHex(data['brand.accent']) ? normalize(data['brand.accent']) : normalize(settings['brand.accent']);
    const accentDark = manualDark && isHex(data['brand.accent_dark']) ? normalize(data['brand.accent_dark']) : null;
    const resolved = palette(accent, accentDark);
    const onWhite = contrast(accent, LIGHT.surface);
    // Con el acento de fabrica y sin oscuro a mano, el tema oscuro usa el verde de app.css.
    const factory = accent === normalize(previewTokens.factory_accent) && accentDark === null;
    const darkUsed = factory ? previewTokens.factory.dark.accent : resolved.dark.accent;

    const draft: PanelDraft = {
        displayName: data['brand.display_name'],
        subtitle: company?.name ?? '',
        accent,
        accentDark,
        mark: brandAssets.mark,
        logoLight: brandAssets.logo_light,
        logoDark: brandAssets.logo_dark,
        layout: settings,
        sidebarStyle: settings['panel.sidebar_style'],
        sidebarMode: settings['panel.sidebar_mode'],
        showToggle: settings['panel.allow_theme_toggle'],
        scale: 100,
        navigation: Array.isArray(settings['panel.navigation']) ? null : settings['panel.navigation'],
    };

    const toggleManualDark = (checked: boolean) => {
        setManualDark(checked);
        set('brand.accent_dark', checked ? darkUsed : null);
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
            <div className="flex flex-col gap-5">
                <TabHeader
                    scope="Empresa"
                    onFactory={
                        readOnly
                            ? undefined
                            : () => {
                                  reset(defaults);
                                  setManualDark(false);
                              }
                    }
                />

                <Input
                    label="Nombre visible"
                    maxLength={40}
                    required
                    disabled={readOnly}
                    value={data['brand.display_name']}
                    onChange={(event) => set('brand.display_name', event.target.value)}
                    error={errors['brand.display_name']}
                    hint="Sale en el menú, en el ingreso, en la pestaña del navegador y en el tiquete."
                />

                <Input
                    label="Subtítulo"
                    maxLength={60}
                    disabled={readOnly}
                    value={data['brand.tagline'] ?? ''}
                    onChange={(event) => set('brand.tagline', event.target.value)}
                    error={errors['brand.tagline']}
                    hint="Opcional. Va con el nombre al pie de la pantalla de ingreso."
                />

                <FieldBlock label="Color de la marca" error={errors['brand.accent']}>
                    <div className="flex flex-wrap gap-1.5">
                        {swatches.map((swatch) => {
                            const active = normalize(swatch) === accent;

                            return (
                                <button
                                    key={swatch}
                                    type="button"
                                    disabled={readOnly}
                                    onClick={() => set('brand.accent', swatch)}
                                    aria-label={`Usar ${swatch}`}
                                    title={swatch}
                                    className={cn(
                                        'grid size-9 place-items-center rounded-control border-2 transition-transform disabled:cursor-not-allowed',
                                        active ? 'border-[var(--text)]' : 'border-transparent hover:scale-105',
                                    )}
                                    style={{ backgroundColor: swatch }}
                                >
                                    {active && <CheckIcon size={16} weight="bold" color={ink(swatch)} />}
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <input
                            type="color"
                            aria-label="Elegir otro color"
                            disabled={readOnly}
                            value={accent.toLowerCase()}
                            onChange={(event) => set('brand.accent', event.target.value.toUpperCase())}
                            className="h-10 w-12 shrink-0 cursor-pointer rounded-control border border-[var(--line)] bg-[var(--surface)] p-1 disabled:cursor-not-allowed"
                        />
                        <input
                            type="text"
                            aria-label="Color en hexadecimal"
                            disabled={readOnly}
                            maxLength={7}
                            value={data['brand.accent']}
                            onChange={(event) => set('brand.accent', event.target.value.trim())}
                            className="h-10 w-32 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 font-mono text-sm uppercase text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                        />
                    </div>

                    <ContrastNote
                        label="Tema claro"
                        original={accent}
                        used={resolved.light.accent}
                        detail={
                            resolved.light.accent === accent
                                ? `Se lee bien como texto (${ratio(resolved.light.contrast)} en el peor fondo).`
                                : `Tu color da ${ratio(onWhite)} sobre blanco; la interfaz usa ${resolved.light.accent} para que el texto se lea.`
                        }
                    />
                    <ContrastNote
                        label="Tema oscuro"
                        original={accent}
                        used={darkUsed}
                        detail={
                            accentDark
                                ? `Elegido a mano (${ratio(resolved.dark.contrast)} en el peor fondo oscuro).`
                                : factory
                                  ? `El de fábrica usa ${darkUsed}, más claro, para que se lea sobre fondo oscuro.`
                                  : darkUsed === accent
                                    ? 'Se lee bien sobre los fondos oscuros.'
                                    : `Sobre fondo oscuro se aclara a ${darkUsed}.`
                        }
                    />
                    <p className="text-[0.75rem] text-[var(--muted)]">El logo y el isotipo conservan tu color original: solo la interfaz usa el ajustado.</p>
                </FieldBlock>

                <FieldBlock
                    label="Color para el tema oscuro"
                    error={errors['brand.accent_dark']}
                    hint="Opción avanzada. Sin ella, el color del tema oscuro se calcula del de la marca."
                >
                    <CheckRow checked={manualDark} onChange={toggleManualDark} disabled={readOnly} label="Elegirlo a mano" />
                    {manualDark && (
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                aria-label="Color del tema oscuro"
                                disabled={readOnly}
                                value={(isHex(data['brand.accent_dark']) ? data['brand.accent_dark'] : resolved.dark.accent).toLowerCase()}
                                onChange={(event) => set('brand.accent_dark', event.target.value.toUpperCase())}
                                className="h-10 w-12 shrink-0 cursor-pointer rounded-control border border-[var(--line)] bg-[var(--surface)] p-1"
                            />
                            <input
                                type="text"
                                aria-label="Color del tema oscuro en hexadecimal"
                                disabled={readOnly}
                                maxLength={7}
                                value={data['brand.accent_dark'] ?? ''}
                                onChange={(event) => set('brand.accent_dark', event.target.value.trim())}
                                className="h-10 w-32 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 font-mono text-sm uppercase text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                            />
                            {isHex(data['brand.accent_dark']) && resolved.dark.contrast < 4.5 && (
                                <span className="flex items-center gap-1 text-[0.75rem] font-medium text-[var(--danger)]">
                                    <WarningCircleIcon size={14} />
                                    {ratio(resolved.dark.contrast)}: no se guarda
                                </span>
                            )}
                        </div>
                    )}
                </FieldBlock>

                <FieldBlock label="Imágenes" hint="PNG, JPG o WebP hasta 2 MB. Se guardan en PNG para conservar la transparencia; cada una se sube en el acto.">
                    <div className="flex flex-col gap-2.5">
                        <ImageSlot
                            slot="mark"
                            title="Isotipo"
                            description="Cuadrado, mínimo 256 × 256 px. Favicon, caja y menú angosto."
                            url={brandAssets.mark}
                            readOnly={readOnly}
                            square
                        />
                        <ImageSlot
                            slot="logo_light"
                            title="Logo para fondo claro"
                            description="Horizontal. Menú claro, ingreso y tiquete (en blanco y negro)."
                            url={brandAssets.logo_light}
                            readOnly={readOnly}
                        />
                        <ImageSlot
                            slot="logo_dark"
                            title="Logo para fondo oscuro"
                            description="Menú oscuro o del color de la marca. Si falta, se usa el claro."
                            url={brandAssets.logo_dark}
                            readOnly={readOnly}
                            dark
                        />
                    </div>
                </FieldBlock>

                <CompanyData company={company} assets={brandAssets} />

                <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
                    <p className="text-[0.84375rem] font-semibold">Tienda en línea</p>
                    <p className="mt-1 text-[0.78125rem] leading-relaxed text-[var(--muted)]">
                        La tienda tiene su propio aspecto, por sede: su color, su tipografía y sus bloques se editan en el editor de la tienda.
                        El domicilio, las franjas y los medios de pago van en su pestaña Funciones.
                    </p>
                    {can('storefront.editor.view') && hasFeature('online_store') && (
                        <Link
                            href={route('storefront.editor.index')}
                            className="mt-3 inline-flex h-9 items-center rounded-control border border-[var(--line)] px-3 text-[0.8125rem] font-medium hover:bg-[var(--surface-2)]"
                        >
                            Abrir el editor de la tienda
                        </Link>
                    )}
                </div>

                {!readOnly && (
                    <SaveBar dirty={form.isDirty} processing={form.processing} onSave={() => save(route('settings.brand.update'))} label="Guardar la marca" />
                )}
            </div>

            <div className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
                <PreviewFrame title="Vista previa · panel claro">
                    <PanelPreview draft={draft} theme="light" tokens={previewTokens} sample={posSample} entries={6} />
                </PreviewFrame>
                <PreviewFrame title="Vista previa · panel oscuro">
                    <PanelPreview draft={draft} theme="dark" tokens={previewTokens} sample={posSample} entries={6} />
                </PreviewFrame>
                <BrowserTab name={data['brand.display_name']} accent={accent} mark={brandAssets.mark} />
            </div>
        </div>
    );
}

function ContrastNote({ label, original, used, detail }: { label: string; original: string; used: string; detail: string }) {
    return (
        <div className="flex items-start gap-2.5 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2">
            <span className="mt-0.5 flex shrink-0 overflow-hidden rounded-chip border border-[var(--line)]">
                <span className="size-5" style={{ backgroundColor: original }} title={`Tu color: ${original}`} />
                {used !== original && <span className="size-5" style={{ backgroundColor: used }} title={`Se usa: ${used}`} />}
            </span>
            <p className="text-[0.78125rem] leading-snug">
                <span className="font-semibold">{label}.</span> <span className="text-[var(--muted)]">{detail}</span>
            </p>
        </div>
    );
}

interface ImageSlotProps {
    slot: Slot;
    title: string;
    description: string;
    url: string | null;
    readOnly: boolean;
    square?: boolean;
    dark?: boolean;
}

/**
 * Un lugar para una imagen de marca. Sube y quita en el acto, sin esperar a Guardar: la imagen
 * no es parte del borrador de la pestana.
 */
function ImageSlot({ slot, title, description, url, readOnly, square, dark }: ImageSlotProps) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const upload = (file: File) => {
        setBusy(true);
        setError(null);

        router.post(
            route('settings.brand.images.store', slot),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => setError(errors.file ?? 'No se pudo subir la imagen.'),
                onFinish: () => {
                    setBusy(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    const remove = () => {
        setBusy(true);
        router.delete(route('settings.brand.images.destroy', slot), {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="flex items-center gap-3 rounded-card border border-[var(--line)] bg-[var(--surface)] p-3">
            <span
                className={cn(
                    'grid shrink-0 place-items-center overflow-hidden rounded-control border border-[var(--line)]',
                    square ? 'size-14' : 'h-14 w-24',
                    dark ? 'bg-[#151a1d]' : 'bg-white',
                )}
                style={
                    url
                        ? undefined
                        : { backgroundImage: 'repeating-conic-gradient(rgba(127,127,127,.18) 0% 25%, transparent 0% 50%)', backgroundSize: '12px 12px' }
                }
            >
                {url ? <img src={url} alt={title} className="max-h-full max-w-full object-contain p-1" /> : <ImageIcon size={20} className="text-[var(--muted)]" />}
            </span>

            <div className="min-w-0 flex-1">
                <p className="text-[0.8125rem] font-semibold">{title}</p>
                <p className="text-[0.75rem] leading-snug text-[var(--muted)]">{description}</p>
                {error && <p className="mt-1 text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>}
            </div>

            {!readOnly && (
                <div className="flex shrink-0 flex-col gap-1.5">
                    <input
                        ref={input}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(event) => {
                            const file = event.target.files?.[0];

                            if (file) {
                                upload(file);
                            }
                        }}
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => input.current?.click()}
                        className="inline-flex h-8 items-center gap-1.5 rounded-chip border border-[var(--line)] px-2.5 text-[0.78125rem] font-medium hover:bg-[var(--surface-2)] disabled:opacity-60"
                    >
                        <UploadSimpleIcon size={14} />
                        {busy ? 'Subiendo…' : url ? 'Cambiar' : 'Subir'}
                    </button>
                    {url && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={remove}
                            className="inline-flex h-8 items-center gap-1.5 rounded-chip px-2.5 text-[0.78125rem] font-medium text-[var(--danger)] hover:bg-[var(--danger-soft)] disabled:opacity-60"
                        >
                            <TrashIcon size={14} />
                            Quitar
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

function CompanyData({ company, assets }: { company: TabProps['company']; assets: BrandAssets }) {
    const rows: [string, string | null | undefined, boolean?][] = [
        ['Razón social', company?.legal_name],
        ['NIT', company?.tax_id, true],
        ['Correo', company?.email],
        ['Teléfono', company?.phone, true],
        ['Dirección', company?.address],
    ];

    return (
        <div className="rounded-card border border-[var(--line)] bg-[var(--surface)] p-4">
            <p className="text-[0.84375rem] font-semibold">Datos de la empresa</p>
            <p className="mt-0.5 text-[0.75rem] text-[var(--muted)]">Salen impresos en tiquetes e informes.</p>
            <dl className="mt-3 flex flex-col gap-2 text-[0.8125rem]">
                {rows.map(([label, value, mono]) => (
                    <div key={label} className="flex items-start justify-between gap-3">
                        <dt className="shrink-0 text-[var(--muted)]">{label}</dt>
                        <dd className={cn('text-right', mono && 'font-mono text-[0.78125rem] tabular')}>{value || '—'}</dd>
                    </div>
                ))}
                <div className="flex items-start justify-between gap-3">
                    <dt className="shrink-0 text-[var(--muted)]">Logo del tiquete</dt>
                    <dd className="text-right">
                        {assets.logo_receipt ? (
                            <img src={assets.logo_receipt} alt="Logo del tiquete" className="ml-auto h-8 max-w-[8.75rem] bg-white object-contain" />
                        ) : (
                            <span className="text-[0.78125rem] text-[var(--muted)]">Se genera al subir un logo o el isotipo</span>
                        )}
                    </dd>
                </div>
            </dl>
        </div>
    );
}

/** Como se ve la pestana del navegador: el favicon y el titulo con el nombre. */
function BrowserTab({ name, accent, mark }: { name: string; accent: string; mark: string | null }) {
    return (
        <PreviewFrame title="Vista previa · pestaña del navegador">
            <div className="flex items-center gap-2 rounded-t-control border border-b-0 border-[var(--line)] bg-[var(--surface-2)] px-3 py-2" inert>
                {mark ? (
                    <img src={mark} alt="" className="size-4 rounded-xs object-contain" />
                ) : (
                    <span className="grid size-4 place-items-center rounded-sm" style={{ backgroundColor: accent }}>
                        <svg viewBox="0 0 32 32" className="size-3" fill="none" stroke={ink(accent) === '#FFFFFF' ? 'white' : '#10161C'} strokeWidth={3}>
                            <path d="M8 11h16l-2 12H10L8 11Z" />
                            <path d="M12 11a4 4 0 0 1 8 0" />
                        </svg>
                    </span>
                )}
                <span className="truncate text-[0.78125rem]">Inicio · {name || 'Sin nombre'}</span>
            </div>
        </PreviewFrame>
    );
}
