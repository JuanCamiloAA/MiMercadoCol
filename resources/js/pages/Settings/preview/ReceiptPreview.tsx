import type { CSSProperties } from 'react';
import type { CompanyRow, ReceiptSample, StoreRow } from '../types';

export interface ReceiptDraft {
    paper: number;
    fontSize: 'small' | 'normal' | 'large';
    showLogo: boolean;
    header: string;
    fields: string[];
    code: 'none' | 'barcode' | 'qr';
    footer: string;
}

interface ReceiptPreviewProps {
    draft: ReceiptDraft;
    sample: ReceiptSample;
    company: CompanyRow | null;
    store: StoreRow | null;
    /** El nombre grande: el de la marca si se cambio en Ajustes, si no el de la empresa. */
    headline: string;
    /** El logo del tiquete ya convertido a blanco y negro, o null para la canasta en negro. */
    logo: string | null;
}

/** Los mismos px que `ReceiptService::FONT_SIZES`. */
const FONT_PX = { small: 10, normal: 11, large: 12 } as const;

/** Miles con punto, como `number_format` en el tiquete impreso (Intl no separa los de 4 cifras). */
function thousands(value: number): string {
    const digits = String(Math.abs(Math.round(value))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return value < 0 ? `-${digits}` : digits;
}

function headerLines(header: string): string[] {
    const lines = header.split(/\r\n|\r|\n/).map((line) => line.trimEnd());

    while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
        lines.pop();
    }

    return lines;
}

/**
 * El tiquete tal como sale de la termica: mismo ancho en milimetros, misma letra, mismo orden
 * que `pos/receipt-print.blade.php`. Si cambias uno, cambia el otro, o la vista previa promete
 * un tiquete que la impresora no saca.
 */
