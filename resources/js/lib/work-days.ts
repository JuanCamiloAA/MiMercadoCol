/** Los días del turno de un empleado, en orden. La clave se guarda en `work_schedule` y no cambia. */
export const DAYS = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'] as const;

export type WorkDay = (typeof DAYS)[number];

/** Lo que se lee: las claves de `DAYS` se guardan en `work_schedule` y no cambian. */
export const DAY_LABELS: Record<WorkDay, string> = {
    lunes: 'Lunes',
    martes: 'Martes',
    miercoles: 'Miércoles',
    jueves: 'Jueves',
    viernes: 'Viernes',
    sabado: 'Sábado',
    domingo: 'Domingo',
};

/** La etiqueta de una clave guardada; una que no sea de la lista se muestra tal cual. */
export const dayLabel = (day: string): string => DAY_LABELS[day as WorkDay] ?? day;
