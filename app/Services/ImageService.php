<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Redimensionado de las fotos de los articulos.
 *
 * Se guardan dos tamanos: uno de 900 px para la ficha y la tienda, y una miniatura de 280 px
 * para las cuadriculas del punto de venta. Una foto de camara de 4 MB servida tal cual hace
 * que el POS tarde en pintar la cuadricula, que es justo donde no se puede esperar.
 *
 * Usa GD directamente para no sumar una dependencia mas. Si GD no esta habilitada, guarda el
 * archivo original: es preferible una foto pesada a un articulo sin foto.
 */
class ImageService
{
    public const MAX_WIDTH = 900;

    public const THUMB_WIDTH = 280;

    /** Imagenes de marca que se suben desde Ajustes > Marca. */
    public const BRAND_SLOTS = ['mark', 'logo_light', 'logo_dark'];

    /** Isotipo: 512 para pantalla, 32 de favicon y 180 para el icono de iOS. */
    public const MARK_SIZE = 512;

    public const FAVICON_SIZE = 32;

    public const TOUCH_SIZE = 180;

    public const LOGO_WIDTH = 600;

    /** 576 puntos = 72 mm imprimibles a 203 dpi. En 58 mm se escala por CSS. */
    public const RECEIPT_LOGO_WIDTH = 576;

    public const RECEIPT_LOGO_MAX_HEIGHT = 240;

    /**
     * Guarda una imagen de marca en PNG, conservando la transparencia.
     *
     * No reusa `store()` ni `encode()`: esos guardan JPEG, que le pone fondo al logo. Cada
     * archivo lleva una ficha al azar en el nombre para que el navegador no muestre el anterior
     * de su cache cuando se reemplaza.
     *
     * @return array<string, string> rutas en el disco `public`
     */
    public function storeBrandAsset(UploadedFile $file, int $companyId, string $slot, string $accent = '#0E6E4E'): array
    {
        $directory = "brand/{$companyId}";
        $token = Str::lower(Str::random(8));
        $base = str_replace('_', '-', $slot);
        $source = $this->canWritePng() ? $this->read($file) : null;

        // Sin GD se guarda el original: pesa mas, pero la marca no se queda sin imagen.
        if (! $source) {
            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
            $path = $file->storeAs($directory, "{$base}-{$token}.{$extension}", 'public');

            return $slot === 'mark' ? ['path' => $path, 'favicon' => $path, 'touch' => $path] : ['path' => $path];
        }

        if ($slot === 'mark') {
            $paths = [
                'path' => "{$directory}/mark-".self::MARK_SIZE."-{$token}.png",
                'favicon' => "{$directory}/mark-".self::FAVICON_SIZE."-{$token}.png",
                'touch' => "{$directory}/mark-".self::TOUCH_SIZE."-{$token}.png",
            ];

            Storage::disk('public')->put($paths['path'], $this->png($this->square($source, self::MARK_SIZE)));
            Storage::disk('public')->put($paths['favicon'], $this->png($this->square($source, self::FAVICON_SIZE)));
            // iOS no respeta la transparencia del icono: se le pone el fondo del acento.
            Storage::disk('public')->put($paths['touch'], $this->png($this->square($source, self::TOUCH_SIZE, $accent)));

            imagedestroy($source);

            return $paths;
        }

        $path = "{$directory}/{$base}-{$token}.png";
        Storage::disk('public')->put($path, $this->png($this->fitWidth($source, self::LOGO_WIDTH)));
        imagedestroy($source);

        return ['path' => $path];
    }

    /**
     * Vuelve a armar el icono de iOS con otro fondo, a partir del isotipo ya guardado. Hace
     * falta cuando cambia el color de la marca: el fondo del icono es ese color.
     */
    public function storeTouchIcon(string $markPath, int $companyId, string $accent): ?string
    {
        $disk = Storage::disk('public');
        $source = $this->canWritePng() && $disk->exists($markPath) ? @imagecreatefromstring((string) $disk->get($markPath)) : false;

        if (! $source) {
            return null;
        }

        $path = "brand/{$companyId}/mark-".self::TOUCH_SIZE.'-'.Str::lower(Str::random(8)).'.png';
        $disk->put($path, $this->png($this->square($source, self::TOUCH_SIZE, $accent)));
        imagedestroy($source);

        return $path;
    }

