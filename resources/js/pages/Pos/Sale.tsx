import { router, usePage } from '@inertiajs/react';
import {
    ArrowUUpLeftIcon,
    BarcodeIcon,
    LockKeyIcon,
    MinusIcon,
    PackageIcon,
    PlusIcon,
    PrinterIcon,
    TagIcon,
    TrashIcon,
    UserIcon,
    WrenchIcon,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import PosLayout from '@/layouts/PosLayout';
import { cn } from '@/lib/cn';
import { money, number, time, UNIT_SHORT } from '@/lib/format';
import { playErrorSound, playScanSound } from '@/lib/sounds';
import type { PresentedPaymentMethod } from '@/components/payments/payment-icons';
import GatewayWaitDialog, { type GatewayPaymentState } from '@/pages/Pos/components/GatewayWaitDialog';
import PaymentDialog, { type GatewayRequest, type PaymentPayload } from '@/pages/Pos/components/PaymentDialog';
import ReturnDialog, { type ExchangeDraft } from '@/pages/Pos/components/ReturnDialog';
import ServiceLineCard from '@/pages/Pos/components/ServiceLineCard';
import { TilePhoto, type TileStyle } from '@/pages/Pos/components/TilePhoto';
import {
    componentsFromMaster,
    componentTotal,
    isComponentAdjusted,
    type PosService,
    type PosServiceComponent,
    q3,
    type RemovedComponent,
    type ServiceAction,
    type ServiceComponentLine,
    swapComponent,
} from '@/pages/Pos/service-lines';
import type { LastSaleFlash as LastSale, PageProps } from '@/types';

/** La promocion que toca un articulo, del tipo que sea. */
interface PosPromotion {
    id: number;
    name: string;
    badge: string;
    hint: string;
    type: string;
    /** El 3x2 y los combos no bajan el precio unitario: se notan al completar cantidades. */
    depends_on_quantity: boolean;
    /** Unidades del mismo articulo que pide un lleva N paga M. */
    needs: number | null;
    price: number | null;
    compare_at: number | null;
}

interface PosProduct {
    promotion?: PosPromotion | null;
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string;
    sold_by_weight: boolean;
    price: number;
    tax_rate: number;
    category_id: number | null;
    allow_negative_stock: boolean;
    /** Lo que hay en las otras bodegas de la sede. No se puede cobrar: hay que ir por ello. */
    other_stock: number;
    thumb: string | null;
    /** La foto, ya resuelta por el servidor (Firebase o local). */
    thumb_url?: string | null;
    stock: number;
    /** «Talla 8 · Azul», «900 ml»: el distintivo de un subartículo. */
    variant_label?: string | null;
    family_id?: number | null;
}

/** Un artículo con subartículos cuyo código se escaneó: se elige cuál se cobra. */
interface PosFamily {
    id: number;
    name: string;
    variants: PosProduct[];
}

interface PosCustomer {
    id: number;
    name: string;
    document_number: string;
    phone: string | null;
    credit_enabled: boolean;
    credit_blocked: boolean;
    blocked_reason: string | null;
    credit_limit: number;
    credit_term_days: number;
    balance: number;
    available_credit: number;
    /** Saldo a favor por devoluciones: se cobra como un medio de pago. */
    store_credit?: number;
}

/** Un artículo suelto o un servicio. Los tiquetes suspendidos de antes no traen `kind`: son artículos. */
type LineKind = 'product' | 'service';

interface CartLine {
    kind: LineKind;
    /** La clave de la línea: un artículo suelto y el mismo dentro de un servicio son líneas distintas. */
    key: string;
    product_id?: number;
    service_id?: number;
    pricing_mode?: 'bundled' | 'itemized';
    open_price?: boolean;
    components?: ServiceComponentLine[];
    removed_components?: RemovedComponent[];
    /** El maestro por una unidad, para recalcular al cambiar la cantidad y para restaurar. */
    master?: PosServiceComponent[];
    /** Lo que dijo el servidor al cotizar un servicio que no se puede cobrar así. */
    quote_error?: string | null;
    promotion?: PosPromotion | null;
    /** El cajero decidio cobrar el precio de lista en esta linea. */
    skip_promotion: boolean;
    /** Lo que rebaja la promocion, calculado por el servidor. */
    promo_discount: number;
    name: string;
    unit: string;
    sold_by_weight: boolean;
    quantity: number;
    unit_price: number;
    tax_rate: number;
    discount: number;
    stock: number;
    allow_negative_stock: boolean;
    variant_label?: string | null;
}

/** Lo que se puede autorizar con el PIN de un supervisor. */
type ApprovalAction = 'discount' | 'credit' | 'return_window' | ServiceAction;

/** Lo que devuelve la cotización del servidor por cada línea, en el mismo orden. */
type QuotedLine =
    | { kind: 'product'; product_id: number; unit_price: number; promo_discount: number }
    | { kind: 'service'; service_id: number; unit_price: number | null; components: { product_id: number; unit_price: number }[]; error: string | null };

interface HeldTicket {
    id: number;
    label: string;
    total: number;
    /** El cliente con sus datos de hoy; nulo si no tenia o si ya no esta activo. */
    customer: PosCustomer | null;
    /** El nombre con que se suspendio, aunque el cliente ya no este activo. */
    customer_name: string | null;
    payload: { items: CartLine[]; customer_id: number | null };
    created_at: string;
}

interface RecentSale {
    id: number;
    number: string;
    total: number;
    status: string;
    status_label: string;
    customer: string | null;
    sold_at: string;
}

/** Las acciones de la caja que tienen tecla. Cada tecla la elige la empresa en Ajustes > Caja. */
type ShortcutAction = 'scan' | 'customer' | 'discount' | 'hold' | 'resume' | 'recent' | 'pay' | 'pay_alt';

/** Como se reparte la pantalla de la caja (Ajustes > Caja). */
interface PosLayoutSettings {
    ticket_side: 'right' | 'left';
    catalog_view: 'grid' | 'list' | 'search';
    tile_size: 'sm' | 'md' | 'lg';
    tile_style: TileStyle;
    total_size: 'normal' | 'large';
    rail: ('sale' | 'opening' | 'closing')[];
    shortcuts: Record<ShortcutAction, string>;
    sounds: boolean;
}

interface TileSize {
    grid: string;
    tile: string;
    name: string;
    price: string;
    /** El relleno del pie cuando la foto va grande: la foto llega hasta el borde. */
    pad: string;
    /** El nombre en el cuadro de la foto cuando el articulo no tiene. */
    placeholder: string;
}

/** Columnas y alto de cada tecla del catalogo segun su tamano. `md` es el de siempre. */
const TILE_GRID: Record<PosLayoutSettings['tile_size'], TileSize> = {
    sm: { grid: 'grid-cols-3 sm:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6', tile: 'min-h-[5.5rem] p-2.5', name: 'text-[0.75rem]', price: 'text-[0.875rem]', pad: 'p-2', placeholder: 'text-[0.9375rem]' },
    md: { grid: 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5', tile: 'min-h-[6.75rem] p-3', name: 'text-[0.8125rem]', price: 'text-[1rem]', pad: 'p-2.5', placeholder: 'text-[1.125rem]' },
    lg: { grid: 'grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4', tile: 'min-h-[8.5rem] p-3.5', name: 'text-[0.9375rem]', price: 'text-[1.1875rem]', pad: 'p-3', placeholder: 'text-[1.375rem]' },
};

/** A quien se le puede atribuir una venta. */
export interface Seller {
    value: number;
    label: string;
    position: string | null;
}

interface PosSaleProps {
    session: {
        id: number;
        register: string | null;
        register_code: string | null;
        /** Quien abrio el turno, que no siempre es quien esta vendiendo. */
        cashier: string | null;
        is_own: boolean;
        opened_at: string;
    };
    /** Las cajas abiertas de la sede: se puede vender en la que abrio otro. */
    openSessions: { id: number; register: string | null; cashier: string | null }[];
    sellers: Seller[];
    /** Por defecto, la ficha de quien abrio la caja. */
    defaultSeller: number | null;
    store: { id: number; name: string };
    categories: { id: number; name: string }[];
    initialProducts: PosProduct[];
    initialServices: PosService[];
    permissions: {
        discount: boolean;
        credit: boolean;
        cancel: boolean;
        suspend: boolean;
        refund: boolean;
        service_remove: boolean;
        service_reduce: boolean;
        service_increase: boolean;
        service_add: boolean;
    };
    maxDiscountPercent: number;
    cashRounding: number;
    /** Los medios de esta caja a esta hora, resueltos por el servidor. */
    paymentMethods: PresentedPaymentMethod[];
    /** Cada cuanto se pregunta por un cobro de pasarela en curso. */
    paymentPollSeconds: number;
    /** Un cobro por QR o datafono que seguia esperando al recargar. */
    pendingGatewayReference: string | null;
    posLayout: PosLayoutSettings;
}

/**
 * Que le falta a la linea para que la promocion aplique.
 *
 * El lleva N paga M se cuenta por articulo, asi que la caja puede ser concreta: «lleva 1 mas»
 * es una frase que el cajero le puede repetir al cliente.
 */
function missingFor(line: CartLine): string {
    const promotion = line.promotion;

    if (!promotion) {
        return '';
    }

    if (!promotion.depends_on_quantity) {
        return 'Aplicada';
    }

    if (promotion.needs && promotion.needs > 0) {
        const missing = promotion.needs - (Math.floor(line.quantity) % promotion.needs);

        return `Lleva ${number(missing)} más para el ${promotion.badge}`;
    }

    return 'Falta completar el combo';
}

let lineSequence = 0;

/** Una clave nueva para una línea del tiquete. */
function newKey(prefix: string): string {
    lineSequence += 1;

    return `${prefix}-${Date.now().toString(36)}-${lineSequence}`;
}

/** Completa los campos que un tiquete guardado puede no traer: los de antes no tienen `kind` ni `key`. */
function normalizeLine(line: Partial<CartLine>): CartLine {
    const kind: LineKind = line.kind ?? 'product';

    return {
        ...(line as CartLine),
        kind,
        key: line.key ?? newKey(kind === 'service' ? `s${line.service_id}` : `p${line.product_id}`),
        discount: line.discount ?? 0,
        promotion: line.promotion ?? null,
        skip_promotion: line.skip_promotion ?? false,
        promo_discount: line.promo_discount ?? 0,
    };
}

/** Lo que cobra una línea del tiquete, servicio con sus artículos incluido. */
function lineAmount(line: CartLine): number {
    const gross = Math.round(line.quantity * line.unit_price);

    if (line.kind === 'service') {
        const own = gross - Math.min(line.discount, gross);

        return own + (line.pricing_mode === 'itemized' ? (line.components ?? []).reduce((sum, component) => sum + componentTotal(component), 0) : 0);
    }

    return gross - Math.min(line.discount + line.promo_discount, gross);
}

/** Cuánto dura la oportunidad de deshacer «Vaciar» o «Cancelar cambio». */
const UNDO_MS = 8000;

export default function PosSale({
    session,
    openSessions,
    sellers,
    defaultSeller,
    store,
    categories,
    initialProducts,
    initialServices,
    permissions,
    maxDiscountPercent,
    cashRounding,
    paymentMethods,
    paymentPollSeconds,
    pendingGatewayReference,
    posLayout,
}: PosSaleProps) {
    const { errors, flash } = usePage<PageProps>().props;

    const [products, setProducts] = useState<PosProduct[]>(initialProducts);
    const [services, setServices] = useState<PosService[]>(initialServices);
    // `services` es el filtro propio de la barra de categorías.
    const [category, setCategory] = useState<number | 'services' | null>(null);
    // Un servicio de precio variable espera su valor antes de entrar al tiquete.
    const [pricePrompt, setPricePrompt] = useState<{ service: PosService | null; key: string | null; value: string } | null>(null);
    // Cambiar la cantidad de un servicio ajustado recalcula sus artículos: antes se confirma.
    const [pendingQuantity, setPendingQuantity] = useState<{ key: string; quantity: number } | null>(null);
    // La línea de servicio a la que se le está agregando un artículo.
    const [addingTo, setAddingTo] = useState<string | null>(null);
    const [choosing, setChoosing] = useState<PosFamily | null>(null);
    const [search, setSearch] = useState('');
    const [cart, setCart] = useState<CartLine[]>([]);
    const [customer, setCustomer] = useState<PosCustomer | null>(null);
    const [modal, setModal] = useState<'payment' | 'customer' | 'discount' | 'hold' | 'resume' | 'recent' | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [lastSale, setLastSale] = useState<LastSale | null>(null);
    const [approvals, setApprovals] = useState<Partial<Record<ApprovalAction, string>>>({});
    const [pinPrompt, setPinPrompt] = useState<{ action: ApprovalAction; message: string } | null>(null);
    // A quien se le atribuye la venta. Arranca en quien abrio la caja y se puede cambiar al
    // cobrar: en el mostrador la caja la abre uno y vende otro.
    const [seller, setSeller] = useState<number | null>(defaultSeller);
    const [ticketKey, setTicketKey] = useState(0);
    // Devolución abierta (la venta que se está devolviendo) y cambio en curso: lo devuelto paga el tiquete.
    const [returnSaleId, setReturnSaleId] = useState<number | null>(null);
    const [exchange, setExchange] = useState<ExchangeDraft | null>(null);
    const [leftoverMethod, setLeftoverMethod] = useState<'cash' | 'store_credit'>('cash');
    // Cobro por QR, link o datafono en curso: la venta nace cuando la pasarela aprueba.
    const [gatewayPayment, setGatewayPayment] = useState<GatewayPaymentState | null>(null);

    const barcodeRef = useRef<HTMLInputElement>(null);
    // Lo último que se vació a mano, mientras su aviso de «Deshacer» siga en pantalla.
    const undoRef = useRef<{ kind: 'ticket' | 'exchange'; toastId: string; restore: () => void } | null>(null);

    // Al volver del cobro, el POS muestra el cambio y ofrece imprimir sin buscar la venta.
    useEffect(() => {
        if (flash?.last_sale) {
            setLastSale(flash.last_sale);
        }
    }, [flash?.last_sale]);

    // La devolución queda con su documento DV: se ofrece imprimirlo sin buscarlo.
    useEffect(() => {
        if (flash?.last_return) {
            const printed = flash.last_return;
            toast.success(`Devolución ${printed.number} registrada.`, {
                action: { label: 'Imprimir', onClick: () => window.open(printed.print_url, '_blank', 'width=420,height=680') },
                duration: 10000,
            });
        }
    }, [flash?.last_return]);

    // Un turno dura ocho horas: sin este latido la sesion caduca con el tiquete armado.
    useEffect(() => {
        const timer = window.setInterval(() => {
            void fetch(route('session.heartbeat'), { headers: { Accept: 'application/json' } }).catch(() => undefined);
        }, 240_000);

        return () => window.clearInterval(timer);
    }, []);

    // --- Totales -----------------------------------------------------------

    const totals = useMemo(() => {
        let total = 0;
        let base = 0;
        let discount = 0;

        const add = (gross: number, lineDiscount: number, taxRate: number) => {
            const applied = Math.min(lineDiscount, gross);
            const lineTotal = gross - applied;

            total += lineTotal;
            base += Math.round(lineTotal / (1 + taxRate / 100));
            discount += applied;
        };

        for (const line of cart) {
            if (line.kind === 'service') {
                add(Math.round(line.quantity * line.unit_price), line.discount, line.tax_rate);

                // En un servicio cerrado los artículos van incluidos: valen cero.
                if (line.pricing_mode === 'itemized') {
                    for (const component of line.components ?? []) {
                        add(Math.round(component.quantity * component.unit_price), component.discount, component.tax_rate);
                    }
                }

                continue;
            }

            add(Math.round(line.quantity * line.unit_price), line.discount + line.promo_discount, line.tax_rate);
        }

        return { total, subtotal: base, tax: total - base, discount };
    }, [cart]);

    // El 3x2 y los combos solo existen cuando hay cantidades, y esa aritmetica vive en el
    // servidor. Repetirla aqui seria tener dos versiones de una regla de plata, asi que el
    // tiquete le pregunta cada vez que cambia.
    const quoteKey = useMemo(
        () =>
            JSON.stringify(
                cart.map((line) =>
                    line.kind === 'service'
                        ? {
                              service_id: line.service_id,
                              quantity: line.quantity,
                              unit_price: line.open_price ? line.unit_price : null,
                              discount: line.discount,
                              components: (line.components ?? []).map((component) => ({
                                  product_id: component.product_id,
                                  quantity: component.quantity,
                                  discount: component.discount,
                              })),
                          }
                        : {
                              product_id: line.product_id,
                              quantity: line.quantity,
                              unit_price: line.unit_price,
                              discount: line.discount,
                              skip_promotion: line.skip_promotion,
                          },
                ),
            ),
        [cart],
    );

    useEffect(() => {
        const items = JSON.parse(quoteKey) as Record<string, unknown>[];

        if (items.length === 0) {
            return;
        }

        const timer = window.setTimeout(() => {
            fetch(route('pos.quote'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ items }),
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((result: { lines: QuotedLine[] }) => {
                    setCart((current) =>
                        current.map((line, index) => {
                            const quoted = result.lines[index];

                            if (!quoted) {
                                return line;
                            }

                            // El servicio y sus artículos cobran lo que dice el servidor, no el navegador.
                            if (line.kind === 'service' && quoted.kind === 'service' && quoted.service_id === line.service_id) {
                                if (quoted.error) {
                                    return line.quote_error === quoted.error ? line : { ...line, quote_error: quoted.error };
                                }

                                const prices = new Map(quoted.components.map((component) => [component.product_id, component.unit_price]));
                                const components = (line.components ?? []).map((component) =>
                                    prices.has(component.product_id) && prices.get(component.product_id) !== component.unit_price
                                        ? { ...component, unit_price: prices.get(component.product_id) ?? component.unit_price }
                                        : component,
                                );
                                const unitPrice = line.open_price ? line.unit_price : (quoted.unit_price ?? line.unit_price);
                                const changed = unitPrice !== line.unit_price || line.quote_error || components.some((component, position) => component !== (line.components ?? [])[position]);

                                return changed ? { ...line, unit_price: unitPrice, components, quote_error: null } : line;
                            }

                            if (line.kind === 'product' && quoted.kind === 'product' && quoted.product_id === line.product_id && quoted.promo_discount !== line.promo_discount) {
                                return { ...line, promo_discount: quoted.promo_discount };
                            }

                            return line;
                        }),
                    );
                })
                .catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
    }, [quoteKey]);

    // --- Catalogo ----------------------------------------------------------

    const fetchProducts = useCallback(async (term: string, categoryId: number | 'services' | null) => {
        const params = new URLSearchParams();

        if (term) {
            params.set('search', term);
        }

        if (categoryId === 'services') {
            params.set('services', '1');
        } else if (categoryId) {
            params.set('category', String(categoryId));
        }

        const response = await fetch(`${route('pos.products')}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return { products: [] as PosProduct[], services: [] as PosService[], family: null as PosFamily | null };
        }

        const payload = (await response.json()) as { products: PosProduct[]; services: PosService[]; family?: PosFamily | null };
        setProducts(payload.products);
        setServices(payload.services ?? []);

        return { products: payload.products, services: payload.services ?? [], family: payload.family ?? null };
    }, []);

    useEffect(() => {
        const timer = setTimeout(() => {
            void fetchProducts(search, category);
        }, 250);

        return () => clearTimeout(timer);
    }, [search, category, fetchProducts]);

    // --- Carrito -----------------------------------------------------------

    const addProduct = useCallback((product: PosProduct, quantity = 1) => {
        if (product.stock <= 0 && !product.allow_negative_stock) {
            if (posLayout.sounds) {
                playErrorSound();
            }

            // Decir donde esta ahorra el viaje a la bodega para averiguarlo.
            toast.error(
                product.other_stock > 0
                    ? `${product.name} se agotó en el punto de venta. Hay ${number(product.other_stock)} en otra bodega.`
                    : `${product.name} está agotado en esta sede.`,
            );

            return;
        }

        // Que el cajero se entere en el momento, no al final del tiquete.
        if (product.promotion) {
            toast.info(`${product.name}: ${product.promotion.hint}`, { description: 'Puedes quitársela desde el tiquete.' });
        }

        setCart((current) => {
            // Un artículo suelto se suma a su propia línea, nunca a la de un servicio que lo lleve.
            const existing = current.find((line) => line.kind === 'product' && line.product_id === product.id);

            if (existing) {
                const nextQuantity = Number((existing.quantity + quantity).toFixed(3));

                if (nextQuantity > product.stock && !product.allow_negative_stock) {
                    toast.warning(`Solo quedan ${number(product.stock)} de ${product.name}.`);

                    return current;
                }

                return current.map((line) => (line.key === existing.key ? { ...line, quantity: nextQuantity } : line));
            }

            return [
                ...current,
                {
                    kind: 'product',
                    key: newKey(`p${product.id}`),
                    product_id: product.id,
                    name: product.name,
                    variant_label: product.variant_label ?? null,
                    unit: product.unit,
                    sold_by_weight: product.sold_by_weight,
                    quantity,
                    unit_price: product.price,
                    tax_rate: product.tax_rate,
                    discount: 0,
                    stock: product.stock,
                    allow_negative_stock: product.allow_negative_stock,
                    promotion: product.promotion ?? null,
                    skip_promotion: false,
                    promo_discount: 0,
                },
            ];
        });
    }, [posLayout.sounds]);

    /** El cajero decide si esta linea lleva su promocion o se cobra el precio de lista. */
    const togglePromotion = (key: string) => {
        setCart((current) =>
            current.map((line) =>
                line.key === key
                    ? { ...line, skip_promotion: !line.skip_promotion, promo_discount: 0 }
                    : line,
            ),
        );
    };

    const setQuantity = (key: string, quantity: number) => {
        setCart((current) =>
            current
                .map((line) => {
                    if (line.key !== key) {
                        return line;
                    }

                    const next = Number(quantity.toFixed(3));

                    if (next > line.stock && !line.allow_negative_stock) {
                        toast.warning(`Solo quedan ${number(line.stock)} de ${line.name}.`);

                        return line;
                    }

                    return { ...line, quantity: next };
                })
                .filter((line) => line.quantity > 0),
        );
    };

    const removeLine = (key: string) => {
        setCart((current) => current.filter((line) => line.key !== key));
    };

    // --- Servicios ---------------------------------------------------------

    /** Lo que el cajero puede cambiar en un servicio: por su permiso o porque lo autorizó un supervisor. */
    const canService = useCallback((action: ServiceAction) => permissions[action] || Boolean(approvals[action]), [permissions, approvals]);

    const updateLine = (key: string, change: (line: CartLine) => CartLine) =>
        setCart((current) => current.map((line) => (line.key === key ? change(line) : line)));

    const addService = useCallback(
        (service: PosService, price?: number) => {
            // S6: con un artículo inactivo no se cobra hasta corregir el maestro o quitarlo.
            if (service.blocked.length > 0 && !permissions.service_remove && !approvals.service_remove) {
                if (posLayout.sounds) {
                    playErrorSound();
                }

                toast.error(`${service.name} no se puede cobrar: ${service.blocked.join(', ')}. Corrige el servicio o pide autorización para quitarlo.`);

                return;
            }

            if (service.open_price && price === undefined) {
                setPricePrompt({ service, key: null, value: '' });

                return;
            }

            const itemized = service.pricing_mode === 'itemized';

            setCart((current) => {
                // El mismo servicio sin ajustes suma una unidad; uno ajustado o de precio variable va aparte.
                const existing = service.open_price
                    ? undefined
                    : current.find(
                          (line) =>
                              line.kind === 'service' &&
                              line.service_id === service.id &&
                              (line.removed_components ?? []).length === 0 &&
                              !(line.components ?? []).some(isComponentAdjusted),
                      );

                if (existing) {
                    const quantity = existing.quantity + 1;

                    return current.map((line) =>
                        line.key === existing.key ? { ...line, quantity, components: componentsFromMaster(service.components, quantity, itemized) } : line,
                    );
                }

                const components = componentsFromMaster(service.components, 1, itemized);

                return [
                    ...current,
                    {
                        kind: 'service',
                        key: newKey(`s${service.id}`),
                        service_id: service.id,
                        pricing_mode: service.pricing_mode,
                        open_price: service.open_price,
                        master: service.components,
                        // Lo inactivo no entra: queda como quitado, a la vista, para que el cajero lo sepa.
                        components: components.filter((component) => component.is_active),
                        removed_components: components
                            .filter((component) => !component.is_active)
                            .map((component) => ({ product_id: component.product_id, name: component.name, master_quantity: component.master_quantity ?? 0 })),
                        name: service.name,
                        unit: 'unit',
                        sold_by_weight: false,
                        quantity: 1,
                        unit_price: service.open_price ? (price ?? 0) : service.price,
                        tax_rate: service.tax_rate,
                        discount: 0,
                        stock: 0,
                        allow_negative_stock: true,
                        promotion: null,
                        skip_promotion: false,
                        promo_discount: 0,
                    },
                ];
            });
        },
        [permissions, approvals, posLayout.sounds],
    );

    /** Cambia la cantidad del servicio: los artículos se recalculan desde el maestro (S5). */
    const applyServiceQuantity = (key: string, quantity: number) => {
        if (quantity <= 0) {
            removeLine(key);

            return;
        }

        updateLine(key, (line) => {
            const master = line.master ?? [];
            const fresh = componentsFromMaster(master, quantity, line.pricing_mode === 'itemized');

            return {
                ...line,
                quantity,
                components: fresh.filter((component) => component.is_active),
                removed_components: fresh
                    .filter((component) => !component.is_active)
                    .map((component) => ({ product_id: component.product_id, name: component.name, master_quantity: component.master_quantity ?? 0 })),
            };
        });
    };

    const setServiceQuantity = (key: string, quantity: number) => {
        const line = cart.find((entry) => entry.key === key);

        if (!line) {
            return;
        }

        const adjusted = (line.removed_components ?? []).some((removed) => (line.master ?? []).some((component) => component.product_id === removed.product_id && component.is_active))
            || (line.components ?? []).some(isComponentAdjusted);

        if (adjusted && quantity > 0) {
            setPendingQuantity({ key, quantity });

            return;
        }

        applyServiceQuantity(key, quantity);
    };

    /** Otra presentación del artículo completo: se cambia libre, el servidor lo vuelve a revisar. */
    const swapServiceComponent = (key: string, productId: number, optionId: number) =>
        updateLine(key, (line) => ({
            ...line,
            components: (line.components ?? []).map((component) => {
                const option = component.product_id === productId ? component.family?.options.find((entry) => entry.product_id === optionId) : undefined;

                return option ? swapComponent(component, option, line.pricing_mode === 'itemized') : component;
            }),
        }));

    const setComponentQuantity = (key: string, productId: number, quantity: number) =>
        updateLine(key, (line) => ({
            ...line,
            components: (line.components ?? []).map((component) => {
                if (component.product_id !== productId) {
                    return component;
                }

                const next = component.sold_by_weight ? q3(quantity) : Math.round(quantity);

                if (next <= 0) {
                    return component;
                }

                if (!component.allow_negative_stock && next > component.stock + 0.0005 && next > component.quantity) {
                    toast.warning(`Solo hay ${number(component.stock)} de ${component.name} en la caja.`);

                    return component;
                }

                return { ...component, quantity: next };
            }),
        }));

    const removeComponent = (key: string, productId: number) =>
        updateLine(key, (line) => {
            const component = (line.components ?? []).find((entry) => entry.product_id === productId);

            if (!component) {
                return line;
            }

            return {
                ...line,
                components: (line.components ?? []).filter((entry) => entry.product_id !== productId),
                // Uno agregado por el cajero simplemente se va; uno del maestro queda tachado para restaurarlo.
                removed_components:
                    component.master_quantity === null
                        ? line.removed_components
                        : [...(line.removed_components ?? []), { product_id: productId, name: component.name, master_quantity: component.master_quantity }],
            };
        });

    const restoreComponent = (key: string, productId: number) =>
        updateLine(key, (line) => {
            const master = (line.master ?? []).find((component) => component.product_id === productId);

            if (!master) {
                return line;
            }

            if (!master.is_active) {
                toast.error(`${master.name} está inactivo: no se puede cobrar.`);

                return line;
            }

            const [restored] = componentsFromMaster([master], line.quantity, line.pricing_mode === 'itemized');

            return {
                ...line,
                components: [...(line.components ?? []), restored],
                removed_components: (line.removed_components ?? []).filter((removed) => removed.product_id !== productId),
            };
        });

    const resetToMaster = (key: string) => {
        const line = cart.find((entry) => entry.key === key);

        if (line) {
            applyServiceQuantity(key, line.quantity);
        }
    };

    const addComponent = (key: string, product: PosProduct) => {
        updateLine(key, (line) => {
            if ((line.components ?? []).some((component) => component.product_id === product.id)) {
                toast.info(`${product.name} ya está en el servicio.`);

                return line;
            }

            const master = (line.master ?? []).find((component) => component.product_id === product.id);

            // Si era del maestro y se había quitado, se restaura como estaba; si no, entra como agregado.
            if (master) {
                const [restored] = componentsFromMaster([master], line.quantity, line.pricing_mode === 'itemized');

                return {
                    ...line,
                    components: [...(line.components ?? []), restored],
                    removed_components: (line.removed_components ?? []).filter((removed) => removed.product_id !== product.id),
                };
            }

            return {
                ...line,
                components: [
                    ...(line.components ?? []),
                    {
                        product_id: product.id,
                        name: product.name,
                        unit: product.unit,
                        sold_by_weight: product.sold_by_weight,
                        master_quantity: null,
                        quantity: 1,
                        unit_price: line.pricing_mode === 'itemized' ? product.price : 0,
                        tax_rate: product.tax_rate,
                        discount: 0,
                        stock: product.stock,
                        allow_negative_stock: product.allow_negative_stock,
                        returnable_by_default: true,
                        is_active: true,
                    },
                ],
            };
        });
        setAddingTo(null);
    };

    const clearTicket = () => {
        setCart([]);
        setCustomer(null);
        setApprovals({});
        setExchange(null);
        setLeftoverMethod('cash');
    };

    /** Ofrece deshacer durante UNDO_MS. Solo hay un deshacer a la vez: el nuevo reemplaza al anterior. */
    const offerUndo = (kind: 'ticket' | 'exchange', title: string, description: string, apply: () => void) => {
        const toastId = `deshacer-${Date.now()}`;
        const restore = () => {
            undoRef.current = null;
            toast.dismiss(toastId);
            apply();
            barcodeRef.current?.focus();
        };
        const forget = () => {
            if (undoRef.current?.toastId === toastId) {
                undoRef.current = null;
            }
        };

        if (undoRef.current) {
            toast.dismiss(undoRef.current.toastId);
        }

        undoRef.current = { kind, toastId, restore };
        toast(title, {
            id: toastId,
            description,
            duration: UNDO_MS,
            action: { label: 'Deshacer', onClick: restore },
            onAutoClose: forget,
            onDismiss: forget,
        });
    };

    /** «Vaciar» a mano: un toque errado no puede costar el tiquete entero. */
    const emptyTicket = () => {
        const before = { cart, customer, approvals, exchange, leftoverMethod };
        const lines = cart.length;

        clearTicket();
        barcodeRef.current?.focus();
        offerUndo('ticket', 'Tiquete vaciado', `${lines} ${lines === 1 ? 'artículo' : 'artículos'} · ${money(totals.total)}`, () => {
            setCart(before.cart);
            setCustomer(before.customer);
            setApprovals(before.approvals);
            setExchange(before.exchange);
            setLeftoverMethod(before.leftoverMethod);
        });
    };

    /** «Cancelar cambio» también se deshace: lo devuelto vuelve a pagar el tiquete. */
    const cancelExchange = () => {
        if (!exchange) {
            return;
        }

        const before = { exchange, leftoverMethod };

        setExchange(null);
        offerUndo('exchange', 'Cambio cancelado', `Venta ${before.exchange.sale_number} · ${money(before.exchange.value)}`, () => {
            setExchange(before.exchange);
            setLeftoverMethod(before.leftoverMethod);
        });
    };

    // --- Codigo de barras --------------------------------------------------

    const handleBarcode = async (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const code = event.currentTarget.value.trim();

        if (!code) {
            return;
        }

        // El codigo de un servicio no puede ser el de un articulo: no hay ambiguedad.
        const localService = services.find((service) => service.barcode === code || service.code === code);

        if (localService) {
            addService(localService);
            event.currentTarget.value = '';

            return;
        }

        const local = products.find((product) => product.barcode === code || product.sku === code);
        // El pitido es del lector: suena al encontrar, no cada vez que se toca una tecla.
        const beep = (found: PosProduct) => {
            if (posLayout.sounds && (found.stock > 0 || found.allow_negative_stock)) {
                playScanSound();
            }
        };

        if (local) {
            beep(local);
            addProduct(local);
            event.currentTarget.value = '';

            return;
        }

        const result = await fetchProducts(code, null);

        // El código de un artículo con subartículos no cobra nada: se elige cuál.
        if (result.family) {
            setChoosing(result.family);
            event.currentTarget.value = '';

            return;
        }

        const exactService = result.services.find((service) => service.barcode === code || service.code === code);

        if (exactService) {
            addService(exactService);
            event.currentTarget.value = '';
            setSearch('');
            void fetchProducts('', category);

            return;
        }

        const found = result.products;

        if (found.length === 1) {
            beep(found[0]);
            addProduct(found[0]);
            event.currentTarget.value = '';
            setSearch('');
            void fetchProducts('', category);
        } else if (found.length === 0) {
            if (posLayout.sounds) {
                playErrorSound();
            }

            toast.error(`No hay ningún artículo con el código ${code}.`);
        } else {
            setSearch(code);
            toast.info('Varios artículos coinciden: elígelo en la cuadrícula.');
        }
    };

    // --- Atajos de teclado -------------------------------------------------

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const actions: Record<ShortcutAction, () => void> = {
                scan: () => barcodeRef.current?.focus(),
                customer: () => setModal('customer'),
                discount: () => permissions.discount && setModal('discount'),
                hold: () => permissions.suspend && cart.length > 0 && setModal('hold'),
                resume: () => permissions.suspend && setModal('resume'),
                recent: () => (permissions.cancel || permissions.refund) && setModal('recent'),
                pay: () => cart.length > 0 && setModal('payment'),
                pay_alt: () => cart.length > 0 && setModal('payment'),
            };

            // La tecla de cada accion la eligio la empresa: se busca la accion por la tecla.
            const action = (Object.keys(actions) as ShortcutAction[]).find((name) => posLayout.shortcuts[name] === event.key);

            if (action) {
                event.preventDefault();
                actions[action]();
            }

            if (event.key === 'Escape') {
                setModal(null);
                barcodeRef.current?.focus();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [cart.length, permissions, posLayout.shortcuts]);

    // Ctrl+Z deshace «Vaciar» o «Cancelar cambio» mientras su aviso siga visible. Con texto escrito
    // en un campo, Ctrl+Z sigue siendo el de siempre.
    useEffect(() => {
        const onUndoKey = (event: KeyboardEvent) => {
            if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z' || !undoRef.current) {
                return;
            }

            const field = event.target;
            const typing = (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) && field.value !== '';

            if (!typing) {
                event.preventDefault();
                undoRef.current.restore();
            }
        };

        window.addEventListener('keydown', onUndoKey);

        return () => window.removeEventListener('keydown', onUndoKey);
    }, []);

    // Si ya empezó otro tiquete (o abrió otro cambio), deshacer pisaría lo nuevo: el aviso se va.
    useEffect(() => {
        const pending = undoRef.current;

        if (!pending) {
            return;
        }

        const startedAgain = pending.kind === 'ticket' ? cart.length > 0 || customer !== null || exchange !== null : exchange !== null;

        if (startedAgain) {
            toast.dismiss(pending.toastId);
            undoRef.current = null;
        }
    }, [cart.length, customer, exchange]);

    useEffect(() => {
        if (modal === null) {
            barcodeRef.current?.focus();
        } else if (modal === 'payment') {
            barcodeRef.current?.blur();
        }
    }, [modal]);

    // --- Cobro -------------------------------------------------------------

    /** El tiquete como lo espera el servidor, igual para el cobro normal y el de pasarela. */
    const salePayload = (payments: PaymentPayload[]) => ({
        items: cart.map((line) =>
                    line.kind === 'service'
                        ? {
                              service_id: line.service_id,
                              quantity: line.quantity,
                              // Solo el precio variable lo pone el cajero; el resto lo decide el servidor.
                              unit_price: line.open_price ? line.unit_price : null,
                              discount: line.discount,
                              components: (line.components ?? []).map((component) => ({
                                  product_id: component.product_id,
                                  quantity: component.quantity,
                                  discount: component.discount,
                              })),
                          }
                        : {
                              product_id: line.product_id,
                              quantity: line.quantity,
                              unit_price: line.unit_price,
                              discount: line.discount,
                              skip_promotion: line.skip_promotion,
                          },
        ),
        payments,
        customer_id: customer?.id ?? exchange?.customer?.id ?? null,
        seller_employee_id: seller,
        // La caja viaja con el tiquete: la plata entra al cajon en el que se cobro.
        register_session_id: session.id,
        ...(exchange ? { return: { items: exchange.items, reason: exchange.reason, leftover_method: leftoverMethod } } : {}),
    });

    const afterSale = () => {
        clearTicket();
        setTicketKey((current) => current + 1);
        setSeller(defaultSeller);
        setModal(null);
        void fetchProducts(search, category);
    };

    const confirmSale = (payments: PaymentPayload[]) => {
        setSubmitting(true);

        router.post(exchange ? route('pos.sales.exchange', exchange.sale_id) : route('pos.sales.store'), salePayload(payments), {
            preserveScroll: true,
            onSuccess: afterSale,
            onFinish: () => setSubmitting(false),
        });
    };

    /**
     * Abre el cobro por la pasarela. El servidor valida el tiquete entero, lo guarda como
     * suspendido y pide el QR o el cobro en el datafono; la venta todavia no existe.
     */
    const startGateway = async (payments: PaymentPayload[], gateway: GatewayRequest) => {
        setSubmitting(true);

        try {
            const customerId = customer?.id ?? null;
            const response = await fetch(route('pos.gateway.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    ...salePayload(payments),
                    gateway,
                    held: {
                        label: `Cobro por pasarela ${new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}`,
                        total: totals.total,
                        customer_id: customerId,
                        payload: { items: cart, customer_id: customerId },
                    },
                }),
            });

            const body = (await response.json().catch(() => null)) as (GatewayPaymentState & { message?: string; errors?: Record<string, string[]> }) | null;

            if (!response.ok || !body) {
                const first = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
                toast.error(first ?? body?.message ?? 'No se pudo abrir el cobro por la pasarela.');

                return;
            }

            setModal(null);
            setGatewayPayment(body);
        } catch {
            toast.error('No hubo respuesta del servidor. Revisa la conexión y vuelve a intentarlo.');
        } finally {
            setSubmitting(false);
        }
    };

    /** Un cobro que seguia esperando al recargar la pantalla vuelve a mostrarse. */
    useEffect(() => {
        if (!pendingGatewayReference) {
            return;
        }

        void (async () => {
            const response = await fetch(route('pos.gateway.show', pendingGatewayReference), { headers: { Accept: 'application/json' } });

            if (response.ok) {
                setGatewayPayment((await response.json()) as GatewayPaymentState);
            }
        })();
    }, [pendingGatewayReference]);

    /**
     * Rechazado, vencido o cancelado. Si el tiquete sigue en pantalla se descarta el guardado; si
     * se recargo mientras se esperaba, se retoma el guardado.
     */
    const leaveGateway = async () => {
        const heldId = gatewayPayment?.held_sale_id ?? null;
        const restoring = cart.length === 0;
        setGatewayPayment(null);

        if (heldId === null) {
            return;
        }

        if (restoring) {
            const response = await fetch(route('pos.hold.index'), { headers: { Accept: 'application/json' } });
            const ticket = response.ok ? ((await response.json()) as { held: HeldTicket[] }).held.find((item) => item.id === heldId) : undefined;

            if (!ticket) {
                return;
            }

            setCart(ticket.payload.items.map((item) => normalizeLine(item)));
            setCustomer(ticket.customer);
        }

        await fetch(route('pos.hold.destroy', heldId), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
        });

        setModal('payment');
    };

    return (
        <PosLayout title="Punto de venta" current="sale">
            <header className="flex h-16 shrink-0 items-center gap-3 border-b border-[var(--line)] bg-[var(--surface)] px-4">
                <div className="min-w-0">
                    {/* Con varias cajas abiertas se puede cambiar de una a otra: en el
                        mostrador se turnan durante el día. */}
                    {openSessions.length > 1 ? (
                        <select
                            value={session.id}
                            onChange={(event) => router.get(route('pos.index'), { caja: event.target.value }, { preserveScroll: true })}
                            className="max-w-[13.75rem] truncate rounded-chip border border-[var(--line)] bg-[var(--surface-2)] px-2 py-0.5 text-[0.84375rem] font-semibold focus:border-[var(--accent)] focus:outline-none"
                        >
                            {openSessions.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.register} · {item.cashier}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <p className="truncate text-[0.84375rem] font-semibold">{session.register}</p>
                    )}
                    <p className="truncate text-[0.71875rem] text-[var(--muted)]">
                        {/* Si la abrió otro, se dice: el efectivo de este turno es suyo. */}
                        {session.is_own ? session.cashier : `Abierta por ${session.cashier}`} · desde {time(session.opened_at)} ·{' '}
                        {store.name}
                    </p>
                </div>

                <div className="relative ml-2 flex w-[18.75rem] items-center">
                    <BarcodeIcon size={18} className="pointer-events-none absolute left-3 text-[var(--muted)]" />
                    <input
                        ref={barcodeRef}
                        autoFocus
                        type="text"
                        placeholder="Código de barras"
                        onKeyDown={handleBarcode}
                        className="h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] pl-10 pr-3 font-mono text-[0.875rem] tabular focus:border-[var(--accent)] focus:outline-none"
                    />
                </div>

                <div className="ml-auto flex items-center gap-1.5">
                    <ShortcutButton label="Cliente" hint={posLayout.shortcuts.customer} onClick={() => setModal('customer')} />
                    {permissions.discount && <ShortcutButton label="Descuento" hint={posLayout.shortcuts.discount} onClick={() => setModal('discount')} />}
                    {permissions.suspend && (
                        <ShortcutButton label="Suspender" hint={posLayout.shortcuts.hold} onClick={() => cart.length > 0 && setModal('hold')} />
                    )}
                    {permissions.suspend && <ShortcutButton label="Recuperar" hint={posLayout.shortcuts.resume} onClick={() => setModal('resume')} />}
                    {(permissions.cancel || permissions.refund) && (
                        <ShortcutButton label="Devolución" hint={posLayout.shortcuts.recent} onClick={() => setModal('recent')} />
                    )}
                </div>
            </header>

            <div className="flex min-h-0 flex-1">
                <section className="flex min-w-0 flex-1 flex-col p-4">
                    <div className="mb-3 flex shrink-0 items-center gap-2">
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar artículo por nombre o SKU"
                            className="h-11 flex-1 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
                        />
                    </div>

                    {/* En «solo búsqueda» no hay catálogo a la vista: tampoco categorías. */}
                    {posLayout.catalog_view !== 'search' && (
                        <div className="mb-3 flex shrink-0 gap-1.5 overflow-x-auto pb-1">
                            <CategoryChip active={category === null} onClick={() => setCategory(null)}>
                                Todas
                            </CategoryChip>
                            {initialServices.length > 0 && (
                                <CategoryChip active={category === 'services'} onClick={() => setCategory('services')}>
                                    Servicios
                                </CategoryChip>
                            )}
                            {categories.map((item) => (
                                <CategoryChip key={item.id} active={category === item.id} onClick={() => setCategory(item.id)}>
                                    {item.name}
                                </CategoryChip>
                            ))}
                        </div>
                    )}

                    {/* El catálogo como lo eligió la empresa en Ajustes > Caja: teclas, lista o solo lo buscado. */}
                    {posLayout.catalog_view === 'search' && search.trim() === '' ? (
                        <div className="grid min-h-0 flex-1 place-items-center text-center text-[var(--muted)]">
                            <span className="flex flex-col items-center gap-2">
                                <BarcodeIcon size={34} />
                                <span className="text-[0.84375rem]">Escanea un código o escribe arriba para buscar un artículo.</span>
                            </span>
                        </div>
                    ) : posLayout.catalog_view !== 'grid' ? (
                        <CatalogList products={products} services={services} onPick={addProduct} onPickService={addService} />
                    ) : (
                    <div className={cn('grid min-h-0 flex-1 auto-rows-min gap-2 overflow-y-auto pr-1', TILE_GRID[posLayout.tile_size].grid)}>
                        {services.map((service) => (
                            <ServiceTile
                                key={`s-${service.id}`}
                                service={service}
                                tile={TILE_GRID[posLayout.tile_size]}
                                mode={posLayout.tile_style}
                                onPick={() => addService(service)}
                            />
                        ))}
                        {products.map((product) => (
                            <ProductTile
                                key={product.id}
                                product={product}
                                tile={TILE_GRID[posLayout.tile_size]}
                                mode={posLayout.tile_style}
                                onPick={() => addProduct(product)}
                            />
                        ))}

                        {products.length === 0 && services.length === 0 && (
                            <div className="col-span-full grid place-items-center gap-2 py-16 text-center text-[var(--muted)]">
                                <PackageIcon size={32} />
                                <p className="text-[0.84375rem]">Ningún artículo coincide con la búsqueda.</p>
                            </div>
                        )}
                    </div>
                    )}
                </section>

                {/* El tiquete va del lado que eligió la empresa; `order-first` lo pasa a la izquierda. */}
                <aside
                    className={cn(
                        'flex w-[27rem] shrink-0 flex-col border-[var(--line)] bg-[var(--surface)]',
                        posLayout.ticket_side === 'left' ? 'order-first border-r' : 'border-l',
                    )}
                >
                    <div className="flex shrink-0 items-center justify-between gap-2 border-b border-[var(--line)] px-4 py-3">
                        <div className="min-w-0">
                            <p className="text-[0.8125rem] font-semibold">Tiquete en curso</p>
                            <p className="text-[0.71875rem] text-[var(--muted)]">
                                {cart.length} {cart.length === 1 ? 'artículo' : 'artículos'}
                            </p>
                        </div>
                        {cart.length > 0 && (
                            <Button size="sm" variant="ghost" icon={<TrashIcon size={15} />} onClick={emptyTicket}>
                                Vaciar
                            </Button>
                        )}
                    </div>

                    {exchange && (
                        <div className="shrink-0 border-b border-[var(--warn)]/40 bg-[var(--warn-soft)] px-4 py-2.5 text-[0.78125rem]">
                            <div className="flex items-start justify-between gap-2">
                                <p>
                                    <span className="font-semibold text-[var(--warn)]">Cambio · venta {exchange.sale_number}</span>
                                    <span className="block text-[var(--muted)]">
                                        Lo devuelto vale {money(exchange.value)} y paga primero. Agrega lo que se lleva.
                                    </span>
                                </p>
                                <button
                                    type="button"
                                    onClick={cancelExchange}
                                    className="shrink-0 text-[0.71875rem] font-medium text-[var(--danger)] hover:underline"
                                >
                                    Cancelar cambio
                                </button>
                            </div>
                            {exchange.value > totals.total && (
                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                    <span className="text-[var(--muted)]">Le sobran {money(exchange.value - totals.total)}:</span>
                                    <select
                                        aria-label="Lo que sobra del cambio"
                                        value={leftoverMethod}
                                        onChange={(event) => setLeftoverMethod(event.target.value as 'cash' | 'store_credit')}
                                        className="h-8 rounded-control border border-[var(--line)] bg-[var(--surface)] px-2 text-[0.75rem]"
                                    >
                                        <option value="cash">Se le devuelven en efectivo</option>
                                        {(customer ?? exchange.customer) && <option value="store_credit">Quedan como saldo a favor</option>}
                                    </select>
                                </div>
                            )}
                        </div>
                    )}

                    <button
                        type="button"
                        onClick={() => setModal('customer')}
                        className="flex shrink-0 items-center gap-2.5 border-b border-[var(--line)] px-4 py-2.5 text-left transition-colors hover:bg-[var(--surface-2)]"
                    >
                        <UserIcon size={18} className="text-[var(--muted)]" />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[0.8125rem] font-medium">{customer ? customer.name : 'Consumidor final'}</span>
                            {customer && <span className="block truncate font-mono text-[0.71875rem] text-[var(--muted)]">{customer.document_number}</span>}
                        </span>
                        <span className="text-[0.71875rem] text-[var(--muted)]">{posLayout.shortcuts.customer}</span>
                    </button>

                    {customer?.credit_enabled && (
                        <div className="grid shrink-0 grid-cols-3 gap-2 border-b border-[var(--line)] bg-[var(--surface-2)] px-4 py-2.5 text-center">
                            <CreditStat label="Cupo" value={money(customer.credit_limit)} />
                            <CreditStat label="Deuda" value={money(customer.balance)} tone={customer.balance > 0 ? 'warn' : undefined} />
                            <CreditStat
                                label="Disponible"
                                value={money(customer.available_credit)}
                                tone={customer.available_credit <= 0 ? 'danger' : 'accent'}
                            />
                        </div>
                    )}

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {cart.length === 0 ? (
                            <div className="grid h-full place-items-center gap-2 px-6 text-center text-[var(--muted)]">
                                <BarcodeIcon size={34} />
                                <p className="text-[0.84375rem]">Escanea un código o toca un artículo para empezar.</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-[var(--line)]">
                                {cart.map((line) => {
                                    if (line.kind === 'service') {
                                        return (
                                            <ServiceLineCard
                                                key={line.key}
                                                line={line}
                                                can={canService}
                                                onServiceQuantity={(next) => setServiceQuantity(line.key, next)}
                                                onEditPrice={() => setPricePrompt({ service: null, key: line.key, value: String(line.unit_price) })}
                                                onComponentQuantity={(productId, next) => setComponentQuantity(line.key, productId, next)}
                                                onRemoveComponent={(productId) => removeComponent(line.key, productId)}
                                                onSwapComponent={(productId, optionId) => swapServiceComponent(line.key, productId, optionId)}
                                                onRestoreComponent={(productId) => restoreComponent(line.key, productId)}
                                                onRequestAuthorization={(action, message) => setPinPrompt({ action, message })}
                                                onAddComponent={() => setAddingTo(line.key)}
                                                onResetToMaster={() => resetToMaster(line.key)}
                                                onRemoveLine={() => removeLine(line.key)}
                                            />
                                        );
                                    }

                                    return (
                                        <li key={line.key} className="px-4 py-2.5">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="min-w-0 flex-1 text-[0.84375rem] font-medium leading-snug">
                                                    {line.name}
                                                    {line.variant_label && (
                                                        <span className="ml-1.5 inline-block rounded-chip bg-[var(--accent-soft)] px-1.5 py-0.5 align-middle text-[0.6875rem] font-semibold text-[var(--accent)]">
                                                            {line.variant_label}
                                                        </span>
                                                    )}
                                                </p>
                                                <button
                                                    type="button"
                                                    onClick={() => removeLine(line.key)}
                                                    aria-label={`Quitar ${line.name}`}
                                                    className="shrink-0 text-[var(--muted)] transition-colors hover:text-[var(--danger)]"
                                                >
                                                    <TrashIcon size={15} />
                                                </button>
                                            </div>

                                            <div className="mt-1.5 flex items-center gap-2">
                                                <div className="flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => setQuantity(line.key, line.quantity - (line.sold_by_weight ? 0.1 : 1))}
                                                        aria-label="Quitar uno"
                                                        className="grid size-11 place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] transition-colors hover:bg-[var(--surface-3)]"
                                                    >
                                                        <MinusIcon size={15} />
                                                    </button>
                                                    <input
                                                        type="number"
                                                        step={line.sold_by_weight ? 0.001 : 1}
                                                        min={0}
                                                        value={line.quantity}
                                                        onChange={(event) => setQuantity(line.key, Number(event.target.value))}
                                                        className="h-11 w-[4.625rem] rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-1 text-center font-mono text-[0.875rem] tabular focus:border-[var(--accent)] focus:outline-none"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => setQuantity(line.key, line.quantity + (line.sold_by_weight ? 0.1 : 1))}
                                                        aria-label="Agregar uno"
                                                        className="grid size-11 place-items-center rounded-control border border-[var(--line)] bg-[var(--surface-2)] transition-colors hover:bg-[var(--surface-3)]"
                                                    >
                                                        <PlusIcon size={15} />
                                                    </button>
                                                </div>

                                                <span className="ml-auto text-right">
                                                    <span className="block font-mono text-[0.9375rem] font-semibold tabular">
                                                        {money(lineAmount(line))}
                                                    </span>
                                                    <span className="block font-mono text-[0.6875rem] text-[var(--muted)] tabular">
                                                        {money(line.unit_price)} c/u
                                                        {line.discount > 0 ? ` · −${money(line.discount)}` : ''}
                                                    </span>
                                                </span>
                                            </div>

                                            {/* La promocion se anuncia en la linea y el cajero decide si la aplica. */}
                                            {line.promotion && (
                                                <div
                                                    className={cn(
                                                        'mt-2 flex items-center gap-2 rounded-control border px-2.5 py-1.5',
                                                        line.skip_promotion
                                                            ? 'border-[var(--line)] bg-[var(--surface-2)]'
                                                            : 'border-[var(--warn)]/50 bg-[var(--warn-soft)]',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'shrink-0 rounded-chip px-1.5 py-0.5 font-mono text-[0.65625rem] font-bold',
                                                            line.skip_promotion
                                                                ? 'bg-[var(--surface-3)] text-[var(--muted)]'
                                                                : 'bg-[var(--warn)] text-[var(--bg)]',
                                                        )}
                                                    >
                                                        {line.promotion.badge}
                                                    </span>

                                                    <span className="min-w-0 flex-1">
                                                        <span
                                                            className={cn(
                                                                'block truncate text-[0.71875rem] font-medium',
                                                                line.skip_promotion ? 'text-[var(--muted)] line-through' : 'text-[var(--warn)]',
                                                            )}
                                                        >
                                                            {line.promotion.hint}
                                                        </span>
                                                        <span className="block truncate text-[0.65625rem] text-[var(--muted)]">
                                                            {line.skip_promotion
                                                                ? 'Se cobra el precio de lista'
                                                                : line.promo_discount > 0
                                                                  ? `Rebaja ${money(line.promo_discount)}`
                                                                  : missingFor(line)}
                                                        </span>
                                                    </span>

                                                    <button
                                                        type="button"
                                                        onClick={() => togglePromotion(line.key)}
                                                        className={cn(
                                                            'shrink-0 rounded-chip px-2 py-1 text-[0.71875rem] font-semibold transition-colors',
                                                            line.skip_promotion
                                                                ? 'bg-[var(--accent)] text-[var(--accent-ink)]'
                                                                : 'border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)]',
                                                        )}
                                                    >
                                                        {line.skip_promotion ? 'Aplicar' : 'No aplicar'}
                                                    </button>
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>

                    {/* `shrink-0`: en una pantalla baja se encoge la lista, nunca el cobro. */}
                    <div className="shrink-0 border-t border-[var(--line)] px-4 py-3">
                        <dl className="flex flex-col gap-1 text-[0.8125rem]">
                            <div className="flex items-center justify-between text-[var(--muted)]">
                                <dt>Base</dt>
                                <dd className="font-mono tabular">{money(totals.subtotal)}</dd>
                            </div>
                            <div className="flex items-center justify-between text-[var(--muted)]">
                                <dt>IVA</dt>
                                <dd className="font-mono tabular">{money(totals.tax)}</dd>
                            </div>
                            {totals.discount > 0 && (
                                <div className="flex items-center justify-between text-[var(--warn)]">
                                    <dt>Descuento</dt>
                                    <dd className="font-mono tabular">− {money(totals.discount)}</dd>
                                </div>
                            )}
                            <div className="mt-1 flex items-center justify-between border-t border-[var(--line)] pt-2">
                                <dt className="text-[0.9375rem] font-semibold">TOTAL</dt>
                                <dd
                                    className={cn(
                                        'font-mono font-semibold leading-none text-[var(--accent)] tabular',
                                        posLayout.total_size === 'large' ? 'text-[2.25rem]' : 'text-[1.75rem]',
                                    )}
                                >
                                    {money(totals.total)}
                                </dd>
                            </div>
                        </dl>

                        {errors.credit && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.credit}</p>}
                        {errors.stock && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.stock}</p>}
                        {errors.service && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.service}</p>}
                        {errors.items && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.items}</p>}
                        {errors.discount && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.discount}</p>}
                        {errors.payments && <p className="mt-2 text-[0.78125rem] font-medium text-[var(--danger)]">{errors.payments}</p>}

                        <Button
                            size="pos"
                            block
                            className={cn('mt-3', posLayout.total_size === 'large' ? 'h-[3.875rem] text-[1.1875rem]' : 'h-[3.375rem] text-[1.0625rem]')}
                            disabled={cart.length === 0}
                            onClick={() => setModal('payment')}
                        >
                            Cobrar · {money(totals.total)}
                            {/* La tecla de cobrar la elige la empresa: el botón dice cuál es. */}
                            <span className="rounded-chip bg-black/15 px-1.5 py-0.5 font-mono text-[0.6875rem] font-medium">{posLayout.shortcuts.pay}</span>
                        </Button>
                    </div>
                </aside>
            </div>

            <LastSaleModal sale={lastSale} onClose={() => setLastSale(null)} />

            <OpenPriceModal
                prompt={pricePrompt}
                onChange={(value) => setPricePrompt((current) => (current ? { ...current, value } : current))}
                onClose={() => setPricePrompt(null)}
                onConfirm={(value) => {
                    if (pricePrompt?.service) {
                        addService(pricePrompt.service, value);
                    } else if (pricePrompt?.key) {
                        updateLine(pricePrompt.key, (line) => ({ ...line, unit_price: value }));
                    }

                    setPricePrompt(null);
                }}
            />

            <ConfirmDialog
                open={pendingQuantity !== null}
                onClose={() => setPendingQuantity(null)}
                onConfirm={() => {
                    if (pendingQuantity) {
                        applyServiceQuantity(pendingQuantity.key, pendingQuantity.quantity);
                    }

                    setPendingQuantity(null);
                }}
                title="Cambiar la cantidad del servicio"
                message="Se perderán los ajustes de los artículos: se vuelven a armar desde el maestro para la nueva cantidad."
                confirmLabel="Cambiar"
                tone="primary"
            />

            <ComponentPickerModal open={addingTo !== null} onClose={() => setAddingTo(null)} onPick={(product) => addingTo && addComponent(addingTo, product)} />

            <SupervisorPinModal
                prompt={pinPrompt}
                onClose={() => setPinPrompt(null)}
                onApproved={(action, by) => {
                    setApprovals((current) => ({ ...current, [action]: by }));
                    setPinPrompt(null);
                    toast.success(`Autorizado por ${by}.`);
                }}
            />

            <PaymentDialog
                open={modal === 'payment'}
                onClose={() => setModal(null)}
                total={totals.total}
                cashRounding={cashRounding}
                ticketKey={ticketKey}
                ticketNumber={session.register_code ?? 'POS'}
                customer={customer}
                canSellOnCredit={permissions.credit}
                creditApprovedBy={approvals.credit}
                submitting={submitting}
                onRequestCreditApproval={(message) => setPinPrompt({ action: 'credit', message })}
                methods={paymentMethods}
                onConfirm={confirmSale}
                // Un cambio no se cobra por pasarela: la devolucion y la venta nueva van en un solo acto.
                onStartGateway={exchange ? undefined : (payments, gateway) => void startGateway(payments, gateway)}
                sellers={sellers}
                seller={seller}
                onSellerChange={setSeller}
                fixedPayments={
                    exchange && totals.total > 0
                        ? [{ key: 'exchange', method_id: null, kind: 'exchange', name: 'Cambio por devolución', amount: Math.min(exchange.value, totals.total), reference: exchange.sale_number }]
                        : undefined
                }
                confirmLabel={exchange ? 'Confirmar el cambio' : undefined}
            />

            <GatewayWaitDialog
                payment={gatewayPayment}
                pollSeconds={paymentPollSeconds}
                onUpdate={setGatewayPayment}
                onApplied={(sale) => {
                    setGatewayPayment(null);
                    setLastSale(sale);
                    afterSale();
                }}
                onBack={() => void leaveGateway()}
            />

            <CustomerModal
                open={modal === 'customer'}
                onClose={() => setModal(null)}
                onSelect={(selected) => {
                    setCustomer(selected);
                    setModal(null);
                }}
                current={customer}
            />

            <DiscountModal
                open={modal === 'discount'}
                onClose={() => setModal(null)}
                cart={cart}
                maxPercent={maxDiscountPercent}
                approvedBy={approvals.discount}
                onRequestApproval={(message) => setPinPrompt({ action: 'discount', message })}
                onApply={(lines) => {
                    setCart(lines);
                    setModal(null);
                }}
            />

            <HoldModal
                open={modal === 'hold'}
                onClose={() => setModal(null)}
                cart={cart}
                customer={customer}
                total={totals.total}
                onHeld={() => {
                    clearTicket();
                    setModal(null);
                    toast.success('Tiquete suspendido.');
                }}
            />

            <ResumeModal
                open={modal === 'resume'}
                onClose={() => setModal(null)}
                onResume={(ticket) => {
                    // Un tiquete suspendido antes de que existieran las promociones no trae
                    // esos campos: sin normalizarlo, el total saldria en blanco.
                    setCart(ticket.payload.items.map((item) => normalizeLine(item)));
                    // El cliente vuelve con el tiquete: no hay que pedirle los datos otra vez.
                    setCustomer(ticket.customer);
                    setModal(null);

                    if (ticket.customer_name && !ticket.customer) {
                        toast.warning(`Tiquete «${ticket.label}» recuperado, pero ${ticket.customer_name} ya no está activo como cliente.`, {
                            description: 'Elige otro cliente o cóbralo como consumidor final.',
                        });
                    } else {
                        toast.success(`Tiquete «${ticket.label}» recuperado${ticket.customer ? ` con ${ticket.customer.name}` : ''}.`);
                    }
                }}
            />

            <RecentSalesModal
                open={modal === 'recent'}
                onClose={() => setModal(null)}
                canCancel={permissions.cancel}
                canRefund={permissions.refund}
                onReturn={(saleId) => {
                    setModal(null);
                    setReturnSaleId(saleId);
                }}
            />

            <ReturnDialog
                saleId={returnSaleId}
                onClose={() => setReturnSaleId(null)}
                ticketCustomer={customer ? { id: customer.id, name: customer.name } : null}
                windowApprovedBy={approvals.return_window}
                onRequestWindowApproval={(message) => setPinPrompt({ action: 'return_window', message })}
                onExchange={(draft) => {
                    setExchange(draft);
                    setLeftoverMethod('cash');
                    toast.info(`Cambio de la venta ${draft.sale_number}: agrega lo que se lleva y cobra.`);
                }}
                onDone={() => {
                    setApprovals((current) => ({ ...current, return_window: undefined }));
                    void fetchProducts(search, category);
                }}
            />

            <VariantChooser
                family={choosing}
                onClose={() => setChoosing(null)}
                onPick={(variant) => {
                    setChoosing(null);
                    addProduct(variant);
                    setSearch('');
                }}
            />
        </PosLayout>
    );
}

// --- Piezas ----------------------------------------------------------------

/** Se escaneó el código de un artículo con subartículos: se elige cuál se cobra. */
function VariantChooser({ family, onClose, onPick }: { family: PosFamily | null; onClose: () => void; onPick: (variant: PosProduct) => void }) {
    return (
        <Modal open={family !== null} onClose={onClose} title={family?.name ?? ''} description="¿Cuál se lleva? El código del artículo no cobra nada por sí solo." size="lg">
            <ul className="grid gap-2 sm:grid-cols-2">
                {(family?.variants ?? []).map((variant) => {
                    const out = variant.stock <= 0 && !variant.allow_negative_stock;

                    return (
                        <li key={variant.id}>
                            <button
                                type="button"
                                disabled={out}
                                onClick={() => onPick(variant)}
                                className={cn(
                                    'flex w-full items-center justify-between gap-3 rounded-control border px-3 py-2.5 text-left transition-colors',
                                    out ? 'cursor-not-allowed border-[var(--line)] opacity-50' : 'border-[var(--line)] hover:border-[var(--accent)] hover:bg-[var(--surface-2)]',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="block font-semibold">{variant.variant_label ?? variant.name}</span>
                                    <span className="block text-[0.75rem] text-[var(--muted)] tabular-nums">{out ? 'Agotado' : `${number(variant.stock)} disponibles`}</span>
                                </span>
                                <span className="font-mono font-semibold text-[var(--accent)] tabular">{money(variant.price)}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </Modal>
    );
}

/**
 * Una tecla de articulo en la cuadricula, con la foto como la eligio la empresa en Ajustes > Caja:
 * pequena al lado del nombre, o grande ocupando casi toda la tecla con el nombre y el precio debajo.
 */
function ProductTile({ product, tile, mode, onPick }: { product: PosProduct; tile: TileSize; mode: TileStyle; onPick: () => void }) {
    const out = product.stock <= 0 && !product.allow_negative_stock;

    const badge = product.promotion && (
        <span
            title={product.promotion.hint}
            className="absolute right-2 top-2 rounded-chip bg-[var(--warn)] px-1.5 py-0.5 font-mono text-[0.65625rem] font-bold text-[var(--bg)]"
        >
            {product.promotion.badge}
        </span>
    );

    const price = (
        <span className="flex items-baseline gap-1.5">
            <span className={cn('font-mono font-semibold text-[var(--accent)] tabular', tile.price)}>{money(product.price)}</span>
            {product.promotion?.compare_at && product.promotion.compare_at > product.price && (
                <span className="font-mono text-[0.6875rem] text-[var(--muted)] line-through tabular">{money(product.promotion.compare_at)}</span>
            )}
        </span>
    );

    const stock = (
        <span className="block font-mono text-[0.6875rem] tabular" style={{ color: out ? 'var(--danger)' : product.stock < 5 ? 'var(--warn)' : 'var(--muted)' }}>
            {out ? 'Agotado' : `${number(product.stock)} ${UNIT_SHORT[product.unit] ?? ''}`}
        </span>
    );

    const otherStock = product.other_stock > 0 && (
        <span className="block font-mono text-[0.65625rem] text-[var(--warn)] tabular">+{number(product.other_stock)} en bodega</span>
    );

    return (
        <button
            type="button"
            disabled={out}
            onClick={onPick}
            className={cn(
                'relative flex flex-col rounded-card border text-left transition-colors',
                mode === 'compact' ? cn('justify-between', tile.tile) : 'overflow-hidden',
                out
                    ? 'cursor-not-allowed border-[var(--line)] bg-[var(--surface)] opacity-45'
                    : product.promotion
                      ? 'border-[var(--warn)] bg-[var(--surface)] hover:bg-[var(--surface-2)]'
                      : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--accent)] hover:bg-[var(--surface-2)]',
            )}
        >
            {mode === 'compact' ? (
                <>
                    {badge}
                    <span className="flex items-start gap-2 pr-10">
                        {product.thumb_url && <img src={product.thumb_url} alt="" loading="lazy" className="size-10 shrink-0 rounded-chip object-cover" />}
                        <span className="min-w-0">
                            <span className={cn('line-clamp-2 font-medium leading-snug', tile.name)}>{product.name}</span>
                            {product.variant_label && (
                                <span className="mt-1 inline-block rounded-chip bg-[var(--accent-soft)] px-1.5 py-0.5 text-[0.6875rem] font-semibold text-[var(--accent)]">
                                    {product.variant_label}
                                </span>
                            )}
                        </span>
                    </span>
                    <span>
                        {price}
                        {stock}
                        {otherStock}
                    </span>
                </>
            ) : (
                <>
                    {/* Las presentaciones de un mismo articulo suelen compartir foto: la etiqueta va encima para distinguirlas. */}
                    <TilePhoto src={product.thumb_url} name={product.name} mode={mode} nameClassName={tile.placeholder}>
                        {badge}
                        {product.variant_label && (
                            <span className="absolute bottom-2 left-2 max-w-[calc(100%-1rem)] truncate rounded-chip bg-[var(--surface)] px-1.5 py-0.5 text-[0.6875rem] font-semibold text-[var(--accent)] shadow-sm">
                                {product.variant_label}
                            </span>
                        )}
                    </TilePhoto>
                    <span className={cn('flex flex-1 flex-col gap-1', tile.pad)}>
                        {/* Sin foto el nombre ya va en grande en el cuadro. */}
                        {product.thumb_url && <span className={cn('line-clamp-2 font-medium', tile.name, 'leading-snug')}>{product.name}</span>}
                        <span className="mt-auto">
                            <span className="flex flex-wrap items-baseline justify-between gap-x-2">
                                {price}
                                {stock}
                            </span>
                            {otherStock}
                        </span>
                    </span>
                </>
            )}
        </button>
    );
}

/** Una tecla de servicio en la cuadrícula. Un servicio que no se puede cobrar sale marcado «Revisar». */
function ServiceTile({ service, tile, mode, onPick }: { service: PosService; tile: TileSize; mode: TileStyle; onPick: () => void }) {
    const blocked = service.blocked.length > 0;

    const badge = (
        <span
            className={cn(
                'absolute right-2 top-2 rounded-chip px-1.5 py-0.5 text-[0.65625rem] font-bold',
                blocked ? 'bg-[var(--danger)] text-white' : 'bg-[var(--accent-soft)] text-[var(--accent)]',
            )}
        >
            {blocked ? 'Revisar' : 'Servicio'}
        </span>
    );

    const price = (
        <span>
            <span className={cn('font-mono font-semibold text-[var(--accent)] tabular', tile.price)}>{service.open_price ? 'Valor abierto' : money(service.total)}</span>
            <span className="block text-[0.6875rem] text-[var(--muted)]">
                {service.components.length === 0
                    ? 'Solo valor'
                    : `${service.components.length} ${service.components.length === 1 ? 'artículo' : 'artículos'}${service.pricing_mode === 'itemized' ? ' aparte' : ' incluidos'}`}
            </span>
        </span>
    );

    return (
        <button
            type="button"
            onClick={onPick}
            title={blocked ? `No se puede cobrar: ${service.blocked.join(', ')}` : undefined}
            className={cn(
                'relative flex flex-col rounded-card border border-dashed text-left transition-colors',
                mode === 'compact' ? cn('justify-between', tile.tile) : 'overflow-hidden',
                blocked ? 'border-[var(--danger)] bg-[var(--surface)] opacity-70' : 'border-[var(--accent)]/60 bg-[var(--surface)] hover:bg-[var(--surface-2)]',
            )}
        >
            {mode === 'compact' ? (
                <>
                    {badge}
                    <span className="flex items-start gap-2 pr-16">
                        {service.thumb_url ? (
                            <img src={service.thumb_url} alt="" loading="lazy" className="size-10 shrink-0 rounded-chip object-cover" />
                        ) : (
                            <WrenchIcon size={18} className="mt-0.5 shrink-0 text-[var(--accent)]" />
                        )}
                        <span className={cn('line-clamp-2 font-medium leading-snug', tile.name)}>{service.name}</span>
                    </span>
                    {price}
                </>
            ) : (
                <>
                    <TilePhoto src={service.thumb_url} name={service.name} mode={mode} nameClassName={tile.placeholder}>
                        {badge}
                    </TilePhoto>
                    <span className={cn('flex flex-1 flex-col gap-1', tile.pad)}>
                        {service.thumb_url && <span className={cn('line-clamp-2 font-medium', tile.name, 'leading-snug')}>{service.name}</span>}
                        <span className="mt-auto">{price}</span>
                    </span>
                </>
            )}
        </button>
    );
}

/** El valor de un servicio de precio variable: se digita al agregarlo y se puede corregir. */
function OpenPriceModal({
    prompt,
    onChange,
    onClose,
    onConfirm,
}: {
    prompt: { service: PosService | null; key: string | null; value: string } | null;
    onChange: (value: string) => void;
    onClose: () => void;
    onConfirm: (value: number) => void;
}) {
    if (!prompt) {
        return null;
    }

    const value = Math.round(Number(prompt.value) || 0);

    return (
        <Modal
            open
            onClose={onClose}
            title={prompt.service ? `Valor de ${prompt.service.name}` : 'Cambiar el valor del servicio'}
            description="Este servicio tiene precio variable: el valor lo digita el cajero."
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button disabled={value < 1} onClick={() => onConfirm(value)}>
                        {prompt.service ? 'Agregar' : 'Guardar'} · {money(value)}
                    </Button>
                </>
            }
        >
            <input
                autoFocus
                type="number"
                min={1}
                value={prompt.value}
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && value >= 1) {
                        event.preventDefault();
                        onConfirm(value);
                    }
                }}
                className="h-14 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-center font-mono text-[1.75rem] tabular focus:border-[var(--accent)] focus:outline-none"
            />
        </Modal>
    );
}

/** Buscar el artículo que se le agrega a un servicio en esta venta. */
function ComponentPickerModal({ open, onClose, onPick }: { open: boolean; onClose: () => void; onPick: (product: PosProduct) => void }) {
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<PosProduct[]>([]);

    useEffect(() => {
        if (!open) {
            setSearch('');
            setResults([]);

            return;
        }

        const timer = setTimeout(async () => {
            const response = await fetch(`${route('pos.products')}?search=${encodeURIComponent(search)}`, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                setResults(((await response.json()) as { products: PosProduct[] }).products);
            }
        }, 250);

        return () => clearTimeout(timer);
    }, [search, open]);

    return (
        <Modal open={open} onClose={onClose} title="Agregar artículo al servicio" description="Solo en esta venta: el servicio no cambia.">
            <input
                autoFocus
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Nombre, SKU o código"
                className="mb-3 h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
            />
            <ul className="flex max-h-[22.5rem] flex-col gap-1.5 overflow-y-auto">
                {results.map((product) => (
                    <li key={product.id}>
                        <button
                            type="button"
                            onClick={() => onPick(product)}
                            className="flex w-full items-center justify-between gap-3 rounded-control border border-[var(--line)] px-3 py-2.5 text-left transition-colors hover:bg-[var(--surface-2)]"
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-[0.84375rem] font-medium">{product.name}</span>
                                <span className="block font-mono text-[0.71875rem] text-[var(--muted)]">
                                    {product.sku} · {number(product.stock)} {UNIT_SHORT[product.unit] ?? ''}
                                </span>
                            </span>
                            <span className="shrink-0 font-mono text-[0.84375rem] tabular">{money(product.price)}</span>
                        </button>
                    </li>
                ))}
                {results.length === 0 && <li className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Ningún artículo coincide.</li>}
            </ul>
        </Modal>
    );
}

/**
 * El catalogo en filas: nombre, existencia y precio. Caben mas articulos en la pantalla que con
 * teclas, y es la misma vista con la que se muestran los resultados en «solo búsqueda».
 */
function CatalogList({
    products,
    services,
    onPick,
    onPickService,
}: {
    products: PosProduct[];
    services: PosService[];
    onPick: (product: PosProduct) => void;
    onPickService: (service: PosService) => void;
}) {
    return (
        <ul className="flex min-h-0 flex-1 flex-col gap-1.5 overflow-y-auto pr-1">
            {services.map((service) => (
                <li key={`s-${service.id}`}>
                    <button
                        type="button"
                        onClick={() => onPickService(service)}
                        className={cn(
                            'flex min-h-[3.25rem] w-full items-center gap-3 rounded-control border border-dashed px-3 py-2 text-left transition-colors hover:bg-[var(--surface-2)]',
                            service.blocked.length > 0 ? 'border-[var(--danger)]' : 'border-[var(--accent)]/60',
                        )}
                    >
                        <WrenchIcon size={18} className="shrink-0 text-[var(--accent)]" />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[0.84375rem] font-medium">{service.name}</span>
                            <span className="block truncate font-mono text-[0.6875rem] text-[var(--muted)]">
                                {service.code} · {service.blocked.length > 0 ? `Revisar: ${service.blocked.join(', ')}` : 'Servicio'}
                            </span>
                        </span>
                        <span className="shrink-0 font-mono text-[0.9375rem] font-semibold text-[var(--accent)] tabular">
                            {service.open_price ? 'Valor abierto' : money(service.total)}
                        </span>
                    </button>
                </li>
            ))}
            {products.map((product) => {
                const out = product.stock <= 0 && !product.allow_negative_stock;

                return (
                    <li key={product.id}>
                        <button
                            type="button"
                            disabled={out}
                            onClick={() => onPick(product)}
                            className={cn(
                                'flex min-h-[3.25rem] w-full items-center gap-3 rounded-control border px-3 py-2 text-left transition-colors',
                                out
                                    ? 'cursor-not-allowed border-[var(--line)] bg-[var(--surface)] opacity-45'
                                    : product.promotion
                                      ? 'border-[var(--warn)] bg-[var(--surface)] hover:bg-[var(--surface-2)]'
                                      : 'border-[var(--line)] bg-[var(--surface)] hover:border-[var(--accent)] hover:bg-[var(--surface-2)]',
                            )}
                        >
                            {product.thumb_url && (
                                <img src={product.thumb_url} alt="" loading="lazy" className="size-9 shrink-0 rounded-chip object-cover" />
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[0.84375rem] font-medium">{product.name}</span>
                                <span className="block truncate font-mono text-[0.6875rem] text-[var(--muted)] tabular">
                                    {product.sku}
                                    {' · '}
                                    <span style={{ color: out ? 'var(--danger)' : product.stock < 5 ? 'var(--warn)' : undefined }}>
                                        {out ? 'Agotado' : `${number(product.stock)} ${UNIT_SHORT[product.unit] ?? ''}`}
                                    </span>
                                    {product.other_stock > 0 && <span className="text-[var(--warn)]"> · +{number(product.other_stock)} en bodega</span>}
                                </span>
                            </span>
                            {product.promotion && (
                                <span
                                    title={product.promotion.hint}
                                    className="shrink-0 rounded-chip bg-[var(--warn)] px-1.5 py-0.5 font-mono text-[0.65625rem] font-bold text-[var(--bg)]"
                                >
                                    {product.promotion.badge}
                                </span>
                            )}
                            <span className="shrink-0 font-mono text-[0.9375rem] font-semibold text-[var(--accent)] tabular">{money(product.price)}</span>
                        </button>
                    </li>
                );
            })}

            {products.length === 0 && services.length === 0 && (
                <li className="grid place-items-center gap-2 py-16 text-center text-[var(--muted)]">
                    <PackageIcon size={32} />
                    <p className="text-[0.84375rem]">Ningún artículo coincide con la búsqueda.</p>
                </li>
            )}
        </ul>
    );
}

function ShortcutButton({ label, hint, onClick }: { label: string; hint: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex h-11 items-center gap-1.5 rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.8125rem] font-medium transition-colors hover:bg-[var(--surface-3)]"
        >
            {label}
            <span className="rounded-chip bg-[var(--surface-3)] px-1 font-mono text-[0.65625rem] text-[var(--muted)]">{hint}</span>
        </button>
    );
}

function CategoryChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'h-10 shrink-0 rounded-control border px-3.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                active
                    ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-ink)]'
                    : 'border-[var(--line)] bg-[var(--surface)] text-[var(--text)] hover:bg-[var(--surface-2)]',
            )}
        >
            {children}
        </button>
    );
}

function CreditStat({ label, value, tone }: { label: string; value: string; tone?: 'accent' | 'warn' | 'danger' }) {
    const colors = { accent: 'var(--accent)', warn: 'var(--warn)', danger: 'var(--danger)' };

    return (
        <div>
            <p className="text-[0.65625rem] uppercase tracking-wide text-[var(--muted)]">{label}</p>
            <p className="font-mono text-[0.8125rem] font-semibold tabular" style={{ color: tone ? colors[tone] : undefined }}>
                {value}
            </p>
        </div>
    );
}

// --- Autorizacion del supervisor -------------------------------------------

/**
 * Teclado de PIN para que un supervisor autorice sin cerrar la sesion del cajero.
 *
 * La autorizacion vale para una sola venta y queda registrada en `access_logs`.
 */
function SupervisorPinModal({
    prompt,
    onClose,
    onApproved,
}: {
    prompt: { action: ApprovalAction; message: string } | null;
    onClose: () => void;
    onApproved: (action: ApprovalAction, by: string) => void;
}) {
    const [pin, setPin] = useState('');
    const [error, setError] = useState('');
    const [sending, setSending] = useState(false);

    useEffect(() => {
        if (prompt) {
            setPin('');
            setError('');
        }
    }, [prompt]);

    if (!prompt) {
        return null;
    }

    const submit = async () => {
        setSending(true);
        setError('');

        const response = await fetch(route('pos.authorization'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ action: prompt.action, pin }),
        });

        const payload = (await response.json().catch(() => ({}))) as { authorized?: boolean; by?: string; message?: string };
        setSending(false);

        if (response.ok && payload.authorized && payload.by) {
            onApproved(prompt.action, payload.by);

            return;
        }

        setPin('');
        setError(payload.message ?? 'No se pudo autorizar.');
    };

    return (
        <Modal
            open
            onClose={onClose}
            title="Autorización del supervisor"
            description={prompt.message}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button disabled={pin.length !== 4} loading={sending} onClick={submit}>
                        Autorizar
                    </Button>
                </>
            }
        >
            <div className="flex flex-col items-center gap-4">
                <div className="flex gap-2">
                    {[0, 1, 2, 3].map((index) => (
                        <span
                            key={index}
                            className={cn(
                                'grid size-12 place-items-center rounded-control border font-mono text-[1.25rem]',
                                pin.length > index
                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent)]'
                                    : 'border-[var(--line)] bg-[var(--surface-2)]',
                            )}
                        >
                            {pin.length > index ? '•' : ''}
                        </span>
                    ))}
                </div>

                <div className="grid w-[13.125rem] grid-cols-3 gap-2">
                    {['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'].map((key, index) =>
                        key === '' ? (
                            <span key={index} />
                        ) : (
                            <button
                                key={index}
                                type="button"
                                onClick={() => setPin((current) => (key === 'del' ? current.slice(0, -1) : current.length < 4 ? current + key : current))}
                                className="h-12 rounded-control border border-[var(--line)] bg-[var(--surface-2)] font-mono text-[1.0625rem] transition-colors hover:bg-[var(--surface-3)]"
                            >
                                {key === 'del' ? '←' : key}
                            </button>
                        ),
                    )}
                </div>

                {error && <p className="text-center text-[0.78125rem] font-medium text-[var(--danger)]">{error}</p>}
            </div>
        </Modal>
    );
}

// --- Venta registrada ------------------------------------------------------

/**
 * Lo que el cajero necesita ver al terminar de cobrar: cuanto devuelve y el tiquete.
 *
 * Se imprime en una pestana aparte para que el punto de venta no pierda el foco del
 * lector de codigo de barras.
 */
function LastSaleModal({ sale, onClose }: { sale: LastSale | null; onClose: () => void }) {
    useEffect(() => {
        if (!sale) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                onClose();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [sale, onClose]);

    if (!sale) {
        return null;
    }

    const print = () => {
        window.open(sale.print_url, '_blank', 'width=420,height=680');
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`Venta ${sale.number} registrada`}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" icon={<PrinterIcon size={16} />} onClick={print}>
                        Imprimir tiquete
                    </Button>
                    <Button size="pos" onClick={onClose}>
                        Nueva venta
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <div className="rounded-card border border-[var(--accent)]/30 bg-[var(--accent-soft)] p-4 text-center">
                    <p className="text-[0.78125rem] uppercase tracking-wide text-[var(--accent)]">Total cobrado</p>
                    <p className="font-mono text-[1.875rem] font-semibold leading-none text-[var(--accent)] tabular">{money(sale.total)}</p>
                </div>

                {sale.exchange && (
                    <div className="rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-3 text-[0.8125rem]">
                        <p>
                            Cambio con la devolución <span className="font-mono font-semibold">{sale.exchange.return_number}</span>.
                        </p>
                        {sale.exchange.leftover > 0 && (
                            <p className="font-semibold text-[var(--warn)]">
                                Le sobraron {money(sale.exchange.leftover)} · {sale.exchange.leftover_label}
                            </p>
                        )}
                        <button
                            type="button"
                            onClick={() => window.open(sale.exchange?.return_print_url, '_blank', 'width=420,height=680')}
                            className="mt-1 text-[0.75rem] font-medium text-[var(--accent)] hover:underline"
                        >
                            Imprimir el comprobante de la devolución
                        </button>
                    </div>
                )}

                {sale.change_amount !== null && sale.change_amount > 0 && (
                    <div className="rounded-card border border-[var(--warn)]/40 bg-[var(--warn-soft)] p-4 text-center">
                        <p className="text-[0.78125rem] uppercase tracking-wide text-[var(--warn)]">Cambio para el cliente</p>
                        <p className="font-mono text-[2.125rem] font-semibold leading-none text-[var(--warn)] tabular">{money(sale.change_amount)}</p>
                        {sale.received_amount !== null && (
                            <p className="mt-1 font-mono text-[0.75rem] text-[var(--muted)] tabular">recibiste {money(sale.received_amount)}</p>
                        )}
                    </div>
                )}

                {sale.payments.length > 1 && (
                    <ul className="flex flex-col gap-1 rounded-card border border-[var(--line)] bg-[var(--surface-2)] p-3">
                        {sale.payments.map((payment) => (
                            <li key={payment.method} className="flex items-baseline justify-between text-[0.8125rem]">
                                <span className="text-[var(--muted)]">
                                    {payment.label}
                                    {payment.reference ? ` · ${payment.reference}` : ''}
                                </span>
                                <span className="font-mono tabular">{money(payment.amount)}</span>
                            </li>
                        ))}
                    </ul>
                )}

                {sale.rounding_total !== 0 && (
                    <p className="text-center text-[0.78125rem] text-[var(--muted)]">
                        Incluye un ajuste al redondeo de{' '}
                        <span className="font-mono tabular">
                            {sale.rounding_total > 0 ? '+' : '−'} {money(Math.abs(sale.rounding_total))}
                        </span>
                        .
                    </p>
                )}

                {sale.due_total > 0 && (
                    <p className="rounded-control bg-[var(--surface-2)] px-3 py-2 text-center text-[0.8125rem] text-[var(--muted)]">
                        Quedaron <span className="font-mono tabular">{money(sale.due_total)}</span> a crédito.
                    </p>
                )}

                <p className="text-center text-[0.75rem] text-[var(--muted)]">Pulsa Enter para seguir vendiendo.</p>
            </div>
        </Modal>
    );
}

// --- Cobro -----------------------------------------------------------------

// --- Cliente ---------------------------------------------------------------

function CustomerModal({
    open,
    onClose,
    onSelect,
    current,
}: {
    open: boolean;
    onClose: () => void;
    onSelect: (customer: PosCustomer | null) => void;
    current: PosCustomer | null;
}) {
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<PosCustomer[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setLoading(true);
        const timer = setTimeout(async () => {
            const response = await fetch(`${route('pos.customers')}?search=${encodeURIComponent(search)}`, {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                const payload = (await response.json()) as { customers: PosCustomer[] };
                setResults(payload.customers);
            }

            setLoading(false);
        }, 250);

        return () => clearTimeout(timer);
    }, [search, open]);

    return (
        <Modal open={open} onClose={onClose} title="Cliente del tiquete" description="Búscalo por nombre, documento o teléfono.">
            <input
                autoFocus
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Nombre o documento"
                className="mb-3 h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
            />

            <button
                type="button"
                onClick={() => onSelect(null)}
                className={cn(
                    'mb-2 flex w-full items-center justify-between rounded-control border px-3 py-2.5 text-left transition-colors',
                    current === null ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:bg-[var(--surface-2)]',
                )}
            >
                <span className="text-[0.84375rem] font-medium">Consumidor final</span>
                <span className="text-[0.75rem] text-[var(--muted)]">Sin datos</span>
            </button>

            <ul className="flex max-h-[23.75rem] flex-col gap-1.5 overflow-y-auto">
                {loading && <li className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Buscando…</li>}
                {!loading && results.length === 0 && <li className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Ningún cliente coincide.</li>}
                {results.map((item) => (
                    <li key={item.id}>
                        <button
                            type="button"
                            onClick={() => onSelect(item)}
                            className={cn(
                                'flex w-full items-center justify-between gap-3 rounded-control border px-3 py-2.5 text-left transition-colors',
                                current?.id === item.id ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line)] hover:bg-[var(--surface-2)]',
                            )}
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-[0.84375rem] font-medium">{item.name}</span>
                                <span className="block truncate font-mono text-[0.71875rem] text-[var(--muted)]">
                                    {item.document_number}
                                    {item.phone ? ` · ${item.phone}` : ''}
                                </span>
                            </span>
                            {item.credit_enabled && (
                                <span className="shrink-0 text-right">
                                    <span className="block text-[0.65625rem] uppercase tracking-wide text-[var(--muted)]">Disponible</span>
                                    <span
                                        className="block font-mono text-[0.8125rem] font-semibold tabular"
                                        style={{ color: item.credit_blocked ? 'var(--danger)' : 'var(--accent)' }}
                                    >
                                        {item.credit_blocked ? 'Bloqueado' : money(item.available_credit)}
                                    </span>
                                </span>
                            )}
                        </button>
                    </li>
                ))}
            </ul>
        </Modal>
    );
}

// --- Descuento -------------------------------------------------------------

function DiscountModal({
    open,
    onClose,
    cart,
    maxPercent,
    approvedBy,
    onRequestApproval,
    onApply,
}: {
    open: boolean;
    onClose: () => void;
    cart: CartLine[];
    maxPercent: number;
    approvedBy?: string;
    onRequestApproval: (message: string) => void;
    onApply: (lines: CartLine[]) => void;
}) {
    const [percent, setPercent] = useState('0');

    useEffect(() => {
        if (open) {
            setPercent('0');
        }
    }, [open]);

    const value = Math.min(Math.max(Number(percent) || 0, 0), 100);
    const overLimit = value > maxPercent;
    const blocked = overLimit && !approvedBy;

    const apply = () => {
        onApply(
            cart.map((line) => ({
                ...line,
                discount: Math.round(line.quantity * line.unit_price * (value / 100)),
                // En un servicio detallado cada artículo se cobra aparte: lleva su parte del descuento.
                components:
                    line.kind === 'service' && line.pricing_mode === 'itemized'
                        ? (line.components ?? []).map((component) => ({ ...component, discount: Math.round(component.quantity * component.unit_price * (value / 100)) }))
                        : line.components,
            })),
        );
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Descuento del tiquete"
            description={`Máximo permitido: ${maxPercent} %`}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    {blocked ? (
                        <Button
                            variant="warn"
                            icon={<LockKeyIcon size={16} />}
                            onClick={() => onRequestApproval(`Un descuento del ${value} % supera el ${maxPercent} % permitido.`)}
                        >
                            Autorizar con PIN
                        </Button>
                    ) : (
                        <Button onClick={apply} icon={<TagIcon size={16} />}>
                            Aplicar {value} %
                        </Button>
                    )}
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <input
                    autoFocus
                    type="number"
                    min={0}
                    max={100}
                    value={percent}
                    onChange={(event) => setPercent(event.target.value)}
                    className={cn(
                        'h-14 rounded-control border bg-[var(--surface-2)] px-3 text-center font-mono text-[1.75rem] tabular focus:outline-none',
                        overLimit ? 'border-[var(--warn)] text-[var(--warn)]' : 'border-[var(--line)] focus:border-[var(--accent)]',
                    )}
                />

                {overLimit && (
                    <p
                        className="rounded-control px-3 py-2 text-[0.78125rem] font-medium"
                        style={{
                            background: approvedBy ? 'var(--accent-soft)' : 'var(--warn-soft)',
                            color: approvedBy ? 'var(--accent)' : 'var(--warn)',
                        }}
                    >
                        {approvedBy
                            ? `Autorizado por ${approvedBy}.`
                            : `Por encima del ${maxPercent} % hace falta el PIN de un supervisor.`}
                    </p>
                )}
                <div className="flex flex-wrap gap-1.5">
                    {[0, 5, 10, 15, 20].map((item) => (
                        <button
                            key={item}
                            type="button"
                            onClick={() => setPercent(String(item))}
                            className="h-10 flex-1 rounded-control border border-[var(--line)] bg-[var(--surface-2)] font-mono text-[0.8125rem] tabular transition-colors hover:bg-[var(--surface-3)]"
                        >
                            {item} %
                        </button>
                    ))}
                </div>
                <p className="text-[0.78125rem] text-[var(--muted)]">El descuento se reparte proporcionalmente entre todas las líneas del tiquete.</p>
            </div>
        </Modal>
    );
}

// --- Suspender / recuperar -------------------------------------------------

function HoldModal({
    open,
    onClose,
    cart,
    customer,
    total,
    onHeld,
}: {
    open: boolean;
    onClose: () => void;
    cart: CartLine[];
    customer: PosCustomer | null;
    total: number;
    onHeld: () => void;
}) {
    const customerId = customer?.id ?? null;
    const [label, setLabel] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setLabel(`Tiquete ${new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}`);
        }
    }, [open]);

    const save = async () => {
        setSaving(true);

        const response = await fetch(route('pos.hold.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ label, total, customer_id: customerId, payload: { items: cart, customer_id: customerId } }),
        });

        setSaving(false);

        if (response.ok) {
            onHeld();
        } else {
            toast.error('No se pudo suspender el tiquete.');
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Suspender el tiquete"
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button onClick={save} loading={saving} disabled={label.trim() === ''}>
                        Suspender
                    </Button>
                </>
            }
        >
            <input
                autoFocus
                type="text"
                value={label}
                onChange={(event) => setLabel(event.target.value)}
                placeholder="Nombre del tiquete"
                className="h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface-2)] px-3 text-[0.875rem] focus:border-[var(--accent)] focus:outline-none"
            />
            <p className="mt-2 text-[0.78125rem] text-[var(--muted)]">
                Se guardan {cart.length} artículos por {money(total)}
                {customer ? (
                    <>
                        {' '}
                        y el cliente <span className="font-medium text-[var(--text)]">{customer.name}</span>
                    </>
                ) : null}
                . Lo puedes retomar con F5.
            </p>
        </Modal>
    );
}

function ResumeModal({ open, onClose, onResume }: { open: boolean; onClose: () => void; onResume: (ticket: HeldTicket) => void }) {
    const [tickets, setTickets] = useState<HeldTicket[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setLoading(true);
        void (async () => {
            const response = await fetch(route('pos.hold.index'), { headers: { Accept: 'application/json' } });

            if (response.ok) {
                const payload = (await response.json()) as { held: HeldTicket[] };
                setTickets(payload.held);
            }

            setLoading(false);
        })();
    }, [open]);

    const remove = async (ticket: HeldTicket) => {
        await fetch(route('pos.hold.destroy', ticket.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        setTickets((current) => current.filter((item) => item.id !== ticket.id));
    };

    return (
        <Modal open={open} onClose={onClose} title="Tiquetes suspendidos" description="Retoma uno para seguir cobrandolo.">
            {loading && <p className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Cargando…</p>}
            {!loading && tickets.length === 0 && <p className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">No hay tiquetes suspendidos.</p>}

            <ul className="flex flex-col gap-1.5">
                {tickets.map((ticket) => (
                    <li key={ticket.id} className="flex items-center gap-2 rounded-control border border-[var(--line)] px-3 py-2.5">
                        <button
                            type="button"
                            onClick={async () => {
                                onResume(ticket);
                                await remove(ticket);
                            }}
                            className="min-w-0 flex-1 text-left"
                        >
                            <span className="block truncate text-[0.84375rem] font-medium">{ticket.label}</span>
                            <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                {ticket.customer_name ?? 'Consumidor final'} · {ticket.payload.items.length} artículos
                            </span>
                        </button>
                        <span className="font-mono text-[0.875rem] font-semibold tabular">{money(ticket.total)}</span>
                        <button
                            type="button"
                            onClick={() => remove(ticket)}
                            aria-label="Descartar"
                            className="text-[var(--muted)] transition-colors hover:text-[var(--danger)]"
                        >
                            <TrashIcon size={15} />
                        </button>
                    </li>
                ))}
            </ul>
        </Modal>
    );
}

// --- Ventas recientes / anulacion ------------------------------------------

function RecentSalesModal({
    open,
    onClose,
    canCancel,
    canRefund,
    onReturn,
}: {
    open: boolean;
    onClose: () => void;
    canCancel: boolean;
    canRefund: boolean;
    onReturn: (saleId: number) => void;
}) {
    const [lookup, setLookup] = useState('');
    const [found, setFound] = useState<RecentSale | null>(null);
    const [lookupError, setLookupError] = useState('');

    /** Una devolución puede ser de cualquier día o de otra caja: se busca por el número del tiquete. */
    const searchSale = async () => {
        const number = lookup.trim();

        if (number === '') {
            return;
        }

        setLookupError('');
        setFound(null);
        const response = await fetch(`${route('pos.sales.lookup')}?numero=${encodeURIComponent(number)}`, { headers: { Accept: 'application/json' } });
        const payload = (await response.json().catch(() => ({}))) as { sale?: RecentSale; message?: string };

        if (response.ok && payload.sale) {
            setFound(payload.sale);
        } else {
            setLookupError(payload.message ?? 'No encontramos esa venta.');
        }
    };
    const [sales, setSales] = useState<RecentSale[]>([]);
    const [loading, setLoading] = useState(false);
    const [target, setTarget] = useState<RecentSale | null>(null);
    const [reason, setReason] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        const response = await fetch(route('pos.sales.recent'), { headers: { Accept: 'application/json' } });

        if (response.ok) {
            const payload = (await response.json()) as { sales: RecentSale[] };
            setSales(payload.sales);
        }

        setLoading(false);
    }, []);

    useEffect(() => {
        if (open) {
            void load();
            setTarget(null);
            setReason('');
            setLookup('');
            setFound(null);
            setLookupError('');
        }
    }, [open, load]);

    const cancelSale = () => {
        if (!target) {
            return;
        }

        router.post(
            route('pos.sales.cancel', target.id),
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTarget(null);
                    setReason('');
                    void load();
                },
            },
        );
    };

    return (
        <Modal open={open} onClose={onClose} title="Ventas del turno" description="Reimprime el tiquete, anula una venta o registra una devolución.">
            {canRefund && (
                <div className="mb-3 rounded-control border border-[var(--line)] bg-[var(--surface-2)] p-2.5">
                    <p className="mb-1.5 text-[0.75rem] text-[var(--muted)]">¿La devolución es de otro día u otra caja? Busca el tiquete.</p>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            void searchSale();
                        }}
                        className="flex gap-2"
                    >
                        <input
                            type="search"
                            value={lookup}
                            onChange={(event) => setLookup(event.target.value)}
                            placeholder="Número del tiquete, ej: C1-000123"
                            aria-label="Número del tiquete"
                            className="h-10 min-w-0 flex-1 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 font-mono text-[0.8125rem] focus:border-[var(--accent)] focus:outline-none"
                        />
                        <Button type="submit" size="sm" variant="secondary">
                            Buscar
                        </Button>
                    </form>
                    {lookupError && <p className="mt-1.5 text-[0.75rem] text-[var(--danger)]">{lookupError}</p>}
                    {found && (
                        <div className="mt-2 flex items-center gap-2 rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 py-2">
                            <span className="min-w-0 flex-1">
                                <span className="block font-mono text-[0.84375rem] font-medium">{found.number}</span>
                                <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                    {time(found.sold_at)} · {found.customer ?? 'Consumidor final'} · {found.status_label}
                                </span>
                            </span>
                            <span className="font-mono text-[0.875rem] font-semibold tabular">{money(found.total)}</span>
                            {found.status !== 'cancelled' && (
                                <Button size="sm" variant="warn" onClick={() => onReturn(found.id)}>
                                    Devolver
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            )}
            {loading && <p className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Cargando…</p>}
            {!loading && sales.length === 0 && <p className="py-4 text-center text-[0.8125rem] text-[var(--muted)]">Todavía no hay ventas en este turno.</p>}

            <ul className="flex max-h-[22.5rem] flex-col gap-1.5 overflow-y-auto">
                {sales.map((sale) => (
                    <li key={sale.id} className="flex items-center gap-2 rounded-control border border-[var(--line)] px-3 py-2.5">
                        <span className="min-w-0 flex-1">
                            <span className="block font-mono text-[0.84375rem] font-medium">{sale.number}</span>
                            <span className="block truncate text-[0.71875rem] text-[var(--muted)]">
                                {time(sale.sold_at)} · {sale.customer ?? 'Consumidor final'} · {sale.status_label}
                            </span>
                        </span>
                        <span className="font-mono text-[0.875rem] font-semibold tabular">{money(sale.total)}</span>
                        <a
                            href={route('pos.sales.receipt', sale.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-chip border border-[var(--line)] px-2 py-1 text-[0.71875rem] text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                        >
                            Tiquete
                        </a>
                        {canRefund && sale.status !== 'cancelled' && sale.total > 0 && (
                            <button
                                type="button"
                                onClick={() => onReturn(sale.id)}
                                className="rounded-chip border border-[var(--line)] px-2 py-1 text-[0.71875rem] text-[var(--muted)] transition-colors hover:border-[var(--warn)] hover:text-[var(--warn)]"
                            >
                                Devolver
                            </button>
                        )}
                        {canCancel && sale.status !== 'cancelled' && (
                            <button
                                type="button"
                                onClick={() => setTarget(sale)}
                                aria-label="Anular"
                                className="rounded-chip border border-[var(--line)] p-1.5 text-[var(--muted)] transition-colors hover:border-[var(--danger)] hover:text-[var(--danger)]"
                            >
                                <ArrowUUpLeftIcon size={15} />
                            </button>
                        )}
                    </li>
                ))}
            </ul>

            {target && (
                <div className="mt-4 rounded-control border border-[var(--danger)]/40 bg-[var(--danger-soft)] p-3">
                    <p className="text-[0.8125rem] font-medium text-[var(--danger)]">Anular la venta {target.number}</p>
                    <p className="mt-0.5 text-[0.78125rem] text-[var(--muted)]">Las existencias vuelven al inventario y queda registrado el motivo.</p>
                    <input
                        autoFocus
                        type="text"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Motivo de la anulación"
                        className="mt-2 h-11 w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 text-[0.84375rem] focus:border-[var(--danger)] focus:outline-none"
                    />
                    <div className="mt-2 flex justify-end gap-2">
                        <Button size="sm" variant="secondary" onClick={() => setTarget(null)}>
                            Cancelar
                        </Button>
                        <Button size="sm" variant="danger" disabled={reason.trim() === ''} onClick={cancelSale}>
                            Anular la venta
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
