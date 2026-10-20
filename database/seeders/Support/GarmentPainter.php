<?php

namespace Database\Seeders\Support;

use App\Services\ImageService;
use Illuminate\Http\UploadedFile;

/**
 * Fotos de demostracion para la tienda de ropa: la silueta de la prenda en el color de la
 * variante, sobre un fondo claro. Las pinta GD, asi que no dependen de internet ni de fotos con
 * derechos de nadie; sirven para ver que la galeria cambia con el color.
 */
class GarmentPainter
{
    /** Las siluetas, en un lienzo de 900 x 900. */
    public const SHAPES = [
        'shirt' => [[360, 158, 225, 203, 90, 371, 191, 450, 264, 388, 264, 788, 636, 788, 636, 388, 709, 450, 810, 371, 675, 203, 540, 158, 512, 203, 450, 223, 388, 203]],
        'pants' => [[270, 120, 630, 120, 665, 800, 505, 800, 450, 340, 395, 800, 235, 800]],
        'dress' => [[380, 110, 520, 110, 560, 250, 520, 330, 690, 800, 210, 800, 380, 330, 340, 250]],
        'shoe' => [[130, 610, 170, 470, 330, 430, 440, 500, 630, 520, 770, 560, 790, 640, 130, 640], [130, 640, 790, 640, 790, 690, 130, 690]],
        'sock' => [[370, 110, 540, 110, 540, 560, 700, 640, 720, 730, 650, 790, 410, 700, 370, 640]],
        'belt' => [[90, 400, 810, 400, 810, 500, 90, 500], [640, 380, 760, 380, 760, 520, 640, 520]],
        'cap' => [[230, 520, 260, 380, 360, 300, 540, 300, 640, 380, 670, 520], [640, 470, 820, 520, 800, 560, 640, 540]],
        'bag' => [[250, 330, 650, 330, 700, 800, 200, 800], [330, 330, 360, 180, 540, 180, 570, 330, 530, 330, 510, 220, 390, 220, 370, 330]],
    ];

    public function __construct(protected ImageService $images) {}

    /**
     * Una silueta llevada a otro tamaño y lugar: centrada en (`cx`, `cy`) y de `size` pixeles de
     * lado. La usa el banner de la tienda de ropa.
     *
     * @return list<list<int>>
     */
    public static function placed(string $shape, int $cx, int $cy, int $size): array
    {
        $scale = $size / 900;

        return array_map(
            fn (array $points) => array_map(
                fn (int $value, int $index) => (int) round(($value - 450) * $scale + ($index % 2 === 0 ? $cx : $cy)),
                $points,
                array_keys($points),
            ),
            self::SHAPES[$shape] ?? [],
        );
    }

    /**
     * Pinta y guarda la foto de una prenda; devuelve las rutas como `storeProductImage`.
     *
     * @return array{path: string, thumb_path: string|null}|null
     */
    public function paint(string $shape, string $hex, int $companyId): ?array
    {
        if (! function_exists('imagecreatetruecolor') || ! isset(self::SHAPES[$shape])) {
            return null;
        }

        $image = imagecreatetruecolor(900, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 244, 241, 238));

        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x') ?: [136, 136, 136];
        $fill = imagecolorallocate($image, (int) $red, (int) $green, (int) $blue);
        // Un borde mas oscuro: una prenda blanca tambien se ve sobre el fondo claro.
        $line = imagecolorallocate($image, max(0, (int) $red - 70), max(0, (int) $green - 70), max(0, (int) $blue - 70));
        imagesetthickness($image, 6);

        foreach (self::SHAPES[$shape] as $points) {
            imagefilledpolygon($image, $points, $fill);
            imagepolygon($image, $points, $line);
        }

        $path = tempnam(sys_get_temp_dir(), 'prenda');
        imagepng($image, $path);
        imagedestroy($image);

        return $this->images->storeProductImage(new UploadedFile($path, $shape.'.png', 'image/png', null, true), $companyId);
    }
}