    /**
     * El logo del tiquete: gris, a dos tonos y de 576 px, sacado de otra imagen de marca.
     *
     * La impresora termica no imprime color ni grises finos: se convierte aqui una vez y no en
     * cada impresion. Sin GD se copia tal cual y el tiquete HTML le aplica un filtro CSS.
     *
     * @return array{path: string}|null
     */
    public function storeReceiptLogo(string $sourcePath, int $companyId): ?array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        $token = Str::lower(Str::random(8));
        $contents = $disk->get($sourcePath);
        $image = $this->canWritePng() ? @imagecreatefromstring((string) $contents) : false;

        if (! $image) {
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png';
            $path = "brand/{$companyId}/logo-receipt-{$token}.{$extension}";
            $disk->put($path, (string) $contents);

            return ['path' => $path];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        // Ancho del papel, y no mas de 3 cm de alto: un logo alto se come medio tiquete.
        $scale = min(1, self::RECEIPT_LOGO_WIDTH / $width, self::RECEIPT_LOGO_MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // La transparencia se vuelve papel: blanco.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $opaque = $this->opacityMask($image, $targetWidth, $targetHeight);
        imagedestroy($image);

        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -60);
        $this->threshold($canvas, $opaque);

        $path = "brand/{$companyId}/logo-receipt-{$token}.png";
        $disk->put($path, $this->png($canvas));

        return ['path' => $path];
    }

    /**
     * Borra del disco los archivos de un valor de marca (`{path, favicon, touch}` o `{path}`).
     *
     * @param  array<string, string>|mixed  $value
     */
    public function deleteBrandAsset(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $paths = array_values(array_unique(array_filter($value, fn ($path) => is_string($path) && str_starts_with($path, 'brand/'))));

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }

    public function canWritePng(): bool
    {
        return $this->canResize() && function_exists('imagepng');
    }

    /**
     * Cuadrado de `$size` con la imagen centrada y sin deformar. Transparente, o del color
     * `$background` si se pide (el icono de iOS).
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    protected function square($source, int $size, ?string $background = null)
    {
        $canvas = $this->transparentCanvas($size, $size);

        if ($background !== null) {
            [$r, $g, $b] = sscanf(ltrim($background, '#'), '%02x%02x%02x') ?: [14, 110, 78];
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // Sobre fondo de color hay que mezclar; sobre transparente, copiar el alfa tal cual.
        imagealphablending($canvas, $background !== null);
        imagecopyresampled(
            $canvas,
            $source,
            (int) floor(($size - $targetWidth) / 2),
            (int) floor(($size - $targetHeight) / 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        return $canvas;
    }

    /**
     * Reduce a un ancho maximo conservando la proporcion y la transparencia. Nunca agranda.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    protected function fitWidth($source, int $maxWidth)
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = min($maxWidth, $width);
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

        $canvas = $this->transparentCanvas($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /**
     * @return \GdImage
     */
    protected function transparentCanvas(int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    /**
     * Que pixeles de la imagen original tienen tinta, ya al tamano del tiquete.
     *
     * @param  \GdImage  $source
     * @return list<list<bool>>
     */
    protected function opacityMask($source, int $width, int $height): array
    {
        $scaled = $this->transparentCanvas($width, $height);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $mask = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $mask[$y][$x] = ((imagecolorat($scaled, $x, $y) >> 24) & 0x7F) < 64;
            }
        }

        imagedestroy($scaled);

