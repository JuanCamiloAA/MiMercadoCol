<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale;
use App\Settings\SettingsSchema;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/**
 * Lo que necesita un tiquete para imprimirse: los ajustes efectivos de su sede, el logo y el
 * codigo del pie. Lo usan el tiquete HTML de la caja, el PDF y la vista previa de Ajustes, para
 * que los tres digan lo mismo.
 */
class ReceiptService
{
    /** Tamano de la letra en px segun `receipt.font_size`. 11 px es lo que imprimia siempre. */
    public const FONT_SIZES = ['small' => 10, 'normal' => 11, 'large' => 12];

    public function __construct(protected SettingsService $settings) {}

    /**
     * Ajustes efectivos del tiquete de una sede, ya normalizados.
     *
     * NIT, base e IVA no estan aqui: salen siempre, porque el tiquete puede ser documento
     * equivalente y no son opcionales.
     *
     * @return array{paper_width: int, font_px: int, font_size: string, show_logo: bool, header: list<string>, fields: list<string>, code: string, footer: string, copies: int, auto_print: bool}
     */
    public function settingsFor(int $companyId, ?int $storeId): array
    {
        $values = $this->settings->forStore($companyId, $storeId);
        $defaults = SettingsService::DEFAULTS;

        $paper = (int) ($values['receipt.paper_width'] ?? 80);
        $fontSize = (string) ($values['receipt.font_size'] ?? 'normal');
        $fields = is_array($values['receipt.fields'] ?? null) ? $values['receipt.fields'] : $defaults['receipt.fields'];
        $code = (string) ($values['receipt.code'] ?? 'none');
        $copies = (int) ($values['receipt.copies'] ?? 1);

        return [
            'paper_width' => in_array($paper, SettingsSchema::PAPER_WIDTHS, true) ? $paper : 80,
            'font_size' => array_key_exists($fontSize, self::FONT_SIZES) ? $fontSize : 'normal',
            'font_px' => self::FONT_SIZES[$fontSize] ?? 11,
            'show_logo' => (bool) ($values['receipt.show_logo'] ?? false),
            'header' => self::headerLines($values['receipt.header'] ?? ''),
            'fields' => array_values(array_intersect(SettingsSchema::RECEIPT_FIELDS, $fields)),
            'code' => in_array($code, SettingsSchema::RECEIPT_CODES, true) ? $code : 'none',
            'footer' => (string) ($values['pos.receipt_footer'] ?: $defaults['pos.receipt_footer']),
            'copies' => in_array($copies, SettingsSchema::RECEIPT_COPIES, true) ? $copies : 1,
            'auto_print' => (bool) ($values['receipt.auto_print'] ?? true),
        ];
    }

    /**
     * El encabezado, renglon por renglon y sin renglones vacios al final.
     *
     * @return list<string>
     */
    public static function headerLines(mixed $header): array
    {
        if (! is_string($header) || trim($header) === '') {
            return [];
        }

        $lines = array_map('rtrim', preg_split('/\r\n|\r|\n/', $header) ?: []);

        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /**
     * El logo del tiquete: una URL para el HTML o la ruta en disco para dompdf, que no descarga
     * direcciones sin `isRemoteEnabled`. Si la empresa no ha subido ninguno, la canasta de
     * siempre en negro, para que «Imprimir el logo» sirva desde el primer dia.
     */
    public function logoFor(int $companyId, bool $forPdf = false): string
    {
        $value = $this->settings->get($companyId, 'brand.logo_receipt');
        $path = is_array($value) ? ($value['path'] ?? null) : null;

        if (! is_string($path) || ! Storage::disk('public')->exists($path)) {
            return $this->defaultLogo();
        }

        return $forPdf ? Storage::disk('public')->path($path) : '/storage/'.$path;
    }

    /**
     * La canasta en negro, del tamano de un isotipo impreso (unos 9 mm).
     */
    public function defaultLogo(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 32 32">'
            .'<rect width="32" height="32" rx="8" fill="#000"/>'
            .'<path d="M7 11h18l-2.2 13H9.2L7 11Z" fill="none" stroke="#fff" stroke-width="2.4" stroke-linejoin="round"/>'
            .'<path d="M12 11a4 4 0 0 1 8 0" fill="none" stroke="#fff" stroke-width="2.4" stroke-linejoin="round"/>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * El codigo del pie en SVG, como `data:` para ponerlo en un `<img>`: asi sirve igual en el
     * HTML y en dompdf, que no pinta un `<svg>` en linea.
     */
    public function codeDataUri(string $type, string $value): ?string
    {
        $svg = $this->codeSvg($type, $value);

        return $svg === null ? null : 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function codeSvg(string $type, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return match ($type) {
                // Code 128: lo lee cualquier lector de caja y admite letras y guiones.
                'barcode' => (new BarcodeGeneratorSVG)->getBarcode($value, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 44),
                'qr' => (new QRCode(new QROptions([
                    'outputInterface' => QRMarkupSVG::class,
                    'outputBase64' => false,
                    'svgAddXmlHeader' => false,
                    'eccLevel' => EccLevel::M,
                    'quietzoneSize' => 1,
                    'drawLightModules' => false,
                    'svgUseFillAttributes' => true,
                    'connectPaths' => true,
                ])))->render($value),
                default => null,
            };
        } catch (Throwable) {
            // Un codigo que no se pudo generar no debe impedir que el tiquete salga.
            return null;
        }
    }

    /**
     * El nombre grande del tiquete.
     *
     * Si la empresa le puso nombre a su marca en Ajustes, ese; si no, el de la empresa, que es
     * lo que el tiquete imprimio siempre.
     */
    public function headline(int $companyId, ?Company $company): string
    {
        $brandName = trim((string) $this->settings->get($companyId, 'brand.display_name'));

        if ($brandName !== '' && $brandName !== SettingsService::DEFAULTS['brand.display_name']) {
            return $brandName;
        }

        return ($company?->name ?: $brandName) ?: SettingsService::DEFAULTS['brand.display_name'];
    }

    /**
     * Todo lo que el blade del tiquete necesita para una venta.
     *
     * @return array<string, mixed>
     */
    public function viewData(Sale $sale, ?Company $company, bool $forPdf = false): array
    {
        $receipt = $this->settingsFor((int) $sale->company_id, $sale->store_id ? (int) $sale->store_id : null);

        return [
            'sale' => $sale,
            'company' => $company,
            'headline' => $this->headline((int) $sale->company_id, $company),
            'receipt' => $receipt,
            'footer' => $receipt['footer'],
            'logo' => $receipt['show_logo'] ? $this->logoFor((int) $sale->company_id, $forPdf) : null,
            'code' => $receipt['code'] !== 'none' ? $this->codeDataUri($receipt['code'], (string) $sale->number) : null,
        ];
    }
}
