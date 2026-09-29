// Corre las cuentas de `resources/js/lib/color.ts` sobre los casos que recibe y las imprime en
// JSON. La usa `ColorParityTest` para comparar contra `App\Support\Color` en PHP: las dos
// copias de la aritmetica de color tienen que dar exactamente lo mismo.
//
//   node scripts/color-parity.mjs '[{"a":"#0E6E4E","b":"#FFFFFF","t":0.12,"dark":null}]'
import { contrast, ink, mix, palette } from '../resources/js/lib/color.ts';

const cases = JSON.parse(process.argv[2] ?? '[]');

const results = cases.map((item) => ({
    contrast: contrast(item.a, item.b),
    mix: mix(item.a, item.b, item.t),
    ink: ink(item.a),
    palette: palette(item.a, item.dark ?? null),
}));

process.stdout.write(JSON.stringify(results));
