<?php

namespace App\Landing\Demo;

use Illuminate\Support\Str;

/**
 * Los datos de ejemplo de la «Demo del sistema» de la landing: un negocio inventado con sus
 * artículos, ventas, pedidos, clientes y cuentas, para que quien visita vea como se usa el panel.
 *
 * Todo es ficticio y viaja al navegador con la pagina: lo que la persona hace en la demo (cobrar,
 * mover un pedido, apagar un articulo) vive en su pestaña y no llega nunca al servidor. Por eso no
 * hay rutas ni consultas aqui. Los nombres son genericos, sin marcas: la landing es publica.
 */
class DemoWorkspace
{
    /** Los modulos que se pueden mostrar, en el orden en que se ofrecen en el editor. */
    public const MODULES = [
        'dashboard' => ['label' => 'Inicio', 'title' => 'Inicio', 'subtitle' => 'Cómo va el negocio hoy'],
        'pos' => ['label' => 'Punto de venta', 'title' => 'Punto de venta', 'subtitle' => 'Toca un artículo para agregarlo al tiquete'],
        'orders' => ['label' => 'Pedidos en línea', 'title' => 'Pedidos en línea', 'subtitle' => 'Del pedido a la puerta del cliente'],
        'products' => ['label' => 'Artículos', 'title' => 'Artículos', 'subtitle' => 'Tu catálogo con precio y existencias'],
        'inventory' => ['label' => 'Inventario', 'title' => 'Inventario', 'subtitle' => 'Lo que hay, lo que falta y lo que se agotó'],
        'customers' => ['label' => 'Clientes', 'title' => 'Clientes', 'subtitle' => 'Quién compra, cuánto y cuándo'],
        'finance' => ['label' => 'Ingresos y gastos', 'title' => 'Ingresos y gastos', 'subtitle' => 'La utilidad real de tu negocio'],
    ];

    public const KINDS = ['market' => 'Mercado o tienda de barrio', 'fashion' => 'Ropa y moda'];

    /**
     * Lo que la demo necesita para pintarse: el negocio, los modulos elegidos y sus datos.
     *
     * @param  array<string, mixed>  $data  Los datos ya normalizados del bloque.
     * @return array<string, mixed>
     */
    public function build(array $data): array
    {
        $kind = array_key_exists($data['business_kind'] ?? '', self::KINDS) ? $data['business_kind'] : 'market';
        $name = trim((string) ($data['business_name'] ?? '')) ?: 'Mercado La Esquina';
        $keys = collect((array) ($data['modules'] ?? []))
            ->pluck('module')
            ->filter(fn ($key) => is_string($key) && isset(self::MODULES[$key]))
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            $keys = collect(['dashboard']);
        }

        $products = $this->products($kind);
        $scale = $kind === 'fashion' ? 1.6 : 1.0;

