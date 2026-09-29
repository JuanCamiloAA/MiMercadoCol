/**
 * Los sonidos de la caja: uno corto cuando el lector encuentra el articulo y uno grave cuando no.
 *
 * Se sintetizan con Web Audio en vez de cargar archivos: no hay nada que descargar, funcionan
 * sin internet y suenan igual en todos los equipos. Solo suenan si la empresa los encendio en
 * Ajustes > Caja.
 */
let context: AudioContext | null = null;

function audio(): AudioContext | null {
    if (typeof window === 'undefined' || !('AudioContext' in window)) {
        return null;
    }

    context ??= new AudioContext();

    // El navegador arranca el audio suspendido hasta el primer gesto de la persona.
    if (context.state === 'suspended') {
        void context.resume();
    }

    return context;
}

function tone(frequency: number, start: number, duration: number, volume = 0.12): void {
    const ctx = audio();

    if (!ctx) {
        return;
    }

    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    const at = ctx.currentTime + start;

    oscillator.type = 'square';
    oscillator.frequency.setValueAtTime(frequency, at);
    gain.gain.setValueAtTime(volume, at);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);

    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(at);
    oscillator.stop(at + duration + 0.02);
}

/** El pitido del lector: agudo y corto. */
export function playScanSound(): void {
    tone(1318, 0, 0.09);
}

/** No se encontro o no hay existencia: dos tonos graves. */
export function playErrorSound(): void {
    tone(220, 0, 0.14, 0.14);
    tone(175, 0.17, 0.2, 0.14);
}