export function ReceiptPreview({ draft, sample, company, store, headline, logo }: ReceiptPreviewProps) {
    const font = FONT_PX[draft.fontSize] ?? 11;
    const has = (field: string) => draft.fields.includes(field);
    const code = draft.code === 'barcode' ? sample.barcode : draft.code === 'qr' ? sample.qr : null;

    const paper: CSSProperties = {
        width: `${draft.paper}mm`,
        padding: '4mm 3mm',
        fontFamily: "'Courier New', monospace",
        fontSize: `${font}px`,
        lineHeight: 1.35,
        color: '#000',
        background: '#fff',
    };

    const big: CSSProperties = { fontSize: `${font + 4}px`, fontWeight: 700 };
    const small: CSSProperties = { fontSize: `${font - 1}px` };
    const rule = <hr style={{ border: 0, borderTop: '1px dashed #000', margin: '6px 0' }} />;
    const row = (label: string, value: string, bold = false) => (
        <tr>
            <td style={{ padding: '1px 0', fontWeight: bold ? 700 : undefined }}>{label}</td>
            <td style={{ padding: '1px 0', textAlign: 'right', fontWeight: bold ? 700 : undefined }}>{value}</td>
        </tr>
    );

    return (
        <div className="flex justify-center overflow-x-auto">
            <div style={paper} className="shrink-0 shadow-sm" aria-label="Vista previa del tiquete">
                <div style={{ textAlign: 'center' }}>
                    {draft.showLogo && (
                        <img
                            src={logo ?? sample.default_logo}
                            alt=""
                            style={{ display: 'block', maxWidth: '100%', maxHeight: '30mm', margin: '0 auto 4px', filter: 'grayscale(1) contrast(1.8)' }}
                        />
                    )}
                    <div style={big}>{headline}</div>
                    <div>{company?.legal_name}</div>
                    <div style={small}>NIT {company?.tax_id}</div>
                    <div style={small}>
                        {store?.name}
                        {has('store_address') && store?.address ? ` · ${store.address}` : ''}
                    </div>
                    {has('store_phone') && store?.phone && <div style={small}>{store.phone}</div>}
                    {headerLines(draft.header).map((line, index) => (
                        <div key={index} style={{ ...small, whiteSpace: 'pre-wrap' }}>
                            {line || ' '}
                        </div>
                    ))}
                </div>

                {rule}

                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <tbody>
                        {row('Tiquete', sample.number, true)}
                        {row('Fecha', sample.date)}
                        {has('cashier') && sample.cashier && row('Cajero', sample.cashier)}
                        {has('seller') && sample.seller && row('Vendedor', sample.seller)}
                        {has('customer') && sample.customer && (
                            <>
                                {row('Cliente', sample.customer.name)}
                                {row('Documento', sample.customer.document)}
                            </>
                        )}
                    </tbody>
                </table>

                {rule}

                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <tbody>
                        {sample.items.map((item, index) => (
                            <FragmentRows key={index}>
                                <tr>
                                    <td colSpan={2} style={{ padding: '1px 0', wordBreak: 'break-word' }}>
                                        {item.description}
                                    </td>
                                </tr>
                                <tr>
                                    <td style={{ ...small, padding: '1px 0' }}>
                                        {item.quantity} x {thousands(item.unit_price)}
                                        {item.discount > 0 ? ` (−${thousands(item.discount)})` : ''}
                                    </td>
                                    <td style={{ padding: '1px 0', textAlign: 'right' }}>{thousands(item.line_total)}</td>
                                </tr>
                                {(item.components ?? []).map((component, position) => (
                                    <tr key={position}>
                                        <td style={{ ...small, padding: '1px 0 1px 0.5rem' }}>
                                            · {component.quantity} x {component.description}
                                            {component.adjusted ? ' (ajustado)' : ''}
                                        </td>
                                        <td style={{ ...small, padding: '1px 0', textAlign: 'right' }}>
                                            {component.line_total !== 0 ? thousands(component.line_total) : 'incluido'}
                                        </td>
                                    </tr>
                                ))}
                            </FragmentRows>
                        ))}
                    </tbody>
                </table>

                {rule}

                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <tbody>
                        {row('Base', thousands(sample.subtotal))}
                        {row('IVA', thousands(sample.tax_total))}
                        {sample.discount_total > 0 && row('Descuento', `− ${thousands(sample.discount_total)}`)}
                        {sample.rounding_total !== 0 &&
                            row('Ajuste al redondeo', `${sample.rounding_total > 0 ? '+ ' : '− '}${thousands(Math.abs(sample.rounding_total))}`)}
                        <tr>
                            <td style={{ ...big, padding: '1px 0' }}>TOTAL</td>
                            <td style={{ ...big, padding: '1px 0', textAlign: 'right' }}>$ {thousands(sample.total)}</td>
                        </tr>
                    </tbody>
                </table>

                {rule}

                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <tbody>
                        {sample.payments.map((payment, index) => (
                            <FragmentRows key={index}>{row(payment.label, thousands(payment.amount))}</FragmentRows>
                        ))}
                        {sample.change !== null && sample.cash_received !== null && (
                            <>
                                {row('Recibido en efectivo', thousands(sample.cash_received))}
                                {row('Cambio', thousands(sample.change), true)}
                            </>
                        )}
                    </tbody>
                </table>

                {rule}

                <div style={{ ...small, textAlign: 'center' }}>
                    {draft.footer}
                    {code && (
                        <img
                            src={code}
                            alt={sample.number}
                            style={
                                draft.code === 'qr'
                                    ? { display: 'block', margin: '6px auto 0', width: '26mm', height: '26mm' }
                                    : { display: 'block', margin: '6px auto 0', width: '100%', height: '12mm' }
                            }
                        />
                    )}
                    <div style={{ marginTop: 6, fontWeight: 700 }}>{sample.number}</div>
                </div>
            </div>
        </div>
    );
}

/** Varias filas de tabla sin envoltura: un `<>` con `key`. */
function FragmentRows({ children }: { children: React.ReactNode }) {
    return <>{children}</>;
}