        return [
            'business' => [
                'name' => $name,
                'initials' => Str::upper(Str::substr(collect(explode(' ', $name))->filter()->map(fn ($word) => Str::substr($word, 0, 1))->implode(''), 0, 2)),
                'store' => 'Sede principal',
            ],
            'modules' => $keys->map(fn (string $key) => ['key' => $key, ...self::MODULES[$key]])->all(),
            'data' => $keys->mapWithKeys(fn (string $key) => [$key => match ($key) {
                'dashboard' => $this->dashboard($products, $scale),
                'pos' => $this->pos($products),
                'orders' => ['orders' => $this->orders($scale)],
                'products' => ['products' => $products, 'categories' => $this->categories($products)],
                'inventory' => ['products' => $products],
                'customers' => ['customers' => $this->customers($scale)],
                'finance' => $this->finance($scale),
            }])->all(),
            'notifications' => [
                'Nuevo pedido #1072 · Laura Gómez · '.$this->money(219000 * $scale).' · Pagado',
                'Venta C2-000188 · '.$this->money(64300 * $scale).' · Nequi',
                'Existencia baja: '.collect($products)->first(fn (array $product) => $product['stock'] > 0 && $product['stock'] <= $product['min_stock'])['name'],
                'Nuevo cliente registrado en la tienda en línea',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function products(string $kind): array
    {
        $rows = $kind === 'fashion'
            ? [
                ['Camiseta básica de algodón', 'Camisetas', 39900, 48, 15, 'und'],
                ['Jean slim azul', 'Pantalones', 119900, 14, 10, 'und'],
                ['Tenis clásicos blancos', 'Calzado', 189900, 6, 8, 'par'],
                ['Chaqueta de jean', 'Chaquetas', 159900, 9, 6, 'und'],
                ['Vestido midi estampado', 'Vestidos', 129900, 11, 6, 'und'],
                ['Buzo con capucha', 'Chaquetas', 99900, 0, 8, 'und'],
                ['Gorra bordada', 'Accesorios', 45900, 27, 10, 'und'],
                ['Medias tobilleras x3', 'Accesorios', 24900, 60, 20, 'paq'],
                ['Camisa de lino', 'Camisas', 89900, 16, 8, 'und'],
                ['Bolso de mano', 'Accesorios', 139900, 7, 5, 'und'],
                ['Pantaloneta deportiva', 'Pantalones', 54900, 30, 10, 'und'],
                ['Cinturón de cuero', 'Accesorios', 69900, 5, 8, 'und'],
            ]
            : [
                ['Arroz blanco 1 kg', 'Granos', 5100, 84, 30, 'und'],
                ['Aceite de girasol 1 L', 'Aceites', 12500, 12, 20, 'und'],
                ['Café molido 500 g', 'Café', 16900, 40, 15, 'und'],
                ['Leche entera 1,1 L', 'Lácteos y huevos', 4600, 6, 24, 'und'],
                ['Huevos AA x30', 'Lácteos y huevos', 18900, 25, 10, 'cubeta'],
                ['Pan tajado', 'Panadería', 7200, 18, 10, 'und'],
                ['Panela redonda', 'Despensa', 3800, 55, 20, 'und'],
                ['Atún en agua 160 g', 'Enlatados', 6200, 0, 12, 'und'],
                ['Detergente en polvo 1 kg', 'Aseo', 14300, 22, 10, 'und'],
                ['Papel higiénico x12', 'Aseo', 21500, 9, 12, 'paq'],
                ['Gaseosa 1,5 L', 'Bebidas', 4900, 60, 24, 'und'],
                ['Banano', 'Frutas y verduras', 3200, 35, 10, 'kg'],
            ];

        $prefix = $kind === 'fashion' ? 'MOD' : 'MER';

        return collect($rows)->values()->map(fn (array $row, int $index) => [
            'id' => $index + 1,
            'name' => $row[0],
            'sku' => sprintf('%s-%03d', $prefix, $index + 1),
            'category' => $row[1],
            'price' => $row[2],
            'cost' => (int) (round($row[2] * 0.68 / 100) * 100),
            'stock' => $row[3],
            'min_stock' => $row[4],
            'unit' => $row[5],
            'active' => true,
            'sold_week' => [42, 18, 25, 64, 31, 22, 47, 9, 15, 12, 58, 39][$index] ?? 10,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<string>
     */
    protected function categories(array $products): array
    {
        return collect($products)->pluck('category')->unique()->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    protected function dashboard(array $products, float $scale): array
    {
        $days = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
        $sales = [1920000, 2310000, 2050000, 2680000, 3120000, 3450000, 2845300];

        return [
            'kpis' => [
                ['label' => 'Ventas de hoy', 'value' => (int) (2845300 * $scale), 'money' => true, 'change' => 12.4],
                ['label' => 'Tiquetes', 'value' => 63, 'money' => false, 'change' => 8.1],
                ['label' => 'Tiquete promedio', 'value' => (int) (45163 * $scale), 'money' => true, 'change' => 3.9],
                ['label' => 'Pedidos en línea', 'value' => 9, 'money' => false, 'change' => -4.2],
            ],
            'week' => collect($days)->map(fn (string $day, int $index) => ['label' => $day, 'value' => (int) ($sales[$index] * $scale)])->all(),
            'top' => collect($products)->sortByDesc('sold_week')->take(5)->map(fn (array $product) => [
                'name' => $product['name'],
                'units' => $product['sold_week'],
                'total' => $product['sold_week'] * $product['price'],
            ])->values()->all(),
            'recent' => [
                ['number' => 'C1-000412', 'customer' => 'Consumidor final', 'method' => 'Efectivo', 'total' => (int) (38500 * $scale), 'ago' => 'hace 2 min'],
                ['number' => 'C2-000187', 'customer' => 'Valentina Ríos', 'method' => 'Nequi', 'total' => (int) (64300 * $scale), 'ago' => 'hace 9 min'],
                ['number' => 'MOS-000046', 'customer' => 'Carlos Pérez', 'method' => 'Tarjeta', 'total' => (int) (152900 * $scale), 'ago' => 'hace 21 min'],
                ['number' => 'C1-000411', 'customer' => 'Consumidor final', 'method' => 'Efectivo', 'total' => (int) (12400 * $scale), 'ago' => 'hace 34 min'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    protected function pos(array $products): array
    {
        // La caja solo ofrece lo que tiene existencias, y sus categorias salen de ahi.
        $available = array_values(array_filter($products, fn (array $product) => $product['stock'] > 0));

        return [
            'products' => $available,
            'categories' => $this->categories($available),
            'methods' => ['Efectivo', 'Nequi', 'Tarjeta'],
            'next_number' => 'C1-000413',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function orders(float $scale): array
    {
        $rows = [
            [1072, 'Laura Gómez', 4, 219000, 'new', 'Domicilio', 'Hoy, 4:00 – 6:00 p. m.'],
            [1071, 'Andrés Rojas', 2, 58600, 'new', 'Recoge en la sede', 'Hoy, 5:00 p. m.'],
            [1070, 'Sofía Castaño', 7, 184300, 'picking', 'Domicilio', 'Hoy, 2:00 – 4:00 p. m.'],
            [1069, 'Juan Esteban Mora', 3, 96500, 'picking', 'Domicilio', 'Hoy, 2:00 – 4:00 p. m.'],
            [1068, 'Daniela Torres', 5, 143200, 'shipping', 'Domicilio', 'En camino'],
            [1067, 'Miguel Ángel Duque', 1, 24900, 'delivered', 'Recoge en la sede', 'Entregado 11:40 a. m.'],
            [1066, 'Valentina Ríos', 6, 171800, 'delivered', 'Domicilio', 'Entregado 10:15 a. m.'],
        ];

        return collect($rows)->map(fn (array $row) => [
            'number' => '#'.$row[0],
            'customer' => $row[1],
            'items' => $row[2],
            'total' => (int) ($row[3] * $scale),
            'status' => $row[4],
            'delivery' => $row[5],
            'slot' => $row[6],
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function customers(float $scale): array
    {
        $rows = [
            ['Laura Gómez', 'laura.gomez@correo.co', 38, 1946000, 'hace 1 hora', 'Tienda en línea'],
            ['Andrés Rojas', 'andres.rojas@correo.co', 24, 1121500, 'hoy', 'Mostrador'],
            ['Valentina Ríos', 'vale.rios@correo.co', 52, 2874300, 'hoy', 'Mostrador'],
            ['Carlos Pérez', 'carlos.perez@correo.co', 11, 684200, 'hace 3 días', 'Tienda en línea'],
            ['Daniela Torres', 'dtorres@correo.co', 19, 932800, 'ayer', 'Tienda en línea'],
            ['Juan Esteban Mora', 'juanes.mora@correo.co', 7, 312600, 'hace 1 semana', 'Mostrador'],
            ['Sofía Castaño', 'sofia.castano@correo.co', 33, 1650400, 'hoy', 'Tienda en línea'],
            ['Miguel Ángel Duque', 'mduque@correo.co', 15, 744900, 'hace 2 días', 'Mostrador'],
        ];

        return collect($rows)->map(fn (array $row, int $index) => [
            'id' => $index + 1,
            'name' => $row[0],
            'email' => $row[1],
            'purchases' => $row[2],
            'spent' => (int) ($row[3] * $scale),
            'points' => (int) floor($row[3] * $scale / 1000),
            'last' => $row[4],
            'channel' => $row[5],
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function finance(float $scale): array
    {
        $months = ['may', 'jun', 'jul', 'ago', 'sept', 'oct'];
        $income = [9600000, 10800000, 11400000, 12200000, 13900000, 15200000];
        $expenses = [6900000, 7300000, 7600000, 7900000, 8400000, 8900000];
        $concepts = [
            ['Compra de mercancía', 4100000],
            ['Nómina', 2200000],
            ['Arriendo', 1500000],
            ['Servicios públicos', 480000],
            ['Domicilios', 350000],
            ['Comisiones de pasarela', 270000],
        ];

        $series = collect($months)->map(fn (string $month, int $index) => [
            'label' => $month,
            'income' => (int) ($income[$index] * $scale),
            'expenses' => (int) ($expenses[$index] * $scale),
        ]);

        $totalIncome = $series->sum('income');
        $totalExpenses = $series->sum('expenses');

        return [
            'months' => $series->all(),
            'totals' => [
                'income' => $totalIncome,
                'expenses' => $totalExpenses,
                'profit' => $totalIncome - $totalExpenses,
                'margin' => round(($totalIncome - $totalExpenses) / max(1, $totalIncome) * 100, 1),
                'income_change' => 14.2,
                'expenses_change' => -3.8,
                'profit_change' => 21.6,
            ],
            'concepts' => collect($concepts)->map(fn (array $row) => ['label' => $row[0], 'value' => (int) ($row[1] * $scale)])->all(),
        ];
    }

    /** El peso como lo pinta la interfaz: «$ 219.000». */
    protected function money(float $value): string
    {
        return '$ '.number_format((int) round($value), 0, ',', '.');
    }
}
