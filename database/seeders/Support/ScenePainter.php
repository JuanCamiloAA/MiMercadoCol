<?php

namespace Database\Seeders\Support;

use GdImage;

/**
 * Las escenas de las plantillas de tienda, pintadas con GD sobre un lienzo que ya existe.
 *
 * Cada receta es `[motivo, ...parametros]`, tal como viene en `data/storefront_templates.json`:
 *
 *   produce · pantry · clean · farm · beach   [desde, hasta]
 *   studio    [desde, hasta, color, 'dress'|'coat', foco = 120]   (foco: alto de la luz, en % del alto)
 *   rack      [desde, hasta, [colores]]
 *   flatlay   [fondo, [colores]]
 *   fold      [desde, hasta, [colores]]
 *   leafy     [desde, hasta, acento|null]
 *   glow      [desde, hasta, color, 'serum'|'cream'|'perfume'|'lipstick'|'mist']
 *   workshop  [desde, hasta, acento]
 *
 * Son formas, degradés y sombras: nada de fotos de nadie ni de internet, y el texto lo pone el
 * bloque encima. `focus` dice donde va el motivo: `center`, o `right` para dejarle la izquierda
 * al texto en un banner ancho. Se pinta al doble y se reduce, para que los bordes no salgan
 * dentados.
 */
class ScenePainter
{
    public const MOTIFS = ['produce', 'pantry', 'clean', 'farm', 'beach', 'studio', 'rack', 'flatlay', 'fold', 'leafy', 'glow', 'workshop'];

    protected GdImage $img;

    protected int $w;

    protected int $h;

    /** Centro horizontal del motivo, en pixeles. */
    protected float $fx;

    /**
     * Pinta la receta en un lienzo nuevo de `width` x `height`.
     *
     * @param  list<mixed>  $recipe
     */
    public static function render(int $width, int $height, array $recipe, string $focus = 'center'): GdImage
    {
        $scale = $width * $height > 2_200_000 ? 1.5 : 2;
        $big = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
        imagealphablending($big, true);

        (new self($big, $focus))->draw($recipe);

        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $big, 0, 0, 0, 0, $width, $height, imagesx($big), imagesy($big));
        imagedestroy($big);

