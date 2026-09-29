// Pasa los tamanos escritos en px a rem y las esquinas a los tokens de Ajustes > Apariencia.
//
//   node scripts/codemod-units.mjs          aplica los cambios
//   node scripts/codemod-units.mjs --check  solo cuenta; sale con 1 si queda algo por pasar
//
// Es idempotente: correrlo dos veces no cambia nada la segunda. A escala 100 % los tamanos
// quedan identicos (13px = 0,8125rem); los radios se agrupan en cuatro tokens y se mueven
// hasta ±2 px. Bordes, anillos, contornos y sombras se quedan en px: un borde de 1 px no debe
// engordar con la escala.
//
// La tienda en linea queda por fuera: tiene su propio editor de aspecto.
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, sep } from 'node:path';

const EXCLUDED = [
    join('resources', 'js', 'components', 'storefront'),
    join('resources', 'js', 'pages', 'Storefront'),
    join('resources', 'js', 'layouts', 'StoreLayout.tsx'),
];

// Radios de hoy agrupados en los cuatro tokens.
const radius = (px) => (px <= 8 ? 'chip' : px <= 11 ? 'control' : px <= 16 ? 'card' : 'sheet');
const rem = (px) => `${+(px / 16).toFixed(5)}rem`;

const SIZE =
    /\b((?:[a-z0-9-]+:)*)(text|w|h|size|min-w|max-w|min-h|max-h|gap|gap-x|gap-y|p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|top|left|right|bottom|inset|leading)-\[(\d+(?:\.\d+)?)px\]/g;
const ROUND = /\b((?:[a-z0-9-]+:)*)rounded(-[tblr]{1,2})?-\[(\d+(?:\.\d+)?)px\]/g;

const check = process.argv.includes('--check');

function walk(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (EXCLUDED.some((excluded) => path === excluded || path.startsWith(excluded + sep))) {
            continue;
        }

        if (statSync(path).isDirectory()) {
            walk(path, out);
        } else if (path.endsWith('.tsx') || path.endsWith('.ts')) {
            out.push(path);
        }
    }

    return out;
}

let sizes = 0;
let radii = 0;
let files = 0;

for (const file of walk(join('resources', 'js'))) {
    const before = readFileSync(file, 'utf8');
    const after = before
        .replace(SIZE, (_, variant, util, px) => {
            sizes++;

            return `${variant}${util}-[${rem(+px)}]`;
        })
        .replace(ROUND, (_, variant, side = '', px) => {
            radii++;

            return `${variant}rounded${side}-${radius(+px)}`;
        });

    if (after !== before) {
        files++;

        if (!check) {
            writeFileSync(file, after);
        }
    }
}

console.log(`${check ? 'Por pasar' : 'Pasados'}: ${sizes} tamaños y ${radii} esquinas en ${files} archivos.`);

if (check && sizes + radii > 0) {
    process.exit(1);
}
