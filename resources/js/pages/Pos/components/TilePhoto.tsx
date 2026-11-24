import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** La foto en la tecla de la caja (Ajustes > Caja): pequena junto al nombre, grande llenando, o grande y entera. */
export type TileStyle = 'compact' | 'photo_fill' | 'photo_fit';

interface TilePhotoProps {
    src: string | null | undefined;
    name: string;
    mode: Exclude<TileStyle, 'compact'>;
    /** El tamano del nombre cuando no hay foto, que sigue al de la tecla. */
    nameClassName?: string;
    /** Para la vista previa de Ajustes, que tiene alto fijo y no puede ser cuadrada. */
    className?: string;
    /** Lo que va encima de la foto: la promocion, la presentacion. */
    children?: ReactNode;
}

/**
 * El cuadro de la foto en una tecla grande. Sin foto, el mismo cuadro lleva el nombre en grande:
 * una tecla de texto al lado de una con foto quedaria estirada y vacia en el medio, y unas
 * iniciales no sirven en una canasta donde «Aceite de girasol» y «Agua sin gas» son AG los dos.
 * Quien lo usa no repite el nombre en el pie cuando `src` es nulo.
 *
 * «Entera» va sobre blanco a proposito: casi todos los empaques se fotografian sobre blanco y en
 * el tema oscuro un marco gris alrededor se ve como un error.
 */
export function TilePhoto({ src, name, mode, nameClassName, className, children }: TilePhotoProps) {
    const fit = mode === 'photo_fit';

    return (
        <span className={cn('relative block aspect-square w-full shrink-0 overflow-hidden', src && fit ? 'bg-white' : 'bg-[var(--surface-2)]', className)}>
            {src ? (
                <img src={src} alt="" loading="lazy" draggable={false} className={cn('size-full', fit ? 'object-contain p-[6%]' : 'object-cover')} />
            ) : (
                <span className="grid size-full place-items-center p-[10%] text-center">
                    {/* El interlineado va al final: `cn` es twMerge y un tamano despues se lo comeria. */}
                    <span className={cn('line-clamp-4 font-display font-semibold break-words text-[var(--text)]', nameClassName, 'leading-tight')}>{name}</span>
                </span>
            )}
            {children}
        </span>
    );
}
