<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * Cuanto pedir de cada articulo, y a quien.
 *
 * Es el unico sitio que decide la cantidad sugerida. La pantalla la muestra y el servidor la
 * vuelve a calcular al guardar: lo que llega del navegador es una propuesta, nunca la verdad.
 *
 * La existencia siempre sale de `product_stocks` de la **sede activa**. Una tienda con tres
 * sedes no compra contra el consolidado: cada una pide lo que le falta a ella.
 */
class PurchaseSuggestionService
{
    /**
     * Cuanto pedir.
     *
     *   falta    = lo que hace falta para volver al minimo
     *   objetivo = lo que hace falta para llegar al minimo por el multiplicador
     *
     * Se toma el mayor de los dos y se redondea hacia arriba al empaque de compra: si el
     * proveedor vende por cajas de doce, pedir siete no es una opcion.
     */
    public function suggest(float $stock, float $minStock, float $multiplier = 2.0, int $packSize = 1): int
    {
        $missing = max(0.0, $minStock - $stock);
        $target = ceil($minStock * $multiplier) - $stock;

        $suggested = max($missing, $target, 0.0);

        if ($suggested <= 0) {
            return 0;
        }

        $pack = max(1, $packSize);

        return (int) (ceil($suggested / $pack) * $pack);
    }

    /** Lo mismo, leyendo el articulo. */
    public function suggestFor(Product $product, float $stock): int
    {
        return $this->suggest(
            $stock,
            (float) $product->min_stock,
            (float) ($product->reorder_multiplier ?: 2.0),
            (int) ($product->purchase_pack_size ?: 1),
        );
    }

    /**
     * Existencia disponible por articulo en una sede.
     *
     * Disponible, no bruta: lo reservado para pedidos en linea ya esta comprometido y no
     * deberia contar como surtido en la estanteria.
     *
     * @param  list<int>  $productIds  Vacio trae toda la sede.
     * @return Collection<int, float>
     */
    public function stockByProduct(int $companyId, int $storeId, array $productIds = []): Collection
    {
        return ProductStock::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)
            ->when($productIds !== [], fn ($query) => $query->whereIn('product_id', $productIds))
            // Se suman las bodegas de la sede: comprar o no es una pregunta de la sede. Sin el
            // agrupado, una sede con dos bodegas devolveria solo la fila de la ultima.
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(reserved_quantity) as reserved_quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->product_id => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
            ]);
    }

    /**
     * Cuantos articulos de la sede estan en el minimo o por debajo.
     *
     * Es el numero del boton «Agregar los N bajo minimo», asi que tiene que contar lo mismo que
     * despues se agrega: la misma condicion, la misma sede.
     */
    public function lowStockCount(int $companyId, int $storeId): int
    {
        // Se cuenta por articulo, no por fila: un articulo con cero en el piso y doscientos
        // atras no esta bajo minimo, y contar las filas sueltas lo marcaria como si lo estuviera.
        $rows = ProductStock::query()
            ->withoutGlobalScopes()
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.company_id', $companyId)
            ->where('product_stocks.store_id', $storeId)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->where('products.min_stock', '>', 0)
            ->groupBy('product_stocks.product_id', 'products.min_stock')
            ->havingRaw('SUM(product_stocks.quantity - product_stocks.reserved_quantity) <= products.min_stock')
            ->selectRaw('product_stocks.product_id')
            ->get();

        return $rows->count();
    }
}
