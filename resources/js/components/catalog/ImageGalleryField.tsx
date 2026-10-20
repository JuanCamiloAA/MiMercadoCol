import { ImageIcon, StarIcon, TrashIcon, XIcon } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { cn } from '@/lib/cn';

/** Una foto que ya está guardada. */
export interface GalleryImage {
    id: number;
    url: string | null;
    /** La portada: la del listado, la caja y la tarjeta de la tienda. */
    is_cover?: boolean;
    /** Una marca corta sobre la foto (el color al que corresponde, en un artículo con subartículos). */
    badge?: string | null;
}

interface ImageGalleryFieldProps {
    /** Las que ya tiene la ficha. */
    images: GalleryImage[];
    /** Las elegidas que se suben al guardar el formulario. */
    files: File[];
    onAddFiles: (files: File[]) => void;
    onRemoveFile: (index: number) => void;
    /** Cuántas fotos admite cada ficha (Ajustes > Catálogo). */
    limit: number;
    /** Sin esto (al crear) no se pinta el botón de quitar una foto guardada. */
    onRemove?: (image: GalleryImage) => void;
    onMakeCover?: (image: GalleryImage) => void;
    /** Lo que va debajo de cada foto nueva (el valor al que corresponde, en una familia). */
    renderFileExtra?: (index: number) => ReactNode;
    error?: string;
}

/**
 * Las fotos de una ficha: las guardadas, las que se van a subir y el espacio que queda. El tope lo
 * pone la empresa y lo vuelve a medir el servidor; aquí solo se evita elegir de más.
 */
export function ImageGalleryField({ images, files, onAddFiles, onRemoveFile, limit, onRemove, onMakeCover, renderFileExtra, error }: ImageGalleryFieldProps) {
    const [skipped, setSkipped] = useState(0);
    const previews = useMemo(() => files.map((file) => URL.createObjectURL(file)), [files]);

    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const total = images.length + files.length;
    const room = Math.max(0, limit - total);
    // Sin ninguna marcada, la primera es la portada.
    const coverId = images.find((image) => image.is_cover)?.id ?? images[0]?.id ?? null;

    const pick = (list: FileList | null) => {
        const picked = Array.from(list ?? []);
        setSkipped(Math.max(0, picked.length - room));

        if (room > 0 && picked.length > 0) {
            onAddFiles(picked.slice(0, room));
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-[0.78125rem]">
                <span className={cn('font-medium', total > limit ? 'text-[var(--warn)]' : 'text-[var(--muted)]')}>
                    {total} de {limit} {limit === 1 ? 'foto' : 'fotos'}
                </span>
                {images.length > limit && <span className="text-[var(--warn)]">Tiene más fotos que el tope de la empresa: puedes quitar, no agregar.</span>}
            </div>

            <div className="flex flex-wrap gap-2">
                {images.map((image) => {
                    const cover = image.id === coverId;

                    return (
                        <figure key={image.id} className="relative">
                            <img
                                src={image.url ?? undefined}
                                alt=""
                                className={cn('size-24 rounded-control border object-cover', cover ? 'border-[var(--accent)]' : 'border-[var(--line)]')}
                            />
                            {cover && (
                                <span className="absolute bottom-1 left-1 rounded-chip bg-[var(--accent)] px-1.5 text-[0.6875rem] font-medium text-[var(--accent-ink)]">Portada</span>
                            )}
                            {image.badge && <span className="absolute left-1 top-1 rounded-chip bg-black/60 px-1 text-[0.6875rem] text-white">{image.badge}</span>}
                            <div className="absolute right-1 top-1 flex gap-1">
                                {onMakeCover && !cover && (
                                    <button
                                        type="button"
                                        title="Usar de portada"
                                        aria-label="Usar de portada"
                                        onClick={() => onMakeCover(image)}
                                        className="grid size-6 place-items-center rounded-full bg-black/60 text-white transition-colors hover:bg-[var(--accent)]"
                                    >
                                        <StarIcon size={12} />
                                    </button>
                                )}
                                {onRemove && (
                                    <button
                                        type="button"
                                        title="Quitar la foto"
                                        aria-label="Quitar la foto"
                                        onClick={() => onRemove(image)}
                                        className="grid size-6 place-items-center rounded-full bg-black/60 text-white transition-colors hover:bg-[var(--danger)]"
                                    >
                                        <TrashIcon size={12} />
                                    </button>
                                )}
                            </div>
                        </figure>
                    );
                })}

                {files.map((file, index) => (
                    <figure key={`${file.name}-${index}`} className="flex flex-col gap-1">
                        <div className="relative">
                            <img src={previews[index]} alt="" className="size-24 rounded-control border border-dashed border-[var(--accent)] object-cover" />
                            <span className="absolute bottom-1 left-1 rounded-chip bg-black/60 px-1.5 text-[0.6875rem] text-white">
                                {images.length === 0 && index === 0 ? 'Portada · nueva' : 'Nueva'}
                            </span>
                            <button
                                type="button"
                                title="No subirla"
                                aria-label="No subir esta foto"
                                onClick={() => {
                                    setSkipped(0);
                                    onRemoveFile(index);
                                }}
                                className="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-black/60 text-white transition-colors hover:bg-[var(--danger)]"
                            >
                                <XIcon size={12} />
                            </button>
                        </div>
                        {renderFileExtra?.(index)}
                    </figure>
                ))}

                {room > 0 && (
                    <label className="flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-dashed border-[var(--line-2)] text-center text-[var(--muted)] transition-colors hover:border-[var(--accent)] hover:bg-[var(--surface-2)]">
                        <ImageIcon size={22} />
                        <span className="text-[0.6875rem] font-medium leading-tight">{room === 1 ? 'Agregar foto' : `Agregar (${room})`}</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple={room > 1}
                            className="sr-only"
                            onChange={(event) => {
                                pick(event.target.files);
                                event.target.value = '';
                            }}
                        />
                    </label>
                )}
            </div>

            <p className="text-[0.75rem] text-[var(--muted)]">
                JPG, PNG o WebP, hasta 4 MB cada una. {limit > 1 ? 'En la ficha de la tienda se ven todas en carrusel, empezando por la portada.' : 'La empresa monta una foto por ficha; el número se cambia en Ajustes > Catálogo.'}
            </p>
            {skipped > 0 && (
                <p className="text-[0.75rem] font-medium text-[var(--warn)]">
                    {skipped === 1 ? 'Una foto no se agregó' : `${skipped} fotos no se agregaron`}: ya no caben más de {limit}.
                </p>
            )}
            {error && <p className="text-[0.78125rem] font-medium text-[var(--danger)]">{error}</p>}
        </div>
    );
}