        return $mask;
    }

    /**
     * Deja la imagen a dos tonos con un umbral calculado (Otsu) sobre los pixeles con tinta.
     *
     * Un umbral fijo borraria un logo claro —un amarillo en gris queda casi blanco—; el de
     * Otsu separa el logo de su propio fondo. Si el logo es de un solo tono, todo lo que tiene
     * tinta va en negro.
     *
     * @param  \GdImage  $image
     * @param  list<list<bool>>  $opaque
     */
    protected function threshold($image, array $opaque): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = array_fill(0, 256, 0);
        $grays = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $gray = imagecolorat($image, $x, $y) & 0xFF;
                $grays[$y][$x] = $gray;

                if ($opaque[$y][$x] ?? false) {
                    $histogram[$gray]++;
                }
            }
        }

        $total = array_sum($histogram);
        $present = array_keys(array_filter($histogram));
        $flat = $present === [] || (max($present) - min($present)) < 32;
        $cut = $flat ? 256 : $this->otsu($histogram, $total);

        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $ink = ($opaque[$y][$x] ?? false) && $grays[$y][$x] < $cut;
                imagesetpixel($image, $x, $y, $ink ? $black : $white);
            }
        }
    }

    /**
     * @param  array<int, int>  $histogram
     */
    protected function otsu(array $histogram, int $total): int
    {
        $sum = 0;

        foreach ($histogram as $value => $count) {
            $sum += $value * $count;
        }

        $sumBackground = 0;
        $weightBackground = 0;
        $best = 0.0;
        $threshold = 128;

        foreach ($histogram as $value => $count) {
            $weightBackground += $count;

            if ($weightBackground === 0) {
                continue;
            }

            $weightForeground = $total - $weightBackground;

            if ($weightForeground === 0) {
                break;
            }

            $sumBackground += $value * $count;
            $meanBackground = $sumBackground / $weightBackground;
            $meanForeground = ($sum - $sumBackground) / $weightForeground;
            $between = $weightBackground * $weightForeground * (($meanBackground - $meanForeground) ** 2);

            if ($between > $best) {
                $best = $between;
                $threshold = $value + 1;
            }
        }

        return $threshold;
    }

    /**
     * @param  \GdImage  $image
     */
    protected function png($image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    /**
     * @return array{path: string, thumb_path: string|null}
     */
    public function storeProductImage(UploadedFile $file, int $companyId, string $folder = 'products'): array
    {
        $directory = "{$folder}/{$companyId}";
        $name = Str::random(32);

        if (! $this->canResize()) {
            return ['path' => $file->store($directory, 'public'), 'thumb_path' => null];
        }

        $source = $this->read($file);

        if (! $source) {
            return ['path' => $file->store($directory, 'public'), 'thumb_path' => null];
        }

        $path = "{$directory}/{$name}.jpg";
        $thumbPath = "{$directory}/{$name}-thumb.jpg";

        Storage::disk('public')->put($path, $this->encode($this->resize($source, self::MAX_WIDTH)));
        Storage::disk('public')->put($thumbPath, $this->encode($this->resize($source, self::THUMB_WIDTH)));

        imagedestroy($source);

        return ['path' => $path, 'thumb_path' => $thumbPath];
    }

    /** Ancho de las imagenes del editor de la tienda: un banner ocupa toda la pantalla. */
    public const BANNER_WIDTH = 1600;

    /** El ancho de la copia angosta de un banner, para celulares. */
    public const SMALL_WIDTH = 800;

    /**
     * Guarda una imagen suelta —un banner, por ejemplo— y devuelve su ruta.
     *
     * Sin GD se guarda tal cual llego: pesa mas, pero la tienda no se queda sin imagen.
     */
    public function store(UploadedFile $file, string $folder, int $width = self::BANNER_WIDTH): string
    {
        $directory = $folder.'/'.(TenantContext::activeCompanyId() ?? 0);

        if (! $this->canResize()) {
            return $file->store($directory, 'public');
        }

        $source = $this->read($file);

        if (! $source) {
            return $file->store($directory, 'public');
        }

        $path = $directory.'/'.Str::random(32).'.jpg';
        Storage::disk('public')->put($path, $this->encode($this->resize($source, $width)));
        imagedestroy($source);

        return $path;
    }

    /**
     * Guarda una imagen del editor con sus recortes.
     *
     * Se generan las tres proporciones que usa la tienda —apaisada, retrato y cuadrada— para
     * poder servir `srcset` y que la misma foto se vea bien en un banner y en una tarjeta.
     * En WebP si el servidor sabe; si no, en JPG, que pesa mas pero se ve igual.
     *
     * @param  array<string, array{0: int, 1: int}>  $ratios
     * @return array{path: string, width: int|null, height: int|null, variants: array<string, string>}
     */
    public function storeWithVariants(UploadedFile $file, string $folder, array $ratios): array
    {
        $directory = $folder.'/'.(TenantContext::activeCompanyId() ?? 0);
        $name = Str::random(32);

        if (! $this->canResize()) {
            return ['path' => $file->store($directory, 'public'), 'width' => null, 'height' => null, 'variants' => []];
        }

        $source = $this->read($file);

        if (! $source) {
            return ['path' => $file->store($directory, 'public'), 'width' => null, 'height' => null, 'variants' => []];
        }

        $full = $this->resize($source, self::BANNER_WIDTH);
        $path = $directory.'/'.$name.'.jpg';
        Storage::disk('public')->put($path, $this->encode($full));

        $variants = [];

        foreach ($ratios as $key => [$w, $h]) {
            $cropped = $this->crop($full, $w, $h);

            if (! $cropped) {
                continue;
            }

            $variantPath = $directory.'/'.$name.'-'.$key.($this->supportsWebp() ? '.webp' : '.jpg');
            Storage::disk('public')->put($variantPath, $this->encodeVariant($cropped));
            $variants[$key] = $variantPath;

            imagedestroy($cropped);
        }

        // Una copia angosta del original para el `srcset`: un celular no necesita bajar 1600 px.
        $small = imagesx($full) > self::SMALL_WIDTH ? $this->resize($full, self::SMALL_WIDTH) : null;

        if ($small) {
            $smallPath = $directory.'/'.$name.'-sm'.($this->supportsWebp() ? '.webp' : '.jpg');
            Storage::disk('public')->put($smallPath, $this->encodeVariant($small));
            $variants['sm'] = $smallPath;
            imagedestroy($small);
        }

        $width = imagesx($full);
        $height = imagesy($full);

        imagedestroy($full);

        if ($full !== $source) {
            imagedestroy($source);
        }

        return ['path' => $path, 'width' => $width, 'height' => $height, 'variants' => $variants];
    }

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * Recorta al centro con la proporcion pedida.
     *
     * @return \GdImage|null
     */
    protected function crop($source, int $ratioWidth, int $ratioHeight)
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $target = $ratioWidth / $ratioHeight;
        $current = $width / $height;

        if ($current > $target) {
            $cropWidth = (int) round($height * $target);
            $cropHeight = $height;
        } else {
            $cropWidth = $width;
            $cropHeight = (int) round($width / $target);
        }

        $canvas = imagecreatetruecolor($cropWidth, $cropHeight);

        if (! $canvas) {
            return null;
        }

        imagecopy(
            $canvas,
            $source,
            0,
            0,
            (int) round(($width - $cropWidth) / 2),
            (int) round(($height - $cropHeight) / 2),
            $cropWidth,
            $cropHeight
        );

        return $canvas;
    }

    protected function encodeVariant($image): string
    {
        ob_start();

        if ($this->supportsWebp()) {
            imagewebp($image, null, 82);
        } else {
            imagejpeg($image, null, 82);
        }

        return (string) ob_get_clean();
    }

    public function canResize(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    }

    /**
     * @return \GdImage|null
     */
    protected function read(UploadedFile $file)
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image ?: null;
    }

    /**
     * Reduce la imagen conservando la proporcion. Nunca la agranda.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    protected function resize($source, int $maxWidth)
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxWidth) {
            return $this->flatten($source, $width, $height);
        }

        $targetHeight = (int) round($height * ($maxWidth / $width));

        return $this->flatten($source, $maxWidth, $targetHeight);
    }

    /**
     * Copia sobre fondo blanco: los PNG con transparencia salen negros en JPEG.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    protected function flatten($source, int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    /**
     * @param  \GdImage  $image
     */
    protected function encode($image): string
    {
        ob_start();
        imagejpeg($image, null, 82);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
