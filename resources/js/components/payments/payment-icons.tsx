import {
    ArrowsSplitIcon,
    BankIcon,
    CreditCardIcon,
    DeviceMobileIcon,
    GlobeIcon,
    HandCoinsIcon,
    LockKeyIcon,
    MoneyIcon,
    QrCodeIcon,
    ReceiptIcon,
    StorefrontIcon,
    WalletIcon,
    type Icon,
} from '@phosphor-icons/react';

/**
 * Los iconos que se pueden elegir para un medio de pago. Es la misma lista que
 * `PaymentMethod::ICONS` en el servidor: si se agrega uno alla, se agrega aqui.
 */
export const PAYMENT_ICONS: Record<string, Icon> = {
    money: MoneyIcon,
    'credit-card': CreditCardIcon,
    bank: BankIcon,
    'device-mobile': DeviceMobileIcon,
    'qr-code': QrCodeIcon,
    wallet: WalletIcon,
    'lock-key': LockKeyIcon,
    'arrows-split': ArrowsSplitIcon,
    globe: GlobeIcon,
    receipt: ReceiptIcon,
    'hand-coins': HandCoinsIcon,
    storefront: StorefrontIcon,
};

export function paymentIcon(name: string | null | undefined): Icon {
    return (name && PAYMENT_ICONS[name]) || HandCoinsIcon;
}

/** Un medio de pago como lo resuelve el servidor para un canal (`PaymentMethodAvailability::present`). */
export interface PresentedPaymentMethod {
    id: number;
    name: string;
    kind: PaymentKind;
    icon: string;
    description: string | null;
    instructions: string | null;
    highlight: boolean;
    requires_reference: boolean;
    reference_label: string | null;
    combinable: boolean;
    min_amount: number | null;
    max_amount: number | null;
    requires_customer: boolean;
    requires_credit_line: boolean;
    requires_store_credit: boolean;
    group: 'online' | 'on_delivery' | 'credit' | null;
    provider: string | null;
    /** Datáfonos de la caja (solo `gateway_terminal`). */
    terminals?: { id: number; name: string }[];
}

export type PaymentKind = 'cash' | 'card' | 'transfer' | 'credit' | 'store_credit' | 'exchange' | 'gateway_checkout' | 'gateway_link' | 'gateway_terminal' | 'other';

export const GATEWAY_KINDS: PaymentKind[] = ['gateway_checkout', 'gateway_link', 'gateway_terminal'];
