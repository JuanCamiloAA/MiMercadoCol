import { CircleNotchIcon, EyeIcon, EyeSlashIcon, UserPlusIcon } from '@phosphor-icons/react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent, type RefObject } from 'react';
import { cn } from '@/lib/cn';
import { closeLogin, useLoginDialog } from './login-dialog';
import { StoreDialog } from './StoreDialog';
import { useStoreShell } from './store-context';

const inputClass =
    'h-12 w-full rounded-[var(--store-radius-sm)] border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[16px] text-[var(--text)] transition-colors placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-soft)]';

/**
 * El formulario de entrada del cliente. Lo usan el diálogo y la página `/tienda/ingresar`, que
 * queda para quien llega sin sesión a una ruta protegida.
 *
 * Con `returnTo` el servidor devuelve al cliente a esa página de la tienda; sin él, a donde iba
 * (o al pago), como siempre.
 */
export function LoginForm({
    returnTo = null,
    initialDocument = '',
    storeName,
    onSuccess,
    onRegister,
    documentRef,
    autoFocus = false,
}: {
    returnTo?: string | null;
    initialDocument?: string;
    storeName?: string | null;
    onSuccess?: () => void;
    onRegister?: () => void;
    documentRef?: RefObject<HTMLInputElement | null>;
    /** Solo en la página: en el diálogo el foco lo pone el diálogo. */
    autoFocus?: boolean;
}) {
    const [visible, setVisible] = useState(false);
    const documentId = useId();
    const passwordId = useId();
    const errorId = useId();
    const { data, setData, post, processing, errors, reset } = useForm({
        document_number: initialDocument,
        password: '',
        return_to: returnTo ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('storefront.login.store'), {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
            onFinish: () => reset('password'),
        });
    };

    const error = errors.document_number ?? errors.password;

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                {error && (
                    <p id={errorId} role="alert" className="rounded-[12px] bg-[var(--danger-soft)] px-3.5 py-2.5 text-[13.5px] font-semibold text-[var(--danger)]">
                        {error}
                    </p>
                )}

                <div>
                    <label htmlFor={documentId} className="mb-1.5 block text-[13px] font-semibold">
                        Número de documento
                    </label>
                    <input
                        ref={documentRef}
                        id={documentId}
                        type="text"
                        inputMode="numeric"
                        autoComplete="username"
                        autoFocus={autoFocus}
                        value={data.document_number}
                        onChange={(event) => setData('document_number', event.target.value)}
                        aria-invalid={errors.document_number ? true : undefined}
                        aria-describedby={errors.document_number ? errorId : undefined}
                        className={cn(inputClass, 'tabular-nums', errors.document_number && 'border-[var(--danger)]')}
                    />
                </div>

                <div>
                    <label htmlFor={passwordId} className="mb-1.5 block text-[13px] font-semibold">
                        Contraseña
                    </label>
                    <div className="relative">
                        <input
                            id={passwordId}
                            type={visible ? 'text' : 'password'}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            aria-invalid={errors.password ? true : undefined}
                            aria-describedby={errors.password ? errorId : undefined}
                            className={cn(inputClass, 'pr-12', errors.password && 'border-[var(--danger)]')}
                        />
                        <button
                            type="button"
                            onClick={() => setVisible((current) => !current)}
                            aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                            aria-pressed={visible}
                            className="absolute inset-y-0 right-0 grid w-12 place-items-center text-[var(--muted)] hover:text-[var(--text)]"
                        >
                            {visible ? <EyeSlashIcon size={20} /> : <EyeIcon size={20} />}
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="mt-1 flex h-12 w-full items-center justify-center gap-2 rounded-[var(--store-radius-sm)] bg-[var(--accent)] text-[15.5px] font-bold text-[var(--accent-ink)] transition-[filter] hover:brightness-110 disabled:opacity-70"
                >
                    {processing && <CircleNotchIcon size={18} className="animate-spin" />}
                    {processing ? 'Entrando…' : 'Entrar'}
                </button>
            </form>

            <div className="my-5 flex items-center gap-3 text-[12.5px] text-[var(--muted)]">
                <span className="h-px flex-1 bg-[var(--line)]" />
                ¿No tienes cuenta?
                <span className="h-px flex-1 bg-[var(--line)]" />
            </div>

            <Link
                href={route('storefront.register')}
                onClick={onRegister}
                className="flex h-12 w-full items-center justify-center gap-2 rounded-[var(--store-radius-sm)] border-2 border-[var(--accent)] text-[15px] font-bold text-[var(--accent)] hover:bg-[var(--accent-soft)]"
            >
                <UserPlusIcon size={19} />
                Crear mi cuenta{storeName ? ` en ${storeName}` : ''}
            </Link>
        </div>
    );
}

/**
 * El ingreso sin salir de la página: ventana en el computador, hoja que sube en el celular.
 * Se monta una vez en `StoreLayout` y lo abre `openLogin()` desde cualquier parte.
 */
export function LoginDialog() {
    const request = useLoginDialog();
    const shell = useStoreShell();
    const page = usePage();
    const documentField = useRef<HTMLInputElement>(null);
    const openedAt = useRef<string | null>(null);
    // En el computador el cursor va al documento; en el celular no, para que el teclado no salte
    // encima de la hoja mientras sube.
    const desktop = typeof window !== 'undefined' && window.matchMedia('(min-width: 48rem)').matches;

    useEffect(() => {
        if (request.open) {
            openedAt.current = page.url;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [request.open]);

    // Si la página cambia por debajo (atrás del navegador), el diálogo no se queda abierto encima
    // de otra. Un error de validación vuelve a la misma dirección: ahí sigue abierto.
    useEffect(
        () =>
            router.on('navigate', (event) => {
                if (openedAt.current !== null && event.detail.page.url !== openedAt.current) {
                    closeLogin();
                }
            }),
        [],
    );

    return (
        <StoreDialog
            open={request.open}
            onClose={closeLogin}
            title="Entra a tu cuenta"
            description={shell?.store?.name ? `Para pedir en la sede ${shell.store.name}. Con tu documento y tu contraseña.` : 'Con tu documento y tu contraseña.'}
            initialFocus={desktop ? documentField : undefined}
        >
            <LoginForm
                returnTo={request.returnTo}
                initialDocument={request.document}
                storeName={shell?.store?.name}
                onSuccess={closeLogin}
                onRegister={closeLogin}
                documentRef={documentField}
            />
        </StoreDialog>
    );
}
