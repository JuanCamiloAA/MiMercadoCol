import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { CheckRow, FieldBlock, OwnChip, PreviewFrame, SaveBar, Segmented, TabHeader } from '../SettingField';
import { useSettingsForm } from '../form';
import { ReceiptPreview } from '../preview/ReceiptPreview';
import type { Option, SettingKey, SettingsValues, TabProps } from '../types';

export const RECEIPT_KEYS = [
    'receipt.paper_width',
    'receipt.font_size',
    'receipt.show_logo',
    'receipt.header',
    'receipt.fields',
    'receipt.code',
    'pos.receipt_footer',
    'receipt.copies',
    'receipt.auto_print',
] as const satisfies readonly SettingKey[];

type ReceiptKey = (typeof RECEIPT_KEYS)[number];

const ignoreDirty = () => undefined;

/** `company` o el id de la sede que se esta editando. */
type Scope = 'company' | number;

/**
 * Ajustes > Tiquete. Vale para toda la empresa y cada sede puede tener lo suyo: con «Editando»
 * en una sede, lo que se toque queda como propio y lo demas se sigue heredando.
 */
export function ReceiptTab(props: TabProps) {
    const { stores, storeOverrides, readOnly, onDirtyChange } = props;
    const [scope, setScope] = useState<Scope>('company');
    const [pending, setPending] = useState<Scope | null>(null);
    const [dirty, setDirty] = useState(false);

    const reportDirty = useCallback(
        (value: boolean) => {
            setDirty(value);
            onDirtyChange(value);
        },
        [onDirtyChange],
    );

    const changeScope = (next: Scope) => {
        if (dirty) {
            setPending(next);
        } else {
            setScope(next);
        }
    };

    const scopeOptions: Option<string>[] = [
        { value: 'company', label: 'Toda la empresa' },
        ...stores.map((store) => {
            const own = Object.keys(storeOverrides[store.id] ?? {}).length;

            return {
                value: String(store.id),
                label: `Sede ${store.name}${own > 0 ? ` · ${own} ${own === 1 ? 'ajuste propio' : 'ajustes propios'}` : ''}`,
            };
        }),
    ];

    return (
        <>
            <ReceiptEditor
                key={String(scope)}
                {...props}
                scope={scope}
                scopeOptions={scopeOptions}
                onScopeChange={changeScope}
                onDirtyChange={reportDirty}
                readOnly={readOnly}
            />

            <Modal
                open={pending !== null}
                onClose={() => setPending(null)}
                title="Tienes cambios sin guardar"
                description="Si cambias lo que estás editando, se pierden."
                size="sm"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setPending(null)}>
                            Seguir editando
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                if (pending !== null) {
                                    setScope(pending);
                                }

                                setPending(null);
                            }}
                        >
                            Descartar cambios
                        </Button>
                    </>
                }
            >
                <p className="text-[0.8125rem] text-[var(--muted)]">Guarda primero si quieres conservarlos.</p>
            </Modal>
        </>
    );
}

interface ReceiptEditorProps extends TabProps {
    scope: Scope;
    scopeOptions: Option<string>[];
    onScopeChange: (scope: Scope) => void;
}

