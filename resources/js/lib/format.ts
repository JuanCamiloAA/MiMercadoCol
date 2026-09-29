import { format, formatDistanceToNowStrict, parseISO } from 'date-fns';
import { es } from 'date-fns/locale';

const currency = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const decimal = new Intl.NumberFormat('es-CO', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
});

/** Pesos enteros: "$ 1.240.500". */
export function money(value: number | string | null | undefined): string {
    const amount = Number(value ?? 0);

    return currency.format(Number.isFinite(amount) ? amount : 0).replace(/ /g, ' ');
}

export function number(value: number | string | null | undefined): string {
    const amount = Number(value ?? 0);

    return decimal.format(Number.isFinite(amount) ? amount : 0);
}

export function percent(value: number | null | undefined, digits = 1): string {
    const amount = Number(value ?? 0);

    return `${amount.toFixed(digits).replace('.', ',')} %`;
}

function toDate(value: string | Date | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const date = typeof value === 'string' ? parseISO(value) : value;

    return Number.isNaN(date.getTime()) ? null : date;
}

export function date(value: string | Date | null | undefined, pattern = 'd MMM yyyy'): string {
    const parsed = toDate(value);

    return parsed ? format(parsed, pattern, { locale: es }) : '—';
}

export function dateTime(value: string | Date | null | undefined): string {
    return date(value, "d MMM yyyy, h:mm a");
}

export function time(value: string | Date | null | undefined): string {
    return date(value, 'h:mm a');
}

export function relative(value: string | Date | null | undefined): string {
    const parsed = toDate(value);

    return parsed ? `hace ${formatDistanceToNowStrict(parsed, { locale: es })}` : '—';
}

export function initials(value: string): string {
    return value
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

export const UNIT_LABELS: Record<string, string> = {
    unit: 'Unidad',
    kg: 'Kilogramo',
    g: 'Gramo',
    l: 'Litro',
    pack: 'Paquete',
};

export const UNIT_SHORT: Record<string, string> = {
    unit: 'und',
    kg: 'kg',
    g: 'g',
    l: 'L',
    pack: 'paq',
};
