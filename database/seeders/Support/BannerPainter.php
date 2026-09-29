<?php

namespace Database\Seeders\Support;

use App\Models\MediaAsset;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;

/**
 * Pinta banners de ejemplo con GD para las semillas de la tienda.
 *
 * Son fondos: degradé, formas suaves y unas «frutas» de colores. El texto no va en la imagen:
 * lo pone el bloque encima, que es como se ve bien en celular y en computador. Asi las semillas
 * no dependen de internet ni de fotos con derechos de nadie.
 */
class BannerPainter
{
    public function __construct(protected ImageService $images) {}

    /**
     * Pinta, guarda con sus variantes y registra el medio (de plataforma o de una empresa).
     *
     * @param  array{0: string, 1: string}  $gradient  Dos colores #RRGGBB.
     * @param  list<string>  $fruits  Colores de las formas redondas.
     */
    public function paint(string $alt, int $width, int $height, array $gradient, array $fruits, ?int $companyId, string $folder = 'storefront-platform'): ?MediaAsset
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);

        [$from, $to] = array_map(fn (string $hex) => sscanf($hex, '#%02x%02x%02x'), $gradient);

        // Degradé en diagonal, linea por linea.
        for ($x = 0; $x < $width; $x++) {
            $t = $x / max(1, $width - 1);
            $color = imagecolorallocate(
                $canvas,
                (int) ($from[0] + ($to[0] - $from[0]) * $t),
                (int) ($from[1] + ($to[1] - $from[1]) * $t),
                (int) ($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($canvas, $x, 0, $x, $height, $color);
        }

        // Circulos grandes y translucidos de fondo.
        $glow = imagecolorallocatealpha($canvas, 255, 255, 255, 110);
        imagefilledellipse($canvas, (int) ($width * 0.82), (int) ($height * 0.2), (int) ($height * 1.2), (int) ($height * 1.2), $glow);
        imagefilledellipse($canvas, (int) ($width * 0.62), (int) ($height * 1.05), (int) ($height * 0.9), (int) ($height * 0.9), $glow);

        // Las «frutas»: redondas, con su brillo y su hojita, a la derecha para dejar sitio al texto.
        mt_srand(crc32($alt));
        $count = count($fruits);
        $base = min($width, $height);

        foreach ($fruits as $index => $hex) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            $size = (int) ($base * (0.22 + (mt_rand(0, 12) / 100)));
            $cx = (int) ($width * (0.55 + 0.4 * (($index + 0.5) / $count))) + mt_rand(-20, 20);
            $cy = (int) ($height * (0.35 + 0.35 * ($index % 2))) + mt_rand(-20, 20);

            $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 100);
            imagefilledellipse($canvas, $cx + (int) ($size * 0.06), $cy + (int) ($size * 0.08), $size, $size, $shadow);
            imagefilledellipse($canvas, $cx, $cy, $size, $size, imagecolorallocate($canvas, $r, $g, $b));

            $shine = imagecolorallocatealpha($canvas, 255, 255, 255, 80);
            imagefilledellipse($canvas, $cx - (int) ($size * 0.18), $cy - (int) ($size * 0.2), (int) ($size * 0.28), (int) ($size * 0.2), $shine);

            $leaf = imagecolorallocate($canvas, 46, 139, 87);
            imagefilledellipse($canvas, $cx + (int) ($size * 0.12), $cy - (int) ($size * 0.5), (int) ($size * 0.3), (int) ($size * 0.14), $leaf);
        }

        $path = tempnam(sys_get_temp_dir(), 'banner').'.jpg';
        imagejpeg($canvas, $path, 86);
        imagedestroy($canvas);

        $file = new UploadedFile($path, 'banner.jpg', 'image/jpeg', null, true);
        $stored = $this->images->storeWithVariants($file, $folder, MediaAsset::VARIANTS);

        $attributes = [
            'path' => $stored['path'],
            'disk' => 'public',
            'mime' => 'image/jpeg',
            'width' => $stored['width'],
            'height' => $stored['height'],
            'bytes' => filesize($path) ?: null,
            'alt' => $alt,
            'variants' => $stored['variants'],
        ];

        @unlink($path);

        return $companyId === null
            ? MediaAsset::createForPlatform($attributes)
            : MediaAsset::query()->withoutGlobalScopes()->create(array_merge($attributes, ['company_id' => $companyId]));
    }
}