function ReceiptEditor({
    scope,
    scopeOptions,
    onScopeChange,
    settings,
    defaults,
    stores,
    storeOverrides,
    company,
    options,
    brandAssets,
    receiptSample,
    receiptLimits,
    readOnly,
    onDirtyChange,
}: ReceiptEditorProps) {
    const store = scope === 'company' ? null : (stores.find((item) => item.id === scope) ?? null);
    const overrides = useMemo(() => (scope === 'company' ? {} : (storeOverrides[scope] ?? {})), [scope, storeOverrides]);

    // Editando una sede, el formulario arranca con lo que esa sede imprime hoy.
    const initial = useMemo(() => ({ ...settings, ...overrides }) as SettingsValues, [settings, overrides]);
    const [own, setOwn] = useState<ReceiptKey[]>(() => RECEIPT_KEYS.filter((key) => key in overrides));
    const initialOwn = useMemo(() => RECEIPT_KEYS.filter((key) => key in overrides), [overrides]);
    const ownChanged = own.length !== initialOwn.length || own.some((key) => !initialOwn.includes(key));

    // El aviso de cambios lo da este componente: cuenta tambien lo que dejo de ser propio.
    const { form, data, errors, set, save, reset } = useSettingsForm(RECEIPT_KEYS, initial, ignoreDirty);

    useEffect(() => {
        onDirtyChange(form.isDirty || ownChanged);
    }, [form.isDirty, ownChanged, onDirtyChange]);

    const isStore = scope !== 'company';

    const change = <Key extends ReceiptKey>(key: Key, value: SettingsValues[Key]) => {
        set(key, value);

        if (isStore && !own.includes(key)) {
            setOwn((current) => [...current, key]);
        }
    };

    const inherit = (key: ReceiptKey) => {
        set(key, settings[key]);
        setOwn((current) => current.filter((item) => item !== key));
    };

    const chip = (key: ReceiptKey) => (isStore ? <OwnChip own={own.includes(key)} onInherit={() => inherit(key)} disabled={readOnly} /> : undefined);

    const paper = Number(data['receipt.paper_width']);
    const maxChars = receiptLimits.chars[String(paper)] ?? 48;
    const header = data['receipt.header'] ?? '';
    const headerLines = header.split(/\r\n|\r|\n/);
    const longLine = headerLines.findIndex((line) => line.length > maxChars);

    // Lo mismo que avisa el servidor al guardar, pero mientras se escribe.
    const headerWarning =
        headerLines.length > receiptLimits.max_lines
            ? `Van ${headerLines.length} líneas; caben máximo ${receiptLimits.max_lines}.`
            : longLine >= 0
              ? `La línea ${longLine + 1} tiene ${headerLines[longLine].length} caracteres; en papel de ${paper} mm caben ${maxChars}.`
              : undefined;

    const fields = data['receipt.fields'];
    const copies = Number(data['receipt.copies']);

    // El nombre grande del tiquete: el de la marca si se cambio, si no el de la empresa.
    const headline =
        settings['brand.display_name'] !== defaults['brand.display_name']
            ? settings['brand.display_name']
            : company?.name || settings['brand.display_name'];

    const submit = () => {
        if (isStore && store) {
            save(route('settings.receipt.store.update', store.id), { own });
        } else {
            save(route('settings.receipt.update'));
        }
    };

    const factory = () => {
        if (isStore) {
            // En una sede, «de fabrica» es volver a heredar todo de la empresa.
            RECEIPT_KEYS.forEach((key) => set(key, settings[key]));
            setOwn([]);
        } else {
            reset(defaults);
        }
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
            <div className="flex flex-col gap-5">
                <TabHeader scope="Empresa, con excepciones por sede" onFactory={readOnly ? undefined : factory}>
                    <FieldBlock
                        label="Editando"
                        htmlFor="receipt-scope"
                        hint={
                            isStore
                                ? 'Lo que toques queda como propio de esta sede. Lo demás lo sigue tomando de la empresa.'
                                : 'Aplica a todas las sedes que no tengan una excepción.'
                        }
                    >
                        <select
                            id="receipt-scope"
                            value={String(scope)}
                            onChange={(event) => onScopeChange(event.target.value === 'company' ? 'company' : Number(event.target.value))}
                            className="h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 text-[0.9375rem] font-medium text-[var(--text)] focus:border-[var(--accent)] focus:outline-none"
                        >
                            {scopeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </FieldBlock>
                </TabHeader>

                <FieldBlock label="Papel" aside={chip('receipt.paper_width')} error={errors['receipt.paper_width']}>
                    <Segmented
                        label="Papel"
                        value={paper}
                        options={options['receipt.paper_width'] as Option<number>[]}
                        onChange={(value) => change('receipt.paper_width', value)}
                        disabled={readOnly}
                    />
                </FieldBlock>

                <FieldBlock label="Letra" aside={chip('receipt.font_size')} error={errors['receipt.font_size']}>
                    <Segmented
                        label="Letra"
                        value={data['receipt.font_size']}
                        options={options['receipt.font_size'] as Option<SettingsValues['receipt.font_size']>[]}
                        onChange={(value) => change('receipt.font_size', value)}
                        disabled={readOnly}
                    />
                </FieldBlock>

                <div className="flex flex-col gap-1.5">
                    <div className="flex items-start justify-between gap-2">
                        <CheckRow
                            checked={data['receipt.show_logo']}
                            onChange={(value) => change('receipt.show_logo', value)}
                            disabled={readOnly}
                            label="Imprimir el logo"
                        />
                        {chip('receipt.show_logo')}
                    </div>
                    <p className="text-[0.75rem] leading-snug text-[var(--muted)]">
                        Se convierte a blanco y negro al subirlo: la impresora térmica no imprime color.
                        {!brandAssets.logo_receipt && ' Mientras no subas uno en Marca, sale la canasta.'}
                    </p>
                </div>

                <FieldBlock
                    label="Encabezado"
                    htmlFor="receipt-header"
                    aside={chip('receipt.header')}
                    error={errors['receipt.header'] ?? headerWarning}
                    hint={`Una línea por renglón: resolución DIAN, régimen, horario. Máximo ${receiptLimits.max_lines} líneas.`}
                >
                    <textarea
                        id="receipt-header"
                        rows={3}
                        value={header}
                        disabled={readOnly}
                        onChange={(event) => change('receipt.header', event.target.value)}
                        className="min-h-[5.25rem] w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2.5 text-sm text-[var(--text)] focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25 disabled:bg-[var(--surface-2)]"
                    />
                </FieldBlock>

                <FieldBlock
                    label="Qué sale impreso"
                    aside={chip('receipt.fields')}
                    error={errors['receipt.fields']}
                    hint="Razón social, NIT, base e IVA salen siempre: el tiquete puede ser documento equivalente."
                >
                    <div className="flex flex-col gap-2">
                        {(options['receipt.fields'] as Option<string>[]).map((option) => (
                            <CheckRow
                                key={option.value}
                                checked={fields.includes(option.value)}
                                disabled={readOnly}
                                label={option.label}
                                hint={option.hint}
                                onChange={(checked) =>
                                    change(
                                        'receipt.fields',
                                        checked ? [...fields, option.value] : fields.filter((field) => field !== option.value),
                                    )
                                }
                            />
                        ))}
                    </div>
                </FieldBlock>

                <FieldBlock
                    label="Código al pie"
                    aside={chip('receipt.code')}
                    error={errors['receipt.code']}
                    hint="Lleva el número de la venta, para encontrarla en una devolución."
                >
                    <Segmented
                        label="Código al pie"
                        value={data['receipt.code']}
                        options={options['receipt.code'] as Option<SettingsValues['receipt.code']>[]}
                        onChange={(value) => change('receipt.code', value)}
                        disabled={readOnly}
                    />
                </FieldBlock>

                <FieldBlock label="Pie del tiquete" htmlFor="receipt-footer" aside={chip('pos.receipt_footer')} error={errors['pos.receipt_footer']}>
                    <textarea
                        id="receipt-footer"
                        rows={2}
                        maxLength={200}
                        value={data['pos.receipt_footer']}
                        disabled={readOnly}
                        onChange={(event) => change('pos.receipt_footer', event.target.value)}
                        className="min-h-[4rem] w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2.5 text-sm text-[var(--text)] focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25 disabled:bg-[var(--surface-2)]"
                    />
                </FieldBlock>

                <FieldBlock label="Copias" aside={chip('receipt.copies')} error={errors['receipt.copies']}>
                    <Segmented
                        label="Copias"
                        value={copies}
                        options={options['receipt.copies'] as Option<number>[]}
                        onChange={(value) => change('receipt.copies', value)}
                        disabled={readOnly}
                    />
                </FieldBlock>

                <div className="flex items-start justify-between gap-2">
                    <CheckRow
                        checked={data['receipt.auto_print']}
                        onChange={(value) => change('receipt.auto_print', value)}
                        disabled={readOnly}
                        label="Imprimir al cobrar"
                        hint="El tiquete se manda a la impresora solo, sin un clic más. Apagado, sale con el botón Imprimir."
                    />
                    {chip('receipt.auto_print')}
                </div>

                {!readOnly && (
                    <SaveBar
                        dirty={form.isDirty || ownChanged}
                        processing={form.processing}
                        onSave={submit}
                        label={isStore && store ? `Guardar el tiquete de ${store.name}` : 'Guardar el tiquete'}
                    />
                )}
            </div>

            <div className="lg:sticky lg:top-20 lg:self-start">
                <PreviewFrame
                    title={`Vista previa · papel de ${paper} mm${receiptSample.is_example ? ' · venta de ejemplo' : ` · venta ${receiptSample.number}`}`}
                    note={`${copies === 1 ? '1 copia' : `${copies} copias`} · ${data['receipt.auto_print'] ? 'se imprime al cobrar' : 'se imprime con el botón Imprimir'}`}
                >
                    <ReceiptPreview
                        draft={{
                            paper,
                            fontSize: data['receipt.font_size'],
                            showLogo: data['receipt.show_logo'],
                            header,
                            fields,
                            code: data['receipt.code'],
                            footer: data['pos.receipt_footer'],
                        }}
                        sample={receiptSample}
                        company={company}
                        store={store ?? stores[0] ?? null}
                        headline={headline}
                        logo={brandAssets.logo_receipt}
                    />
                </PreviewFrame>
            </div>
        </div>
    );
}
