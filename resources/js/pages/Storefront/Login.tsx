import { Link } from '@inertiajs/react';
import { LoginForm } from '@/components/storefront/LoginDialog';
import StoreLayout from '@/layouts/StoreLayout';

interface LoginProps {
    store: { id: number; name: string } | null;
}

/**
 * La página de ingreso queda para quien llega sin sesión a una ruta protegida (un enlace viejo,
 * la sesión vencida). Desde la tienda se entra con el diálogo, sin salir de la página.
 */
export default function StorefrontLogin({ store }: LoginProps) {
    return (
        <StoreLayout title="Entrar" current="account">
            <div className="mx-auto max-w-[420px] py-6 md:py-12">
                <h1 className="text-[22px] leading-tight md:text-[26px]">Entra a tu cuenta</h1>
                <p className="mb-5 mt-1 text-[13.5px] text-[var(--muted)]">
                    {store?.name ? `Para pedir en la sede ${store.name}. ` : ''}Con tu documento y tu contraseña.
                </p>

                <LoginForm storeName={store?.name} autoFocus />

                <Link href={route('storefront.home')} className="mt-5 block text-center text-[13px] font-medium text-[var(--accent)]">
                    Seguir viendo la tienda
                </Link>
            </div>
        </StoreLayout>
    );
}
