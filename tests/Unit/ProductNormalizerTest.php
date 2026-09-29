<?php

namespace Tests\Unit;

use App\Services\Catalog\ProductNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * El normalizador, sin Laravel de por medio.
 *
 * Los datos de una base colaborativa vienen sucios: nombres vacios, marcas en una sola cadena,
 * codigos con comillas. Aqui se fija exactamente que pasa con cada caso, porque es lo que
 * decide si un articulo entra al catalogo o se queda afuera con su motivo.
 */
class ProductNormalizerTest extends TestCase
{
    protected ProductNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ProductNormalizer;
    }

    public function test_un_ean_con_digito_de_verificacion_valido_pasa(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
        ]);

        $this->assertTrue($product->isUsable());
        $this->assertSame('7702001139415', $product->ean);
    }

    public function test_un_ean_con_digito_de_verificacion_invalido_se_omite(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139416',
            'product_name' => 'Yogurt Original',
        ]);

        $this->assertFalse($product->isUsable());
        $this->assertSame('invalid_ean', $product->reason);
    }

    public function test_un_upc_de_doce_digitos_se_normaliza_a_trece(): void
    {
        // 036000291452 es un UPC-A valido de manual.
        $product = $this->normalizer->normalize([
            'code' => '036000291452',
            'product_name' => 'Producto importado',
        ]);

        $this->assertTrue($product->isUsable());
        $this->assertSame('0036000291452', $product->ean);
        $this->assertSame(13, strlen($product->ean));
    }

    public function test_el_cero_de_la_izquierda_se_conserva(): void
    {
        // Prueba anti regresion del bug clasico: un (int) en cualquier punto del camino se
        // come el cero y el escaner de caja ya no encuentra el articulo.
        $product = $this->normalizer->normalize([
            'code' => '0036000291452',
            'product_name' => 'Producto importado',
        ]);

        $this->assertSame('0036000291452', $product->ean);
        $this->assertIsString($product->ean);
        $this->assertStringStartsWith('0', $product->ean);
    }

    public function test_un_codigo_entre_comillas_y_con_espacios_se_limpia(): void
    {
        $product = $this->normalizer->normalize([
            'code' => "'7702001139415 ",
            'product_name' => 'Yogurt Original',
        ]);

        $this->assertSame('7702001139415', $product->ean);
    }

    public function test_sin_nombre_ni_nombre_generico_se_omite(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => '   ',
            'generic_name' => '',
        ]);

        $this->assertFalse($product->isUsable());
        $this->assertSame('missing_name', $product->reason);
    }

    public function test_el_nombre_generico_sirve_de_respaldo(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => '',
            'generic_name' => 'Yogurt con dulce',
        ]);

        $this->assertTrue($product->isUsable());
        $this->assertSame('Yogurt con dulce', $product->name);
        // No se repite la misma frase en nombre y descripcion.
        $this->assertNull($product->description);
    }

    public function test_de_varias_marcas_se_toma_la_primera(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'brands' => 'Alpina, Alpina S.A.',
        ]);

        $this->assertSame('Alpina', $product->brand);
    }

    public function test_la_categoria_pierde_el_prefijo_de_idioma(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'categories_tags' => ['en:dairies', 'en:yogurts'],
        ]);

        $this->assertSame('dairies', $product->externalCategory);
    }

    public function test_los_guiones_de_la_categoria_pasan_a_espacios(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'categories_tags' => ['en:fermented-milk-products'],
        ]);

        $this->assertSame('fermented milk products', $product->externalCategory);
    }

    public function test_la_presentacion_se_parte_en_cantidad_y_unidad(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'quantity' => '1 L',
        ]);

        $this->assertSame('1 L', $product->packaging);
        $this->assertSame(1.0, $product->contentAmount);
        $this->assertSame('l', $product->contentUnit);
    }

    public function test_una_presentacion_que_no_se_entiende_se_queda_como_texto(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'quantity' => 'tamaño familiar',
        ]);

        $this->assertSame('tamaño familiar', $product->packaging);
        $this->assertNull($product->contentAmount);
        $this->assertNull($product->contentUnit);
    }

    public function test_una_imagen_que_no_es_https_se_descarta(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'image_front_small_url' => 'javascript:alert(1)',
        ]);

        $this->assertNull($product->imageUrl);
    }

    public function test_una_imagen_https_valida_se_conserva(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => 'Yogurt Original',
            'image_front_small_url' => 'https://images.openfoodfacts.org/images/products/front.jpg',
        ]);

        $this->assertSame('https://images.openfoodfacts.org/images/products/front.jpg', $product->imageUrl);
    }

    public function test_el_nombre_se_corta_a_doscientos_caracteres(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => str_repeat('a', 260),
        ]);

        $this->assertSame(200, mb_strlen($product->name));
    }

    public function test_los_espacios_repetidos_del_nombre_se_colapsan(): void
    {
        $product = $this->normalizer->normalize([
            'code' => '7702001139415',
            'product_name' => "  Yogurt    Original \n Fresa ",
        ]);

        $this->assertSame('Yogurt Original Fresa', $product->name);
    }

    public function test_un_codigo_de_longitud_rara_no_pasa(): void
    {
        foreach (['123', '12345678901', 'ABC12345678'] as $code) {
            $product = $this->normalizer->normalize(['code' => $code, 'product_name' => 'Algo']);

            $this->assertFalse($product->isUsable(), "El codigo {$code} no deberia pasar.");
            $this->assertSame('invalid_ean', $product->reason);
        }
    }
}
