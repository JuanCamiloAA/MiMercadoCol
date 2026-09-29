import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { PageProps } from '@/types';

/** Los mensajes de sesion se muestran una sola vez por visita. */
export function useFlash(): void {
    const { flash } = usePage<PageProps>().props;
    const shown = useRef<string>('');

    useEffect(() => {
        const signature = JSON.stringify(flash ?? {});

        if (signature === shown.current) {
            return;
        }

        shown.current = signature;

        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
        if (flash?.warning) toast.warning(flash.warning);
        if (flash?.info) toast.info(flash.info);
    }, [flash]);
}