        return $out;
    }

    public function __construct(GdImage $img, string $focus = 'center')
    {
        $this->img = $img;
        $this->w = imagesx($img);
        $this->h = imagesy($img);
        $this->fx = $focus === 'right' && $this->w / $this->h >= 1.5 ? $this->w * 0.68 : $this->w * 0.5;
    }

    /**
     * @param  list<mixed>  $recipe
     */
    public function draw(array $recipe): void
    {
        $motif = (string) ($recipe[0] ?? 'produce');
        mt_srand(crc32(json_encode($recipe).$this->w.'x'.$this->h));

        match ($motif) {
            'studio' => $this->studio((string) $recipe[1], (string) $recipe[2], (string) ($recipe[3] ?? '#6E7553'), (string) ($recipe[4] ?? 'dress'), (int) ($recipe[5] ?? 120)),
            'rack' => $this->rack((string) $recipe[1], (string) $recipe[2], (array) ($recipe[3] ?? [])),
            'flatlay' => $this->flatlay((string) $recipe[1], (array) ($recipe[2] ?? [])),
            'fold' => $this->fold((string) $recipe[1], (string) $recipe[2], (array) ($recipe[3] ?? [])),
            'leafy' => $this->leafy((string) $recipe[1], (string) $recipe[2], isset($recipe[3]) ? (string) $recipe[3] : null),
            'glow' => $this->glowScene((string) $recipe[1], (string) $recipe[2], (string) ($recipe[3] ?? '#C98B3C'), (string) ($recipe[4] ?? 'perfume')),
            'workshop' => $this->workshop((string) $recipe[1], (string) $recipe[2], (string) ($recipe[3] ?? '#F59E0B')),
            'pantry' => $this->pantry((string) $recipe[1], (string) $recipe[2]),
            'clean' => $this->clean((string) $recipe[1], (string) $recipe[2]),
            'farm' => $this->farm((string) $recipe[1], (string) $recipe[2]),
            'beach' => $this->beach((string) ($recipe[1] ?? '#BFE3F2'), (string) ($recipe[2] ?? '#F3E3C3')),
            default => $this->produce((string) ($recipe[1] ?? '#E8EFE9'), (string) ($recipe[2] ?? '#CFE0D3')),
        };
    }

    /** La descripcion de la imagen (`alt`), segun el motivo. */
    public static function describe(array $recipe): string
    {
        return match ((string) ($recipe[0] ?? '')) {
            'studio' => ($recipe[4] ?? 'dress') === 'coat' ? 'Abrigo en un maniquí sobre fondo de estudio' : 'Vestido en un maniquí sobre fondo de estudio',
            'rack' => 'Perchero con prendas colgadas y una mesa con ropa doblada',
            'flatlay' => 'Prendas dobladas vistas desde arriba',
            'fold' => 'Pilas de ropa doblada sobre una repisa',
            'leafy' => isset($recipe[3]) ? 'Vela en frasco de vidrio entre hojas verdes' : 'Hojas verdes con luz suave',
            'glow' => match ($recipe[4] ?? 'perfume') {
                'serum' => 'Frasco gotero de sérum iluminado sobre fondo oscuro',
                'cream' => 'Tarro de crema iluminado sobre fondo oscuro',
                'lipstick' => 'Labial iluminado sobre fondo oscuro',
                'mist' => 'Bruma facial en atomizador iluminada sobre fondo oscuro',
                default => 'Frasco de perfume iluminado sobre fondo oscuro',
            },
            'workshop' => 'Herramientas colgadas en un tablero de taller y un taladro sobre el banco',
            'pantry' => 'Repisa de despensa con frascos de granos, costales y enlatados',
            'clean' => 'Productos de aseo con burbujas de jabón',
            'farm' => 'Campo cultivado con una caja de verduras recién cosechadas',
            'beach' => 'Playa con sombrilla y mar en calma',
            default => 'Frutas y verduras frescas en una caja de madera',
        };
    }

    // ---------------------------------------------------------------- motivos

    protected function studio(string $from, string $to, string $color, string $kind, int $spot): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $horizon = (int) ($this->h * 0.8);
        $this->vertical(0, 0, $this->w, $horizon, $a, $b);
        $this->vertical(0, $horizon, $this->w, $this->h, $this->shade($b, 0.08), $this->shade($b, -0.14));

        $dark = $this->luminance($a) < 0.35;
        $radius = $this->h * max(60, min(220, $spot)) / 100 * 0.5;
        $this->glow((int) $this->fx, (int) ($this->h * 0.42), (int) $radius, (int) ($radius * 1.1), $dark ? [255, 236, 214] : $this->shade($a, 0.5), $dark ? 46 : 70);

        $height = min($this->h * 0.86, $this->w * 1.5);
        $feet = $this->h * 0.93;
        $this->figure($this->fx, $feet - $height, $height / 100, $this->rgb($color), $kind, $dark);
    }

    /**
     * Un maniqui con vestido o abrigo. `u` es un centesimo del alto de la figura.
     *
     * @param  array{int, int, int}  $color
     */
    protected function figure(float $cx, float $top, float $u, array $color, string $kind, bool $darkRoom): void
    {
        $skin = $darkRoom ? [214, 204, 192] : [236, 229, 220];
        $skinShade = $this->shade($skin, -0.12);
        $y = fn (float $v) => $top + $v * $u;
        $x = fn (float $v) => $cx + $v * $u;

        $this->shadow((int) $cx, (int) $y(98), (int) (44 * $u), (int) (6 * $u), 80);

        // Piernas y zapatos.
        $legs = $kind === 'coat' ? $this->shade($color, -0.6) : $skin;
        $legTop = $kind === 'coat' ? 82 : 78;
        foreach ([-1, 1] as $side) {
            $this->poly([$x($side * 6.5 - 2.2), $y($legTop), $x($side * 6.5 + 2.2), $y($legTop), $x($side * 5.6 + 1.6), $y(96), $x($side * 5.6 - 1.6), $y(96)], $this->color($legs));
            $this->poly([$x($side * 5.6 - 2), $y(95.5), $x($side * 5.6 + 2 + $side * 2.2), $y(96.5), $x($side * 5.6 + 2 + $side * 2.2), $y(98.5), $x($side * 5.6 - 2), $y(98.5)], $this->color($this->shade($color, -0.7)));
        }

        // Brazos (detras del cuerpo en el vestido, mangas en el abrigo).
        $arm = $kind === 'coat' ? $this->shade($color, -0.1) : $skin;
        foreach ([-1, 1] as $side) {
            $this->poly([
                $x($side * 10.5), $y(15.5), $x($side * 13.8), $y(17), $x($side * 17.2), $y(38), $x($side * 17.6), $y(54),
                $x($side * 14.6), $y(54.5), $x($side * 13.6), $y(39), $x($side * 10.5), $y(24),
            ], $this->color($arm));
            $this->ellipse($x($side * 16.1), $y(56.5), 3.6 * $u, 5 * $u, $this->color($kind === 'coat' ? $skin : $skinShade));
        }

        // Cuello y cabeza.
        $this->poly([$x(-2.2), $y(9), $x(2.2), $y(9), $x(2.6), $y(16), $x(-2.6), $y(16)], $this->color($skinShade));
        $this->ellipse($cx, $y(5.6), 9.4 * $u, 11.6 * $u, $this->color($skin));
        $this->ellipse($cx + 1.6 * $u, $y(5.6), 6 * $u, 10 * $u, $this->color($this->shade($skin, -0.06), 70));

        if ($kind === 'coat') {
            $body = [
                $x(-13.5), $y(15), $x(-4.5), $y(13.4), $x(0), $y(17), $x(4.5), $y(13.4), $x(13.5), $y(15),
                $x(12.8), $y(30), $x(15), $y(60), $x(17), $y(84), $x(0), $y(85.5), $x(-17), $y(84), $x(-15), $y(60), $x(-12.8), $y(30),
            ];
            $this->poly($body, $this->color($color));
            // Volumen: el lado derecho en sombra.
            $this->poly([$x(0), $y(17), $x(4.5), $y(13.4), $x(13.5), $y(15), $x(12.8), $y(30), $x(15), $y(60), $x(17), $y(84), $x(0), $y(85.5)], $this->color([0, 0, 0], 112));
            // Camisa y solapas.
            $this->poly([$x(-4.2), $y(14), $x(4.2), $y(14), $x(0), $y(29)], $this->color([240, 236, 230]));
            $lapel = $this->color($this->shade($color, -0.22));
            $this->poly([$x(-4.6), $y(13.6), $x(-0.6), $y(31), $x(-7.6), $y(22.5)], $lapel);
            $this->poly([$x(4.6), $y(13.6), $x(0.6), $y(31), $x(7.6), $y(22.5)], $lapel);
            imagesetthickness($this->img, max(2, (int) (0.35 * $u)));
            imageline($this->img, (int) $x(0), (int) $y(31), (int) $x(-0.8), (int) $y(85), $this->color($this->shade($color, -0.3)));
            foreach ([42, 52, 62] as $button) {
                $this->ellipse($x(2.4), $y($button), 1.5 * $u, 1.5 * $u, $this->color($this->shade($color, -0.45)));
            }
            // Bolsillos.
            imageline($this->img, (int) $x(-11), (int) $y(58), (int) $x(-5), (int) $y(58), $this->color($this->shade($color, -0.25)));
            imageline($this->img, (int) $x(5), (int) $y(58), (int) $x(11), (int) $y(58), $this->color($this->shade($color, -0.35)));

            return;
        }

        $dress = [
            $x(-10.8), $y(15), $x(-5.6), $y(14), $x(-3.6), $y(15.4), $x(0), $y(20), $x(3.6), $y(15.4), $x(5.6), $y(14), $x(10.8), $y(15),
            $x(9.8), $y(24), $x(7.4), $y(34), $x(24), $y(79),
        ];
        // El ruedo, con su curva.
        for ($i = 1; $i < 12; $i++) {
            $t = $i / 12;
            $dress[] = $x(24 - 48 * $t);
            $dress[] = $y(79 + 2.6 * sin(M_PI * $t));
        }
        array_push($dress, $x(-24), $y(79), $x(-7.4), $y(34), $x(-9.8), $y(24));
        $this->poly($dress, $this->color($color));
        $this->poly([$x(0), $y(20), $x(3.6), $y(15.4), $x(5.6), $y(14), $x(10.8), $y(15), $x(9.8), $y(24), $x(7.4), $y(34), $x(24), $y(79), $x(12), $y(81), $x(1), $y(81.6)], $this->color([0, 0, 0], 112));

        // Pliegues de la falda y la cintura.
        imagesetthickness($this->img, max(2, (int) (0.3 * $u)));
        $fold = $this->color($this->shade($color, -0.2));
        foreach ([-4, 0, 4] as $offset) {
            imageline($this->img, (int) $x($offset * 0.8), (int) $y(36), (int) $x($offset * 3.2), (int) $y(79.5), $fold);
        }
        $this->poly([$x(-7.6), $y(33), $x(7.6), $y(33), $x(7.3), $y(35.4), $x(-7.3), $y(35.4)], $this->color($this->shade($color, -0.3)));
    }

    /**
     * @param  list<string>  $colors
     */
    protected function rack(string $from, string $to, array $colors): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $floor = (int) ($this->h * 0.82);
        $this->vertical(0, 0, $this->w, $floor, $this->shade($a, 0.06), $b);
        $this->vertical(0, $floor, $this->w, $this->h, $this->shade($b, -0.16), $this->shade($b, -0.3));
        $this->glow((int) ($this->w * 0.25), (int) ($this->h * 0.1), (int) ($this->w * 0.5), (int) ($this->h * 0.6), [255, 250, 240], 40);

        $colors = $colors !== [] ? array_values($colors) : ['#2B2B2D', '#E4D9C6', '#6E7553'];
        $wide = $this->w / $this->h >= 1.3;
        $metal = $this->color($this->shade($b, -0.6));
        $railY = $this->h * 0.2;
        [$left, $right] = $wide ? [$this->w * 0.1, $this->w * 0.66] : [$this->w * 0.08, $this->w * 0.92];

        if ($wide && $this->fx > $this->w * 0.6) {
            [$left, $right] = [$this->w * 0.36, $this->w * 0.8];
        }

        // Riel y soportes.
        imagesetthickness($this->img, max(3, (int) ($this->h * 0.008)));
        imageline($this->img, (int) $left, (int) $railY, (int) $right, (int) $railY, $metal);
        foreach ([$left, $right] as $post) {
            imageline($this->img, (int) $post, (int) $railY, (int) $post, (int) ($floor + $this->h * 0.06), $metal);
            imageline($this->img, (int) ($post - $this->h * 0.03), (int) ($floor + $this->h * 0.06), (int) ($post + $this->h * 0.03), (int) ($floor + $this->h * 0.06), $metal);
        }

        // Prendas colgadas: camisa, vestido, chaqueta...
        $count = count($colors);
        $spacing = ($right - $left) / max(1, $count);
        $shapes = ['shirt', 'dress', 'shirt', 'dress', 'shirt', 'shirt'];
        $hook = $this->h * 0.05;

        foreach ($colors as $index => $hex) {
            $shape = $shapes[$index % count($shapes)];
            $size = min($spacing * ($wide ? 1.7 : 2.4), $this->h * ($wide ? ($shape === 'dress' ? 0.66 : 0.56) : 0.42));
            $cx = $left + $spacing * ($index + 0.5);
            $topShape = $shape === 'dress' ? 110 : 158;
            $cy = $railY + $hook + (450 - $topShape) * $size / 900;
            $fill = $this->rgb($hex);

            imagesetthickness($this->img, max(2, (int) ($this->h * 0.004)));
            imagearc($this->img, (int) $cx, (int) ($railY + $hook * 0.15), (int) ($hook * 0.5), (int) ($hook * 0.5), 180, 360, $metal);
            imageline($this->img, (int) $cx, (int) ($railY + $hook * 0.4), (int) ($cx - $size * 0.2), (int) ($railY + $hook), $metal);
            imageline($this->img, (int) $cx, (int) ($railY + $hook * 0.4), (int) ($cx + $size * 0.2), (int) ($railY + $hook), $metal);

            foreach (GarmentPainter::placed($shape, (int) ($cx + $size * 0.025), (int) ($cy + $size * 0.02), (int) $size) as $points) {
                $this->poly($points, $this->color([0, 0, 0], 104));
            }
            foreach (GarmentPainter::placed($shape, (int) $cx, (int) $cy, (int) $size) as $points) {
                $this->poly($points, $this->color($fill));
                imagesetthickness($this->img, max(2, (int) ($this->h * 0.003)));
                imagepolygon($this->img, $points, $this->color($this->shade($fill, -0.25)));
            }
        }

        // La mesa con prendas dobladas.
        if ($wide) {
            [$tl, $tr] = $this->fx > $this->w * 0.6 ? [$this->w * 0.03, $this->w * 0.3] : [$this->w * 0.7, $this->w * 0.95];
        } else {
            [$tl, $tr] = [$this->w * 0.14, $this->w * 0.86];
        }

        $tableY = $this->h * ($wide ? 0.62 : 0.68);
        $wood = $this->mix($b, [168, 132, 96], 0.55);
        $this->shadow((int) (($tl + $tr) / 2), (int) ($floor + $this->h * 0.07), (int) (($tr - $tl) * 0.62), (int) ($this->h * 0.04), 70);
        foreach ([$tl + ($tr - $tl) * 0.06, $tr - ($tr - $tl) * 0.06] as $leg) {
            $this->poly([$leg - $this->h * 0.008, $tableY, $leg + $this->h * 0.008, $tableY, $leg + $this->h * 0.008, $floor + $this->h * 0.07, $leg - $this->h * 0.008, $floor + $this->h * 0.07], $this->color($this->shade($wood, -0.3)));
        }
        $this->poly([$tl, $tableY, $tr, $tableY, $tr, $tableY + $this->h * 0.028, $tl, $tableY + $this->h * 0.028], $this->color($wood));
        $this->poly([$tl, $tableY + $this->h * 0.022, $tr, $tableY + $this->h * 0.022, $tr, $tableY + $this->h * 0.028, $tl, $tableY + $this->h * 0.028], $this->color($this->shade($wood, -0.25)));

        $piles = $wide ? 2 : 3;
        $pileW = ($tr - $tl) / $piles * 0.74;
        $layer = $this->h * 0.034;
        for ($p = 0; $p < $piles; $p++) {
            $px = $tl + ($tr - $tl) * ($p + 0.5) / $piles;
            $layers = 3 + ($p % 2);
            for ($l = 0; $l < $layers; $l++) {
                $fill = $this->rgb($colors[($l + $p * 2 + 1) % $count]);
                $y2 = $tableY - $l * $layer;
                $jitter = (mt_rand(-10, 10) / 100) * $pileW * 0.1;
                $this->rrect($px - $pileW / 2 + $jitter, $y2 - $layer, $px + $pileW / 2 + $jitter, $y2, $layer * 0.3, $this->color($fill));
                $this->poly([$px - $pileW / 2 + $jitter, $y2 - $layer * 0.32, $px + $pileW / 2 + $jitter, $y2 - $layer * 0.32, $px + $pileW / 2 + $jitter, $y2 - $layer * 0.22, $px - $pileW / 2 + $jitter, $y2 - $layer * 0.22], $this->color($this->shade($fill, -0.2)));
            }
        }
    }

    /**
     * @param  list<string>  $colors
     */
    protected function flatlay(string $background, array $colors): void
    {
        $bg = $this->rgb($background);
        $this->vertical(0, 0, $this->w, $this->h, $this->shade($bg, 0.04), $this->shade($bg, -0.08));

        // Textura de lino: lineas muy tenues.
        $line = $this->color([255, 255, 255], 121);
        for ($y = 0; $y < $this->h; $y += max(4, (int) ($this->h * 0.006))) {
            imageline($this->img, 0, $y, $this->w, $y, $line);
        }

        $colors = $colors !== [] ? array_values($colors) : ['#F3EFE7', '#5C7FA6'];
        $count = count($colors);
        [$x0, $x1] = $this->fx > $this->w * 0.6 ? [$this->w * 0.4, $this->w * 0.97] : [$this->w * 0.06, $this->w * 0.94];
        [$y0, $y1] = [$this->h * 0.07, $this->h * 0.93];
        [$cols, $rows] = $this->grid($count, ($x1 - $x0) / ($y1 - $y0), 0.82);
        $cellH = ($y1 - $y0) / $rows;
        // Las columnas no se separan mas de lo que piden las prendas: el grupo queda junto.
        $cellW = min(($x1 - $x0) / $cols, $cellH * 0.9);
        $x0 = ($x0 + $x1) / 2 - $cellW * $cols / 2;
        $kinds = ['shirt', 'pants', 'knit', 'shirt', 'knit', 'pants'];

        foreach ($colors as $index => $hex) {
            $col = $index % $cols;
            $row = intdiv($index, $cols);
            $inRow = min($cols, $count - $row * $cols);
            $offset = ($cols - $inRow) * $cellW / 2;
            $cx = $x0 + $offset + $cellW * ($col + 0.5);
            $cy = $y0 + $cellH * ($row + 0.5);
            $size = min($cellW / 0.82, $cellH) * 0.84;
            $this->folded($kinds[$index % count($kinds)], $cx, $cy, $size, $this->rgb($hex), mt_rand(-7, 7));
        }
    }

    /**
     * Una prenda doblada vista desde arriba.
     *
     * @param  array{int, int, int}  $fill
     */
    protected function folded(string $kind, float $cx, float $cy, float $size, array $fill, float $angle): void
    {
        $w = $kind === 'pants' ? $size * 0.5 : $size * 0.7;
        $h = $size * 0.86;
        $box = fn (float $l, float $t, float $r, float $b) => $this->rotate([$cx + $l * $w, $cy + $t * $h, $cx + $r * $w, $cy + $t * $h, $cx + $r * $w, $cy + $b * $h, $cx + $l * $w, $cy + $b * $h], $cx, $cy, $angle);

        $this->poly(array_map(fn ($v, $i) => $v + $size * ($i % 2 === 0 ? 0.025 : 0.035), $box(-0.5, -0.5, 0.5, 0.5), array_keys($box(-0.5, -0.5, 0.5, 0.5))), $this->color([0, 0, 0], 100));
        $this->poly($box(-0.5, -0.5, 0.5, 0.5), $this->color($fill));
        $line = $this->color($this->shade($fill, -0.16));
        imagesetthickness($this->img, max(2, (int) ($size * 0.008)));

        if ($kind === 'pants') {
            $this->poly($box(-0.5, -0.5, 0.5, -0.38), $this->color($this->shade($fill, -0.12)));
            $seam = $box(0, -0.38, 0, 0.5);
            imageline($this->img, (int) $seam[0], (int) $seam[1], (int) $seam[4], (int) $seam[5], $line);
            $pocket = $box(-0.42, -0.36, -0.18, -0.22);
            imageline($this->img, (int) $pocket[0], (int) $pocket[1], (int) $pocket[4], (int) $pocket[5], $line);

            return;
        }

        $fold = $box(-0.5, 0.12, 0.5, 0.12);
        imageline($this->img, (int) $fold[0], (int) $fold[1], (int) $fold[2], (int) $fold[3], $line);

        if ($kind === 'knit') {
            $this->poly($box(-0.5, 0.38, 0.5, 0.5), $this->color($this->shade($fill, -0.1)));
            $neck = $this->rotate([$cx, $cy - 0.5 * $h], $cx, $cy, $angle);
            imagesetthickness($this->img, 1);
            imagefilledarc($this->img, (int) $neck[0], (int) $neck[1], (int) ($w * 0.36), (int) ($w * 0.24), 0, 180, $this->color($this->shade($fill, -0.2)), IMG_ARC_PIE);

            return;
        }

        // Camisa: cuello en V con su sombra y la tapeta.
        $collar = $this->rotate([
            $cx - 0.2 * $w, $cy - 0.5 * $h, $cx + 0.2 * $w, $cy - 0.5 * $h, $cx + 0.24 * $w, $cy - 0.4 * $h, $cx, $cy - 0.28 * $h, $cx - 0.24 * $w, $cy - 0.4 * $h,
        ], $cx, $cy, $angle);
        $this->poly($collar, $this->color($this->shade($fill, 0.12)));
        imagepolygon($this->img, $collar, $line);
        $placket = $box(0, -0.28, 0, 0.5);
        imageline($this->img, (int) $placket[0], (int) $placket[1], (int) $placket[4], (int) $placket[5], $line);
        foreach ([-0.1, 0.1, 0.3] as $button) {
            $point = $this->rotate([$cx, $cy + $button * $h], $cx, $cy, $angle);
            $this->ellipse($point[0] + $size * 0.012, $point[1], $size * 0.018, $size * 0.018, $this->color($this->shade($fill, -0.3)));
        }
    }

    /**
     * @param  list<string>  $colors
     */
    protected function fold(string $from, string $to, array $colors): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $shelf = $this->h * 0.7;
        $this->vertical(0, 0, $this->w, (int) $shelf, $a, $b);
        $this->vertical(0, (int) $shelf, $this->w, $this->h, $this->shade($b, -0.06), $this->shade($b, -0.18));

        // Luz de ventana: dos franjas tenues en diagonal.
        foreach ([0.08, 0.3] as $start) {
            $x = $this->w * $start;
            $this->poly([$x, 0, $x + $this->w * 0.12, 0, $x + $this->w * 0.32, $shelf, $x + $this->w * 0.2, $shelf], $this->color([255, 255, 255], 112));
        }

        $wood = $this->mix($b, [150, 112, 80], 0.5);
        $this->poly([0, $shelf - $this->h * 0.012, $this->w, $shelf - $this->h * 0.012, $this->w, $shelf + $this->h * 0.03, 0, $shelf + $this->h * 0.03], $this->color($wood));
        $this->poly([0, $shelf + $this->h * 0.02, $this->w, $shelf + $this->h * 0.02, $this->w, $shelf + $this->h * 0.03, 0, $shelf + $this->h * 0.03], $this->color($this->shade($wood, -0.3)));

        $colors = $colors !== [] ? array_values($colors) : ['#E4D9C6', '#6E7553'];
        $piles = $this->w / $this->h >= 1.3 ? 3 : 2;
        $span = $this->fx > $this->w * 0.6 ? [$this->w * 0.42, $this->w * 0.95] : [$this->w * 0.12, $this->w * 0.88];
        $step = ($span[1] - $span[0]) / $piles;

        for ($p = 0; $p < $piles; $p++) {
            $px = $span[0] + $step * ($p + 0.5);
            $width = min($step * 0.8, $this->h * 0.42);
            $layers = 5 + ($p % 2) * 2;
            $y = $shelf - $this->h * 0.012;
            $this->shadow((int) $px, (int) $y, (int) ($width * 0.62), (int) ($this->h * 0.02), 70);

            for ($l = 0; $l < $layers; $l++) {
                $fill = $this->rgb($colors[($l + $p * 2) % count($colors)]);
                $thick = $this->h * (0.045 + mt_rand(0, 15) / 1000);
                $jitter = mt_rand(-12, 12) / 1000 * $width;
                $wl = $width * (0.92 + mt_rand(0, 8) / 100);
                $this->rrect($px - $wl / 2 + $jitter, $y - $thick, $px + $wl / 2 + $jitter, $y, $thick * 0.35, $this->color($fill));
                $this->poly([$px - $wl / 2 + $jitter, $y - $thick * 0.32, $px + $wl / 2 + $jitter, $y - $thick * 0.32, $px + $wl / 2 + $jitter, $y - $thick * 0.2, $px - $wl / 2 + $jitter, $y - $thick * 0.2], $this->color($this->shade($fill, -0.16)));
                $this->poly([$px - $wl / 2 + $jitter, $y - $thick, $px + $wl / 2 + $jitter, $y - $thick, $px + $wl / 2 + $jitter, $y - $thick * 0.86, $px - $wl / 2 + $jitter, $y - $thick * 0.86], $this->color([255, 255, 255], 100));
                $y -= $thick;
            }
        }
    }

    protected function leafy(string $from, string $to, ?string $accent): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $this->vertical(0, 0, $this->w, $this->h, $a, $b);
        $this->glow((int) $this->fx, (int) ($this->h * 0.45), (int) ($this->h * 0.6), (int) ($this->h * 0.6), [255, 252, 240], 52);

        $greens = [
            $this->mix($b, [52, 92, 58], 0.7),
            $this->mix($a, [86, 128, 80], 0.6),
            $this->mix($b, [30, 66, 44], 0.75),
            $this->mix($a, [120, 150, 100], 0.5),
        ];
        $size = max($this->w, $this->h);
        // Hojas que entran desde los bordes.
        $leaves = [
            [0.0, 0.05, 30, 0.55], [0.02, 0.35, 5, 0.48], [0.0, 0.85, -25, 0.5], [0.22, 1.02, -70, 0.42],
            [1.0, 0.0, 145, 0.52], [1.02, 0.4, 178, 0.46], [1.0, 0.95, 210, 0.55], [0.75, 1.03, 250, 0.4], [0.45, -0.03, 100, 0.34],
        ];

        foreach ($leaves as $index => [$lx, $ly, $angle, $length]) {
            $this->leaf($this->w * $lx, $this->h * $ly, $size * $length * 0.5, $angle + mt_rand(-8, 8), $greens[$index % count($greens)]);
        }

        if ($accent === null) {
            return;
        }

        // Una vela en frasco sobre una piedra.
        $color = $this->rgb($accent);
        $s = min($this->h * 0.62, $this->w * 0.7);
        $base = $this->h * 0.8;
        $this->shadow((int) $this->fx, (int) $base, (int) ($s * 0.55), (int) ($s * 0.07), 80);
        $this->ellipse($this->fx, $base, $s * 0.9, $s * 0.16, $this->color($this->mix($b, [180, 172, 160], 0.6)));
        $this->ellipse($this->fx, $base - $s * 0.015, $s * 0.86, $s * 0.12, $this->color($this->mix($a, [214, 206, 194], 0.5)));

        $jarW = $s * 0.36;
        $jarH = $s * 0.42;
        $top = $base - $s * 0.04 - $jarH;
        $this->rrect($this->fx - $jarW / 2, $top, $this->fx + $jarW / 2, $base - $s * 0.04, $jarW * 0.12, $this->color($color));
        $this->rrect($this->fx - $jarW / 2 + $jarW * 0.08, $top + $jarH * 0.38, $this->fx + $jarW / 2 - $jarW * 0.08, $top + $jarH * 0.7, $jarW * 0.04, $this->color([246, 240, 228]));
        $this->ellipse($this->fx, $top + $jarH * 0.02, $jarW * 0.96, $jarW * 0.22, $this->color($this->shade($color, 0.45)));
        $this->poly([$this->fx - $jarW * 0.42, $top + $jarH * 0.08, $this->fx - $jarW * 0.3, $top + $jarH * 0.08, $this->fx - $jarW * 0.3, $top + $jarH * 0.92, $this->fx - $jarW * 0.42, $top + $jarH * 0.92], $this->color([255, 255, 255], 96));
        imagesetthickness($this->img, max(2, (int) ($s * 0.008)));
        imageline($this->img, (int) $this->fx, (int) ($top - $jarH * 0.02), (int) $this->fx, (int) ($top - $jarH * 0.1), $this->color([40, 32, 26]));
        $this->glow((int) $this->fx, (int) ($top - $jarH * 0.2), (int) ($s * 0.16), (int) ($s * 0.2), [255, 214, 140], 60);
        $this->ellipse($this->fx, $top - $jarH * 0.17, $s * 0.035, $s * 0.08, $this->color([255, 196, 92]));
        $this->ellipse($this->fx, $top - $jarH * 0.15, $s * 0.016, $s * 0.04, $this->color([255, 246, 214]));

        foreach ([[0.2, 1.0, -60, 0.3], [0.85, 1.02, 235, 0.32]] as $index => [$lx, $ly, $angle, $length]) {
            $this->leaf($this->w * $lx, $this->h * $ly, $size * $length * 0.5, $angle, $greens[($index + 2) % count($greens)]);
        }
    }

    /**
     * Una hoja: base en (`x`, `y`), larga `length`, girada `angle` grados.
     *
     * @param  array{int, int, int}  $green
     */
    protected function leaf(float $x, float $y, float $length, float $angle, array $green): void
    {
        $points = [];
        $width = $length * 0.36;
        for ($i = 0; $i <= 24; $i++) {
            $t = $i / 24;
            $points[] = [$x + $length * $t, $y - $width * 0.5 * pow(sin(M_PI * $t), 0.85)];
        }
        for ($i = 23; $i > 0; $i--) {
            $t = $i / 24;
            $points[] = [$x + $length * $t, $y + $width * 0.5 * pow(sin(M_PI * $t), 0.85)];
        }

        $flat = $this->rotate(array_merge(...$points), $x, $y, $angle);
        $this->poly($flat, $this->color($green));

        // La mitad de abajo un poco mas oscura, y la nervadura.
        $half = [$x + $length, $y];
        foreach (array_slice($points, 25) as $point) {
            array_push($half, ...$point);
        }
        array_push($half, $x, $y);
        $this->poly($this->rotate($half, $x, $y, $angle), $this->color([0, 0, 0], 112));
        $rib = $this->rotate([$x, $y, $x + $length * 0.92, $y], $x, $y, $angle);
        imagesetthickness($this->img, max(2, (int) ($length * 0.01)));
        imageline($this->img, (int) $rib[0], (int) $rib[1], (int) $rib[2], (int) $rib[3], $this->color($this->shade($green, 0.25)));
    }

    protected function glowScene(string $from, string $to, string $color, string $kind): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $this->radial($this->fx, $this->h * 0.46, max($this->w, $this->h) * 0.62, $a, $b);

        $tint = $this->rgb($color);
        $this->glow((int) $this->fx, (int) ($this->h * 0.5), (int) ($this->h * 0.5), (int) ($this->h * 0.5), $this->shade($tint, 0.3), 40);

        $s = min($this->h * 0.7, $this->w * 0.75);
        $top = $this->h * 0.76;
        // Pedestal.
        $pw = $s * 0.62;
        $stone = $this->shade($a, 0.12);
        $this->poly([$this->fx - $pw / 2, $top, $this->fx + $pw / 2, $top, $this->fx + $pw / 2, $this->h, $this->fx - $pw / 2, $this->h], $this->color($stone));
        $this->poly([$this->fx, $top, $this->fx + $pw / 2, $top, $this->fx + $pw / 2, $this->h, $this->fx, $this->h], $this->color([0, 0, 0], 100));
        $this->ellipse($this->fx, $top, $pw, $s * 0.1, $this->color($this->shade($stone, 0.15)));
        $this->ellipse($this->fx, $top, $s * 0.38, $s * 0.05, $this->color($tint, 96));

        $this->beautyProduct($kind, $this->fx, $top, $s, $tint);
    }

    /**
     * El producto de belleza parado en (`cx`, `base`).
     *
     * @param  array{int, int, int}  $tint
     */
    protected function beautyProduct(string $kind, float $cx, float $base, float $s, array $tint): void
    {
        $gold = [201, 168, 106];
        $black = [24, 22, 20];
        $highlight = $this->color([255, 255, 255], 92);

        switch ($kind) {
            case 'serum':
                $bw = $s * 0.2;
                $bh = $s * 0.3;
                $this->rrect($cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base, $bw * 0.2, $this->color($tint));
                $this->rrect($cx - $bw * 0.3, $base - $bh * 0.6, $cx + $bw * 0.3, $base - $bh * 0.22, $bw * 0.04, $this->color([244, 238, 228]));
                $this->rrect($cx - $bw * 0.22, $base - $bh - $s * 0.04, $cx + $bw * 0.22, $base - $bh + $s * 0.01, $bw * 0.04, $this->color($gold));
                $this->rrect($cx - $bw * 0.16, $base - $bh - $s * 0.16, $cx + $bw * 0.16, $base - $bh - $s * 0.035, $bw * 0.16, $this->color($black));
                $this->poly([$cx - $bw * 0.38, $base - $bh * 0.92, $cx - $bw * 0.26, $base - $bh * 0.92, $cx - $bw * 0.26, $base - $bh * 0.1, $cx - $bw * 0.38, $base - $bh * 0.1], $highlight);
                break;
            case 'cream':
                $bw = $s * 0.46;
                $bh = $s * 0.16;
                $this->rrect($cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base, $bh * 0.25, $this->color($this->shade($tint, 0.55)));
                $this->rrect($cx - $bw * 0.52, $base - $bh - $s * 0.08, $cx + $bw * 0.52, $base - $bh + $s * 0.005, $s * 0.015, $this->color($tint));
                $this->poly([$cx - $bw * 0.52, $base - $bh - $s * 0.02, $cx + $bw * 0.52, $base - $bh - $s * 0.02, $cx + $bw * 0.52, $base - $bh, $cx - $bw * 0.52, $base - $bh], $this->color($gold));
                $this->poly([$cx - $bw * 0.44, $base - $bh * 0.9, $cx - $bw * 0.36, $base - $bh * 0.9, $cx - $bw * 0.36, $base - $bh * 0.15, $cx - $bw * 0.44, $base - $bh * 0.15], $highlight);
                break;
            case 'lipstick':
                $bw = $s * 0.13;
                $this->rrect($cx - $bw / 2, $base - $s * 0.2, $cx + $bw / 2, $base, $bw * 0.08, $this->color($black));
                $this->rrect($cx - $bw * 0.42, $base - $s * 0.27, $cx + $bw * 0.42, $base - $s * 0.195, $bw * 0.05, $this->color($gold));
                $this->poly([$cx - $bw * 0.32, $base - $s * 0.27, $cx + $bw * 0.32, $base - $s * 0.27, $cx + $bw * 0.32, $base - $s * 0.36, $cx - $bw * 0.32, $base - $s * 0.41], $this->color($tint));
                $this->poly([$cx - $bw * 0.4, $base - $s * 0.19, $cx - $bw * 0.26, $base - $s * 0.19, $cx - $bw * 0.26, $base - $s * 0.02, $cx - $bw * 0.4, $base - $s * 0.02], $highlight);
                // La tapa, parada al lado.
                $capX = $cx + $s * 0.17;
                $this->rrect($capX - $bw * 0.48, $base - $s * 0.2, $capX + $bw * 0.48, $base, $bw * 0.08, $this->color($black));
                $this->poly([$capX - $bw * 0.48, $base - $s * 0.035, $capX + $bw * 0.48, $base - $s * 0.035, $capX + $bw * 0.48, $base - $s * 0.02, $capX - $bw * 0.48, $base - $s * 0.02], $this->color($gold));
                break;
            case 'mist':
                $bw = $s * 0.17;
                $bh = $s * 0.4;
                $this->rrect($cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base, $bw * 0.3, $this->color($this->shade($tint, 0.15)));
                $this->rrect($cx - $bw * 0.36, $base - $bh * 0.66, $cx + $bw * 0.36, $base - $bh * 0.3, $bw * 0.04, $this->color([240, 236, 228]));
                $this->rrect($cx - $bw * 0.3, $base - $bh - $s * 0.05, $cx + $bw * 0.3, $base - $bh + $s * 0.01, $bw * 0.06, $this->color([196, 196, 200]));
                $this->rrect($cx - $bw * 0.2, $base - $bh - $s * 0.11, $cx + $bw * 0.2, $base - $bh - $s * 0.045, $bw * 0.1, $this->color([236, 236, 238]));
                for ($i = 0; $i < 40; $i++) {
                    $dx = mt_rand(10, 100) / 100 * $s * 0.4;
                    $dy = (mt_rand(-50, 50) / 100) * $dx * 0.5;
                    $this->ellipse($cx + $bw * 0.2 + $dx, $base - $bh - $s * 0.08 + $dy, $s * 0.012, $s * 0.012, $this->color([255, 255, 255], 70 + (int) ($dx / ($s * 0.4) * 50)));
                }
                $this->poly([$cx - $bw * 0.38, $base - $bh * 0.9, $cx - $bw * 0.24, $base - $bh * 0.9, $cx - $bw * 0.24, $base - $bh * 0.1, $cx - $bw * 0.38, $base - $bh * 0.1], $highlight);
                break;
            default:
                // Perfume: frasco de esquinas cortadas, cuello dorado y tapa.
                $bw = $s * 0.36;
                $bh = $s * 0.36;
                $cut = $bw * 0.14;
                $l = $cx - $bw / 2;
                $r = $cx + $bw / 2;
                $t = $base - $bh;
                $this->poly([$l + $cut, $t, $r - $cut, $t, $r, $t + $cut, $r, $base - $cut, $r - $cut, $base, $l + $cut, $base, $l, $base - $cut, $l, $t + $cut], $this->color($this->shade($tint, -0.2)));
                $inset = $bw * 0.08;
                $this->poly([$l + $cut + $inset * 0.4, $t + $inset, $r - $cut - $inset * 0.4, $t + $inset, $r - $inset, $t + $cut + $inset * 0.4, $r - $inset, $base - $cut - $inset * 0.4, $r - $cut - $inset * 0.4, $base - $inset, $l + $cut + $inset * 0.4, $base - $inset, $l + $inset, $base - $cut - $inset * 0.4, $l + $inset, $t + $cut + $inset * 0.4], $this->color($tint));
                $this->rrect($cx - $bw * 0.22, $t + $bh * 0.42, $cx + $bw * 0.22, $t + $bh * 0.62, $bw * 0.02, $this->color([246, 238, 222], 30));
                $this->poly([$cx - $bw * 0.12, $t - $s * 0.035, $cx + $bw * 0.12, $t - $s * 0.035, $cx + $bw * 0.12, $t, $cx - $bw * 0.12, $t], $this->color($gold));
                $this->rrect($cx - $bw * 0.2, $t - $s * 0.16, $cx + $bw * 0.2, $t - $s * 0.03, $bw * 0.03, $this->color($black));
                $this->poly([$cx - $bw * 0.2, $t - $s * 0.16, $cx - $bw * 0.08, $t - $s * 0.16, $cx - $bw * 0.08, $t - $s * 0.03, $cx - $bw * 0.2, $t - $s * 0.03], $this->color([255, 255, 255], 110));
                $this->poly([$l + $inset * 1.4, $t + $cut + $inset, $l + $inset * 2.6, $t + $cut + $inset, $l + $inset * 2.6, $base - $cut - $inset, $l + $inset * 1.4, $base - $cut - $inset], $highlight);
                break;
        }
    }

    protected function workshop(string $from, string $to, string $accent): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $bench = $this->h * 0.74;
        $this->vertical(0, 0, $this->w, (int) $bench, $a, $b);

        // Tablero perforado.
        [$pl, $pr] = [$this->w * 0.04, $this->w * 0.96];
        $board = $this->shade($b, 0.07);
        $this->poly([$pl, $this->h * 0.06, $pr, $this->h * 0.06, $pr, $bench - $this->h * 0.04, $pl, $bench - $this->h * 0.04], $this->color($board));
        $hole = $this->color($this->shade($b, -0.4));
        $gap = max(10, (int) ($this->h * 0.035));
        for ($y = (int) ($this->h * 0.06) + $gap; $y < $bench - $this->h * 0.05; $y += $gap) {
            for ($x = (int) $pl + $gap; $x < $pr; $x += $gap) {
                $this->ellipse($x, $y, $gap * 0.22, $gap * 0.22, $hole);
            }
        }
        $this->glow((int) ($this->w * 0.5), (int) ($this->h * 0.05), (int) ($this->w * 0.5), (int) ($this->h * 0.5), [255, 236, 200], 34);

        $tone = $this->rgb($accent);
        $steel = [168, 172, 178];
        $s = min($this->h, $this->w * 0.9);
        $span = $this->fx > $this->w * 0.6 ? [$this->w * 0.42, $this->w * 0.94] : [$this->w * 0.1, $this->w * 0.9];
        $slot = fn (float $t) => $span[0] + ($span[1] - $span[0]) * $t;

        // Martillo.
        $hx = $slot(0.12);
        $this->rrect($hx - $s * 0.018, $this->h * 0.2, $hx + $s * 0.018, $this->h * 0.56, $s * 0.01, $this->color($tone));
        $this->rrect($hx - $s * 0.09, $this->h * 0.15, $hx + $s * 0.06, $this->h * 0.22, $s * 0.01, $this->color($steel));
        $this->poly([$hx + $s * 0.06, $this->h * 0.15, $hx + $s * 0.1, $this->h * 0.13, $hx + $s * 0.1, $this->h * 0.18, $hx + $s * 0.06, $this->h * 0.2], $this->color($this->shade($steel, -0.2)));

        // Llaves de distintos tamaños.
        foreach ([0.3, 0.38, 0.46] as $index => $t) {
            $wx = $slot($t);
            $len = $this->h * (0.3 - $index * 0.04);
            $width = $s * (0.024 - $index * 0.003);
            $this->rrect($wx - $width / 2, $this->h * 0.18, $wx + $width / 2, $this->h * 0.18 + $len, $width * 0.4, $this->color($steel));
            $this->ellipse($wx, $this->h * 0.18, $width * 3, $width * 3, $this->color($steel));
            $this->ellipse($wx, $this->h * 0.165, $width * 1.3, $width * 1.6, $this->color($board));
            $this->ellipse($wx, $this->h * 0.18 + $len, $width * 2.4, $width * 2.4, $this->color($this->shade($steel, -0.1)));
            $this->ellipse($wx, $this->h * 0.18 + $len, $width * 1.1, $width * 1.1, $this->color($board));
        }

        // Destornilladores.
        foreach ([0.58, 0.64] as $index => $t) {
            $sx = $slot($t);
            $this->rrect($sx - $s * 0.004, $this->h * 0.3, $sx + $s * 0.004, $this->h * 0.52, $s * 0.002, $this->color($steel));
            $this->rrect($sx - $s * 0.016, $this->h * 0.16, $sx + $s * 0.016, $this->h * 0.31, $s * 0.012, $this->color($index === 0 ? $tone : $this->shade($tone, -0.35)));
        }

        // Alicate.
        $px = $slot(0.76);
        $this->poly([$px - $s * 0.01, $this->h * 0.16, $px + $s * 0.01, $this->h * 0.16, $px + $s * 0.012, $this->h * 0.26, $px - $s * 0.012, $this->h * 0.26], $this->color($steel));
        $this->poly([$px - $s * 0.012, $this->h * 0.26, $px - $s * 0.002, $this->h * 0.26, $px - $s * 0.03, $this->h * 0.5, $px - $s * 0.05, $this->h * 0.5], $this->color($tone));
        $this->poly([$px + $s * 0.002, $this->h * 0.26, $px + $s * 0.012, $this->h * 0.26, $px + $s * 0.05, $this->h * 0.5, $px + $s * 0.03, $this->h * 0.5], $this->color($tone));
        $this->ellipse($px, $this->h * 0.26, $s * 0.022, $s * 0.022, $this->color($this->shade($steel, -0.3)));

        // Banco de trabajo.
        $wood = $this->mix($b, [120, 86, 54], 0.6);
        $this->poly([0, $bench, $this->w, $bench, $this->w, $this->h, 0, $this->h], $this->color($this->shade($b, -0.25)));
        $this->poly([0, $bench, $this->w, $bench, $this->w, $bench + $this->h * 0.05, 0, $bench + $this->h * 0.05], $this->color($wood));
        $this->poly([0, $bench + $this->h * 0.04, $this->w, $bench + $this->h * 0.04, $this->w, $bench + $this->h * 0.05, 0, $bench + $this->h * 0.05], $this->color($this->shade($wood, -0.3)));

        // Taladro sobre el banco.
        $dx = $slot(0.88) - $s * 0.08;
        $dy = $bench;
        $u = $s * 0.0042;
        $black = $this->shade($b, -0.55);
        $this->shadow((int) ($dx + 20 * $u), (int) $dy, (int) (70 * $u), (int) (6 * $u), 90);
        $this->rrect($dx - 6 * $u, $dy - 52 * $u, $dx + 54 * $u, $dy - 30 * $u, 8 * $u, $this->color($tone));
        $this->poly([$dx + 14 * $u, $dy - 32 * $u, $dx + 30 * $u, $dy - 32 * $u, $dx + 26 * $u, $dy - 8 * $u, $dx + 10 * $u, $dy - 8 * $u], $this->color($black));
        $this->rrect($dx + 2 * $u, $dy - 10 * $u, $dx + 36 * $u, $dy, 3 * $u, $this->color($tone));
        $this->rrect($dx + 54 * $u, $dy - 47 * $u, $dx + 66 * $u, $dy - 35 * $u, 2 * $u, $this->color($black));
        $this->poly([$dx + 66 * $u, $dy - 42.5 * $u, $dx + 88 * $u, $dy - 41.5 * $u, $dx + 88 * $u, $dy - 40.5 * $u, $dx + 66 * $u, $dy - 39.5 * $u], $this->color($steel));
        $this->poly([$dx - 4 * $u, $dy - 50 * $u, $dx + 50 * $u, $dy - 50 * $u, $dx + 50 * $u, $dy - 46 * $u, $dx - 4 * $u, $dy - 46 * $u], $this->color([255, 255, 255], 100));
    }

    protected function produce(string $from, string $to): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $this->vertical(0, 0, $this->w, $this->h, $a, $b);
        $this->glow((int) ($this->fx), (int) ($this->h * 0.25), (int) ($this->w * 0.4), (int) ($this->h * 0.5), [255, 255, 255], 40);

        $wide = $this->w / $this->h >= 1.3;
        $crateW = $wide ? min($this->w * 0.5, $this->h * 1.25) : $this->w * 0.82;
        $crateH = $crateW * 0.32;
        $crateTop = $this->h * 0.94 - $crateH;
        $cx = $this->fx;

        $this->shadow((int) $cx, (int) ($this->h * 0.94), (int) ($crateW * 0.62), (int) ($crateH * 0.18), 80);
        $this->heap($cx, $crateTop, $crateW * 0.5, $crateH * 1.2, ['#E4572E', '#F2A541', '#7BC043', '#F3D34A', '#C0392B', '#4C6B2F', '#5B2A86', '#F28C28']);

        $wood = [176, 128, 84];
        for ($i = 0; $i < 3; $i++) {
            $y1 = $crateTop + $crateH * ($i / 3) + $crateH * 0.02;
            $y2 = $crateTop + $crateH * (($i + 1) / 3) - $crateH * 0.04;
            $this->poly([$cx - $crateW / 2, $y1, $cx + $crateW / 2, $y1, $cx + $crateW / 2, $y2, $cx - $crateW / 2, $y2], $this->color($this->shade($wood, -0.06 * $i)));
            $this->poly([$cx - $crateW / 2, $y1, $cx + $crateW / 2, $y1, $cx + $crateW / 2, $y1 + $crateH * 0.03, $cx - $crateW / 2, $y1 + $crateH * 0.03], $this->color([255, 255, 255], 104));
        }
        foreach ([-1, 1] as $side) {
            $x = $cx + $side * ($crateW / 2 - $crateW * 0.03);
            $this->poly([$x - $crateW * 0.03, $crateTop - $crateH * 0.06, $x + $crateW * 0.03, $crateTop - $crateH * 0.06, $x + $crateW * 0.03, $crateTop + $crateH, $x - $crateW * 0.03, $crateTop + $crateH], $this->color($this->shade($wood, -0.22)));
        }

        // Unas sueltas al lado de la caja.
        foreach ([[-0.62, '#E4572E'], [-0.52, '#F3D34A'], [0.6, '#7BC043']] as [$dx, $hex]) {
            $this->fruit($cx + $crateW * $dx, $this->h * 0.9, $crateH * 0.42, $this->rgb($hex));
        }
    }

    /**
     * Un monton de frutas sobre la boca de la caja.
     *
     * @param  list<string>  $palette
     */
    protected function heap(float $cx, float $top, float $rx, float $ry, array $palette): void
    {
        $items = [];
        // Hojas verdes al fondo.
        for ($i = 0; $i < 7; $i++) {
            $items[] = ['leaf', $cx + (mt_rand(-90, 90) / 100) * $rx, $top - $ry * (0.35 + mt_rand(0, 40) / 100), $rx * 0.34];
        }
        for ($i = 0; $i < 22; $i++) {
            $angle = mt_rand(0, 180) / 180 * M_PI;
            $dist = sqrt(mt_rand(0, 100) / 100);
            $items[] = ['fruit', $cx + cos($angle) * $rx * 0.95 * $dist, $top - sin($angle) * $ry * 0.7 * $dist + $ry * 0.05, $rx * (0.22 + mt_rand(0, 10) / 100), $palette[$i % count($palette)]];
        }
        usort($items, fn ($p, $q) => $p[2] <=> $q[2]);

        foreach ($items as $item) {
            if ($item[0] === 'leaf') {
                $this->ellipse($item[1], $item[2], $item[3] * 1.4, $item[3], $this->color([86, 150, 64]));
                $this->ellipse($item[1] - $item[3] * 0.2, $item[2] - $item[3] * 0.1, $item[3], $item[3] * 0.7, $this->color([120, 182, 84]));

                continue;
            }

            $this->fruit($item[1], $item[2], $item[3], $this->rgb($item[4]));
        }
    }

    /**
     * @param  array{int, int, int}  $color
     */
    protected function fruit(float $cx, float $cy, float $size, array $color): void
    {
        $this->ellipse($cx + $size * 0.06, $cy + $size * 0.08, $size, $size * 0.96, $this->color([0, 0, 0], 100));
        $this->ellipse($cx, $cy, $size, $size * 0.96, $this->color($color));
        $this->ellipse($cx + $size * 0.12, $cy + $size * 0.12, $size * 0.7, $size * 0.66, $this->color($this->shade($color, -0.18), 60));
        $this->ellipse($cx - $size * 0.18, $cy - $size * 0.2, $size * 0.28, $size * 0.2, $this->color([255, 255, 255], 76));
        $this->ellipse($cx + $size * 0.12, $cy - $size * 0.5, $size * 0.3, $size * 0.13, $this->color([46, 139, 87]));
    }

    protected function pantry(string $from, string $to): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $this->vertical(0, 0, $this->w, $this->h, $a, $b);
        $this->glow((int) $this->fx, (int) ($this->h * 0.3), (int) ($this->w * 0.4), (int) ($this->h * 0.6), [255, 255, 255], 36);

        $wide = $this->w / $this->h >= 1.3;
        $span = $this->fx > $this->w * 0.6 ? [$this->w * 0.4, $this->w * 0.97] : [$this->w * 0.06, $this->w * 0.94];
        $shelves = $wide && $this->w / $this->h > 2 ? [0.9] : [0.5, 0.92];
        $wood = $this->mix($b, [150, 104, 62], 0.55);
        $items = ['jar', 'sack', 'can', 'box', 'jar', 'bottle', 'can', 'sack', 'jar', 'box'];
        $fills = [[241, 234, 216], [122, 74, 42], [217, 130, 43], [233, 196, 106], [192, 57, 43], [46, 134, 171], [244, 211, 94], [231, 111, 81], [42, 157, 143]];
        $index = 0;

        foreach ($shelves as $at) {
            $y = $this->h * $at;
            $height = $this->h * (count($shelves) === 1 ? 0.62 : 0.32);
            $x = $span[0];
            while ($x < $span[1]) {
                $kind = $items[$index % count($items)];
                $width = $height * match ($kind) {
                    'box' => 0.55, 'sack' => 0.62, 'bottle' => 0.3, 'can' => 0.38, default => 0.46
                };
                if ($x + $width > $span[1]) {
                    break;
                }
                $this->pantryItem($kind, $x + $width / 2, $y, $width, $height * (0.8 + mt_rand(0, 20) / 100), $fills[$index % count($fills)]);
                $x += $width * 1.12;
                $index++;
            }
            $this->poly([0, $y, $this->w, $y, $this->w, $y + $this->h * 0.03, 0, $y + $this->h * 0.03], $this->color($wood));
            $this->poly([0, $y + $this->h * 0.022, $this->w, $y + $this->h * 0.022, $this->w, $y + $this->h * 0.03, 0, $y + $this->h * 0.03], $this->color($this->shade($wood, -0.3)));
        }
    }

    /**
     * @param  array{int, int, int}  $fill
     */
    protected function pantryItem(string $kind, float $cx, float $base, float $w, float $h, array $fill): void
    {
        $l = $cx - $w / 2;
        $r = $cx + $w / 2;
        $this->shadow((int) ($cx + $w * 0.08), (int) $base, (int) ($w * 0.6), (int) ($w * 0.08), 70);

        switch ($kind) {
            case 'sack':
                $burlap = [200, 169, 126];
                $this->poly([$l + $w * 0.1, $base - $h * 0.78, $r - $w * 0.1, $base - $h * 0.78, $r, $base - $h * 0.1, $r - $w * 0.06, $base, $l + $w * 0.06, $base, $l, $base - $h * 0.1], $this->color($burlap));
                $this->poly([$l + $w * 0.2, $base - $h * 0.78, $r - $w * 0.2, $base - $h * 0.78, $cx + $w * 0.12, $base - $h * 0.9, $cx - $w * 0.12, $base - $h * 0.9], $this->color($this->shade($burlap, -0.1)));
                $this->poly([$l + $w * 0.1, $base - $h * 0.8, $r - $w * 0.1, $base - $h * 0.8, $r - $w * 0.1, $base - $h * 0.74, $l + $w * 0.1, $base - $h * 0.74], $this->color([120, 84, 48]));
                $this->ellipse($cx, $base - $h * 0.8, $w * 0.76, $h * 0.12, $this->color($fill));
                $this->rrect($l + $w * 0.2, $base - $h * 0.5, $r - $w * 0.2, $base - $h * 0.25, $w * 0.04, $this->color([250, 246, 236]));
                break;
            case 'can':
                $this->rrect($l, $base - $h * 0.62, $r, $base, $w * 0.06, $this->color([190, 192, 196]));
                $this->poly([$l, $base - $h * 0.52, $r, $base - $h * 0.52, $r, $base - $h * 0.1, $l, $base - $h * 0.1], $this->color($fill));
                $this->ellipse($cx, $base - $h * 0.31, $w * 0.5, $h * 0.18, $this->color([255, 255, 255], 30));
                $this->poly([$l + $w * 0.1, $base - $h * 0.6, $l + $w * 0.2, $base - $h * 0.6, $l + $w * 0.2, $base - $h * 0.04, $l + $w * 0.1, $base - $h * 0.04], $this->color([255, 255, 255], 96));
                break;
            case 'box':
                $this->poly([$l, $base - $h * 0.85, $r, $base - $h * 0.85, $r, $base, $l, $base], $this->color($fill));
                $this->poly([$cx, $base - $h * 0.85, $r, $base - $h * 0.85, $r, $base, $cx, $base], $this->color([0, 0, 0], 112));
                $this->rrect($l + $w * 0.14, $base - $h * 0.62, $r - $w * 0.14, $base - $h * 0.3, $w * 0.05, $this->color([250, 246, 236]));
                break;
            case 'bottle':
                $oil = [233, 196, 106];
                $this->rrect($l, $base - $h * 0.72, $r, $base, $w * 0.3, $this->color($oil));
                $this->poly([$cx - $w * 0.2, $base - $h * 0.9, $cx + $w * 0.2, $base - $h * 0.9, $cx + $w * 0.2, $base - $h * 0.7, $cx - $w * 0.2, $base - $h * 0.7], $this->color($this->shade($oil, -0.1)));
                $this->rrect($cx - $w * 0.24, $base - $h, $cx + $w * 0.24, $base - $h * 0.88, $w * 0.05, $this->color([192, 57, 43]));
                $this->rrect($l + $w * 0.1, $base - $h * 0.5, $r - $w * 0.1, $base - $h * 0.25, $w * 0.05, $this->color([250, 246, 236]));
                break;
            default:
                // Frasco de vidrio con granos.
                $this->rrect($l, $base - $h * 0.7, $r, $base, $w * 0.12, $this->color([226, 236, 238], 40));
                $this->rrect($l + $w * 0.05, $base - $h * 0.58, $r - $w * 0.05, $base - $h * 0.03, $w * 0.1, $this->color($fill));
                for ($i = 0; $i < 16; $i++) {
                    $this->ellipse($l + $w * (0.12 + mt_rand(0, 76) / 100), $base - $h * (0.06 + mt_rand(0, 48) / 100), $w * 0.07, $w * 0.05, $this->color($this->shade($fill, -0.18)));
                }
                $this->rrect($l + $w * 0.04, $base - $h * 0.82, $r - $w * 0.04, $base - $h * 0.68, $w * 0.04, $this->color([160, 112, 60]));
                $this->poly([$l + $w * 0.1, $base - $h * 0.66, $l + $w * 0.2, $base - $h * 0.66, $l + $w * 0.2, $base - $h * 0.06, $l + $w * 0.1, $base - $h * 0.06], $this->color([255, 255, 255], 90));
                break;
        }
    }

    protected function clean(string $from, string $to): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $this->vertical(0, 0, $this->w, $this->h, $a, $b);

        // Burbujas.
        for ($i = 0; $i < 26; $i++) {
            $size = min($this->w, $this->h) * (0.03 + mt_rand(0, 90) / 1000);
            $x = mt_rand(0, 100) / 100 * $this->w;
            $y = mt_rand(0, 70) / 100 * $this->h;
            $this->ellipse($x, $y, $size, $size, $this->color([255, 255, 255], 110));
            imagesetthickness($this->img, max(1, (int) ($size * 0.04)));
            imageellipse($this->img, (int) $x, (int) $y, (int) $size, (int) $size, $this->color([255, 255, 255], 70));
            $this->ellipse($x - $size * 0.2, $y - $size * 0.2, $size * 0.2, $size * 0.14, $this->color([255, 255, 255], 40));
        }

        $floor = $this->h * 0.86;
        $this->poly([0, $floor, $this->w, $floor, $this->w, $this->h, 0, $this->h], $this->color($this->shade($b, -0.08)));
        $s = min($this->h * 0.7, $this->w * 0.62);
        $cx = $this->fx;

        // Galón de detergente, atomizador, jabón con dispensador y esponja.
        $this->shadow((int) $cx, (int) $floor, (int) ($s * 0.7), (int) ($s * 0.05), 70);
        $jug = [125, 211, 252];
        $this->rrect($cx - $s * 0.38, $floor - $s * 0.62, $cx - $s * 0.02, $floor, $s * 0.06, $this->color($jug));
        $this->rrect($cx - $s * 0.16, $floor - $s * 0.72, $cx - $s * 0.06, $floor - $s * 0.6, $s * 0.015, $this->color([30, 64, 175]));
        $this->rrect($cx - $s * 0.36, $floor - $s * 0.6, $cx - $s * 0.2, $floor - $s * 0.44, $s * 0.04, $this->color($this->shade($jug, -0.15)));
        $this->rrect($cx - $s * 0.33, $floor - $s * 0.57, $cx - $s * 0.23, $floor - $s * 0.47, $s * 0.03, $this->color($a));
        $this->rrect($cx - $s * 0.34, $floor - $s * 0.36, $cx - $s * 0.06, $floor - $s * 0.12, $s * 0.02, $this->color([255, 255, 255]));
        $this->poly([$cx - $s * 0.34, $floor - $s * 0.26, $cx - $s * 0.06, $floor - $s * 0.3, $cx - $s * 0.06, $floor - $s * 0.22, $cx - $s * 0.34, $floor - $s * 0.18], $this->color([30, 64, 175]));

        $spray = [167, 243, 208];
        $this->rrect($cx + $s * 0.02, $floor - $s * 0.5, $cx + $s * 0.18, $floor, $s * 0.05, $this->color($spray));
        $this->poly([$cx + $s * 0.06, $floor - $s * 0.5, $cx + $s * 0.14, $floor - $s * 0.5, $cx + $s * 0.13, $floor - $s * 0.58, $cx + $s * 0.07, $floor - $s * 0.58], $this->color([240, 240, 240]));
        $this->poly([$cx + $s * 0.03, $floor - $s * 0.58, $cx + $s * 0.16, $floor - $s * 0.58, $cx + $s * 0.16, $floor - $s * 0.66, $cx - $s * 0.02, $floor - $s * 0.66, $cx - $s * 0.04, $floor - $s * 0.62], $this->color([16, 120, 90]));
        $this->poly([$cx + $s * 0.08, $floor - $s * 0.58, $cx + $s * 0.11, $floor - $s * 0.58, $cx + $s * 0.09, $floor - $s * 0.52], $this->color([16, 120, 90]));
        $this->rrect($cx + $s * 0.04, $floor - $s * 0.32, $cx + $s * 0.16, $floor - $s * 0.12, $s * 0.015, $this->color([255, 255, 255]));

        $soap = [249, 168, 212];
        $this->rrect($cx + $s * 0.22, $floor - $s * 0.32, $cx + $s * 0.36, $floor, $s * 0.05, $this->color($soap));
        $this->rrect($cx + $s * 0.27, $floor - $s * 0.4, $cx + $s * 0.31, $floor - $s * 0.32, $s * 0.01, $this->color([240, 240, 240]));
        $this->rrect($cx + $s * 0.24, $floor - $s * 0.43, $cx + $s * 0.34, $floor - $s * 0.39, $s * 0.01, $this->color([240, 240, 240]));
        $this->rrect($cx + $s * 0.24, $floor - $s * 0.2, $cx + $s * 0.34, $floor - $s * 0.08, $s * 0.015, $this->color([255, 255, 255]));

        $this->rrect($cx + $s * 0.38, $floor - $s * 0.12, $cx + $s * 0.56, $floor, $s * 0.02, $this->color([253, 224, 71]));
        $this->rrect($cx + $s * 0.38, $floor - $s * 0.15, $cx + $s * 0.56, $floor - $s * 0.1, $s * 0.015, $this->color([34, 197, 94]));
    }

    protected function farm(string $from, string $to): void
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $horizon = $this->h * 0.55;
        $this->vertical(0, 0, $this->w, (int) $horizon, $this->shade($a, 0.1), $a);
        $this->glow((int) ($this->w * 0.82), (int) ($this->h * 0.16), (int) ($this->h * 0.32), (int) ($this->h * 0.32), [255, 236, 170], 60);
        $this->ellipse($this->w * 0.82, $this->h * 0.16, $this->h * 0.1, $this->h * 0.1, $this->color([252, 220, 120]));

        $back = $this->shade($b, -0.08);
        $mid = $this->shade($this->mix($b, [80, 128, 60], 0.35), -0.06);
        $front = $this->shade($this->mix($b, [70, 110, 50], 0.55), -0.1);
        $this->ellipse($this->w * 0.25, $this->h * 0.66, $this->w * 1.2, $this->h * 0.4, $this->color($back));
        $this->ellipse($this->w * 0.85, $this->h * 0.7, $this->w * 1.1, $this->h * 0.38, $this->color($mid));
        $fieldTop = $this->h * 0.72;
        $this->poly([0, $fieldTop, $this->w, $fieldTop, $this->w, $this->h, 0, $this->h], $this->color($front));

        // Surcos en perspectiva.
        $vanish = [$this->w * 0.5, $this->h * 0.58];
        imagesetthickness($this->img, max(2, (int) ($this->h * 0.006)));
        $furrow = $this->color($this->shade($front, -0.22));
        for ($i = -12; $i <= 12; $i++) {
            $x = $this->w * 0.5 + $i * $this->w * 0.09;
            imageline($this->img, (int) $x, (int) $this->h, (int) ($vanish[0] + ($x - $vanish[0]) * 0.18), (int) $fieldTop, $furrow);
        }

        // Caja de verduras en primer plano.
        $crateW = min($this->w * 0.5, $this->h * 0.9);
        $crateH = $crateW * 0.28;
        $cx = $this->fx;
        $top = $this->h * 0.96 - $crateH;
        $this->shadow((int) $cx, (int) ($this->h * 0.96), (int) ($crateW * 0.6), (int) ($crateH * 0.16), 80);

        $items = [];
        for ($i = 0; $i < 18; $i++) {
            $items[] = [$cx + (mt_rand(-46, 46) / 100) * $crateW, $top - mt_rand(0, 40) / 100 * $crateH, $i % 4];
        }
        usort($items, fn ($p, $q) => $p[1] <=> $q[1]);
        foreach ($items as [$x, $y, $kind]) {
            $size = $crateH * 0.55;
            match ($kind) {
                0 => $this->carrot($x, $y, $size),
                1 => $this->fruit($x, $y, $size * 0.8, [214, 64, 48]),
                2 => $this->lettuce($x, $y, $size),
                default => $this->fruit($x, $y, $size * 0.85, [122, 36, 76]),
            };
        }

        $wood = [170, 122, 78];
        $this->poly([$cx - $crateW / 2, $top, $cx + $crateW / 2, $top, $cx + $crateW / 2, $top + $crateH, $cx - $crateW / 2, $top + $crateH], $this->color($wood));
        $this->poly([$cx - $crateW / 2, $top + $crateH * 0.46, $cx + $crateW / 2, $top + $crateH * 0.46, $cx + $crateW / 2, $top + $crateH * 0.54, $cx - $crateW / 2, $top + $crateH * 0.54], $this->color($this->shade($wood, -0.25)));
        $this->poly([$cx - $crateW / 2, $top, $cx + $crateW / 2, $top, $cx + $crateW / 2, $top + $crateH * 0.06, $cx - $crateW / 2, $top + $crateH * 0.06], $this->color([255, 255, 255], 100));
    }

    protected function carrot(float $x, float $y, float $size): void
    {
        $this->poly([$x - $size * 0.18, $y - $size * 0.3, $x + $size * 0.18, $y - $size * 0.3, $x + $size * 0.02, $y + $size * 0.6, $x - $size * 0.02, $y + $size * 0.6], $this->color([240, 128, 40]));
        foreach ([-0.12, 0, 0.12] as $dx) {
            $this->ellipse($x + $dx * $size, $y - $size * 0.45, $size * 0.1, $size * 0.32, $this->color([70, 140, 60]));
        }
    }

    protected function lettuce(float $x, float $y, float $size): void
    {
        $this->ellipse($x, $y, $size * 1.1, $size * 0.9, $this->color([86, 150, 64]));
        $this->ellipse($x - $size * 0.15, $y - $size * 0.1, $size * 0.7, $size * 0.6, $this->color([132, 190, 90]));
        $this->ellipse($x + $size * 0.15, $y - $size * 0.05, $size * 0.5, $size * 0.45, $this->color([160, 208, 110]));
    }

    protected function beach(string $from, string $to): void
    {
        $sky = $this->rgb($from);
        $sand = $this->rgb($to);
        $sea = $this->h * 0.5;
        $shore = $this->h * 0.66;
        $this->vertical(0, 0, $this->w, (int) $sea, $this->shade($sky, 0.1), $sky);
        $this->glow((int) ($this->w * 0.2), (int) ($this->h * 0.18), (int) ($this->h * 0.3), (int) ($this->h * 0.3), [255, 240, 190], 60);
        $this->vertical(0, (int) $sea, $this->w, (int) $shore, [58, 150, 190], [110, 196, 214]);
        $this->vertical(0, (int) $shore, $this->w, $this->h, $sand, $this->shade($sand, -0.1));
        imagesetthickness($this->img, max(2, (int) ($this->h * 0.006)));
        imageline($this->img, 0, (int) $shore, $this->w, (int) $shore, $this->color([255, 255, 255], 40));

        // Sombrilla y toalla.
        $x = $this->fx;
        $r = min($this->h * 0.3, $this->w * 0.3);
        $base = $this->h * 0.86;
        imagesetthickness($this->img, max(3, (int) ($r * 0.03)));
        imageline($this->img, (int) $x, (int) ($base - $r * 1.5), (int) ($x + $r * 0.08), (int) $base, $this->color([90, 70, 50]));
        $stripes = [[232, 93, 4], [255, 255, 255], [232, 93, 4], [255, 255, 255], [232, 93, 4], [255, 255, 255]];
        imagesetthickness($this->img, 1);
        foreach ($stripes as $i => $stripe) {
            imagefilledarc($this->img, (int) $x, (int) ($base - $r * 1.45), (int) ($r * 2.2), (int) ($r * 1.2), 180 + $i * 30, 210 + $i * 30, $this->color($stripe), IMG_ARC_PIE);
        }
        $this->shadow((int) ($x + $r * 0.3), (int) $base, (int) ($r * 1.1), (int) ($r * 0.12), 70);
        $this->rotateRect($x + $r * 0.5, $base - $r * 0.05, $r * 0.9, $r * 0.36, -8, [42, 157, 143]);
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * Columnas y filas para `count` prendas en un area de esta proporcion.
     *
     * @return array{int, int}
     */
    protected function grid(int $count, float $ratio, float $itemRatio): array
    {
        $best = [1, $count];
        $score = INF;
        for ($cols = 1; $cols <= $count; $cols++) {
            $rows = (int) ceil($count / $cols);
            $cell = ($ratio / $cols) / (1 / $rows);
            $value = abs(log($cell / $itemRatio)) + 0.3 * ($cols * $rows - $count);
            if ($value < $score) {
                [$best, $score] = [[$cols, $rows], $value];
            }
        }

        return $best;
    }

    /** @param  array{int, int, int}  $fill */
    protected function rotateRect(float $cx, float $cy, float $w, float $h, float $angle, array $fill): void
    {
        $this->poly($this->rotate([$cx - $w / 2, $cy - $h / 2, $cx + $w / 2, $cy - $h / 2, $cx + $w / 2, $cy + $h / 2, $cx - $w / 2, $cy + $h / 2], $cx, $cy, $angle), $this->color($fill));
    }

    /**
     * @param  list<float|int>  $points
     * @return list<float>
     */
    protected function rotate(array $points, float $cx, float $cy, float $degrees): array
    {
        $rad = deg2rad($degrees);
        [$cos, $sin] = [cos($rad), sin($rad)];
        $out = [];
        for ($i = 0; $i + 1 < count($points); $i += 2) {
            $dx = $points[$i] - $cx;
            $dy = $points[$i + 1] - $cy;
            $out[] = $cx + $dx * $cos - $dy * $sin;
            $out[] = $cy + $dx * $sin + $dy * $cos;
        }

        return $out;
    }

    /** @return array{int, int, int} */
    protected function rgb(string $hex): array
    {
        $values = sscanf($hex, '#%02x%02x%02x');

        return [(int) ($values[0] ?? 128), (int) ($values[1] ?? 128), (int) ($values[2] ?? 128)];
    }

    /** @param  array{int, int, int}  $rgb */
    protected function color(array $rgb, int $alpha = 0): int
    {
        return imagecolorallocatealpha($this->img, max(0, min(255, (int) $rgb[0])), max(0, min(255, (int) $rgb[1])), max(0, min(255, (int) $rgb[2])), max(0, min(127, $alpha)));
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     * @return array{int, int, int}
     */
    protected function mix(array $a, array $b, float $t): array
    {
        return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)];
    }

    /**
     * Aclara (positivo) u oscurece (negativo) hacia blanco o negro.
     *
     * @param  array{int, int, int}  $rgb
     * @return array{int, int, int}
     */
    protected function shade(array $rgb, float $amount): array
    {
        return $amount >= 0 ? $this->mix($rgb, [255, 255, 255], $amount) : $this->mix($rgb, [0, 0, 0], -$amount);
    }

    /** @param  array{int, int, int}  $rgb */
    protected function luminance(array $rgb): float
    {
        return (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;
    }

    /**
     * Degradé de arriba (`from`) hacia abajo (`to`), linea por linea.
     *
     * @param  array{int, int, int}  $from
     * @param  array{int, int, int}  $to
     */
    protected function vertical(int $x1, int $y1, int $x2, int $y2, array $from, array $to): void
    {
        $span = max(1, $y2 - $y1);
        for ($y = $y1; $y <= $y2; $y++) {
            imageline($this->img, $x1, $y, $x2, $y, $this->color($this->mix($from, $to, ($y - $y1) / $span)));
        }
    }

    /**
     * Un halo suave que se desvanece hacia el borde. `strength` es la opacidad del centro (0-127).
     *
     * Se calcula pixel a pixel en una capa chica y se agranda con interpolacion: elipses una
     * dentro de otra dejaban anillos a la vista.
     *
     * @param  array{int, int, int}  $rgb
     */
    protected function glow(int $cx, int $cy, int $rx, int $ry, array $rgb, int $strength = 60): void
    {
        $rx = max(2, $rx);
        $ry = max(2, $ry);
        $step = max(1, (int) ceil(max($rx, $ry) * 2 / 180));
        $lw = max(2, (int) ceil($rx * 2 / $step));
        $lh = max(2, (int) ceil($ry * 2 / $step));
        $layer = imagecreatetruecolor($lw, $lh);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        $max = max(0, min(127, $strength));

        for ($y = 0; $y < $lh; $y++) {
            $dy = ($y + 0.5) / $lh * 2 - 1;
            for ($x = 0; $x < $lw; $x++) {
                $dx = ($x + 0.5) / $lw * 2 - 1;
                $d = $dx * $dx + $dy * $dy;
                $opacity = $d >= 1 ? 0 : $max * (1 - $d) * (1 - $d);
                imagesetpixel($layer, $x, $y, imagecolorallocatealpha($layer, $rgb[0], $rgb[1], $rgb[2], 127 - (int) round($opacity)));
            }
        }

        $this->stamp($layer, $cx - $rx, $cy - $ry, $rx * 2, $ry * 2);
    }

    protected function shadow(int $cx, int $cy, int $rx, int $ry, int $strength = 70): void
    {
        $this->glow($cx, $cy, $rx, $ry, [0, 0, 0], $strength);
    }

    /**
     * Degradé radial en todo el lienzo: `inner` en (`cx`, `cy`) hasta `outer` a `radius`.
     *
     * @param  array{int, int, int}  $inner
     * @param  array{int, int, int}  $outer
     */
    protected function radial(float $cx, float $cy, float $radius, array $inner, array $outer, float $stretch = 1.3): void
    {
        $step = max(1, (int) ceil(max($this->w, $this->h) / 240));
        $lw = max(2, (int) ceil($this->w / $step));
        $lh = max(2, (int) ceil($this->h / $step));
        $layer = imagecreatetruecolor($lw, $lh);

        for ($y = 0; $y < $lh; $y++) {
            for ($x = 0; $x < $lw; $x++) {
                $dx = (($x + 0.5) * $step - $cx) / $stretch;
                $dy = ($y + 0.5) * $step - $cy;
                $t = min(1, sqrt($dx * $dx + $dy * $dy) / $radius);
                [$r, $g, $b] = $this->mix($inner, $outer, $t * $t * (3 - 2 * $t));
                imagesetpixel($layer, $x, $y, imagecolorallocate($layer, $r, $g, $b));
            }
        }

        $this->stamp($layer, 0, 0, $this->w, $this->h);
    }

    /** Agranda una capa chica con interpolacion y la pega encima, respetando su transparencia. */
    protected function stamp(GdImage $layer, int $x, int $y, int $width, int $height): void
    {
        $scaled = imagescale($layer, max(1, $width), max(1, $height), IMG_BILINEAR_FIXED);
        imagedestroy($layer);

        if ($scaled === false) {
            return;
        }

        imagealphablending($this->img, true);
        imagecopy($this->img, $scaled, $x, $y, 0, 0, imagesx($scaled), imagesy($scaled));
        imagedestroy($scaled);
    }

    protected function ellipse(float $cx, float $cy, float $w, float $h, int $color): void
    {
        imagefilledellipse($this->img, (int) round($cx), (int) round($cy), max(1, (int) round($w)), max(1, (int) round($h)), $color);
    }

    /**
     * Polígono relleno. El grosor vuelve a 1 antes: GD rellena con lineas, y con un grosor
     * mayor cada pixel se pinta varias veces y un color translucido sale casi opaco.
     *
     * @param  list<float|int>  $points
     */
    protected function poly(array $points, int $color): void
    {
        imagesetthickness($this->img, 1);
        imagefilledpolygon($this->img, array_map(fn ($value) => (int) round($value), $points), $color);
    }

    protected function rrect(float $x1, float $y1, float $x2, float $y2, float $radius, int $color): void
    {
        imagesetthickness($this->img, 1);
        $r = max(0, min($radius, ($x2 - $x1) / 2, ($y2 - $y1) / 2));
        [$x1, $y1, $x2, $y2] = array_map(fn ($value) => (int) round($value), [$x1, $y1, $x2, $y2]);
        $r = (int) round($r);

        if ($r < 1) {
            imagefilledrectangle($this->img, $x1, $y1, $x2, $y2, $color);

            return;
        }

        // Con color translucido, rectangulos que no se pisen: si se pisaran, se oscurece la union.
        imagefilledrectangle($this->img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($this->img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $color);
        imagefilledrectangle($this->img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledarc($this->img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($this->img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($this->img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color, IMG_ARC_PIE);
        imagefilledarc($this->img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color, IMG_ARC_PIE);
    }
}
