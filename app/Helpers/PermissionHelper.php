<?php

namespace App\Helpers;

/**
 * Catalogo de permisos de la aplicacion.
 *
 * Un permiso por accion real, no uno por pantalla. Esta clase es la fuente de la verdad:
 * si un permiso no esta aqui no existe, no se guarda en `model_has_permissions` y ninguna
 * ruta lo exige.
 *
 * Formato de cada pagina:
 *   'actions' => list<string>            claves que forman `modulo.pagina.accion`
 *   'labels'  => array<string, string>   texto que lee un humano en el asignador
 */
class PermissionHelper
{
    /** Etiqueta por defecto de los verbos que se repiten en todos los modulos. */
    protected const DEFAULT_ACTION_LABELS = [
        'view' => 'Ver',
        'create' => 'Crear',
        'edit' => 'Editar',
        'delete' => 'Eliminar',
        'export' => 'Exportar',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getPermissionMatrix(): array
    {
        return [
            'dashboard' => [
                'display' => 'Inicio',
                'icon' => 'House',
                'order' => 1,
                'pages' => [
                    'index' => [
                        'display' => 'Panel de inicio',
                        'route' => 'dashboard',
                        'actions' => ['view', 'sales_totals'],
                        'labels' => [
                            'view' => 'Ver el panel de inicio',
                            'sales_totals' => 'Ver totales de ventas de la empresa',
                        ],
                    ],
                ],
            ],

            'pos' => [
                'display' => 'Punto de venta',
                'icon' => 'ShoppingCart',
                'order' => 2,
                'pages' => [
                    'sale' => [
                        'display' => 'Venta',
                        'route' => 'pos.index',
                        'actions' => ['view', 'create', 'discount', 'credit', 'cancel', 'suspend', 'refund', 'service_remove', 'service_reduce', 'service_increase', 'service_add', 'gateway'],
                        'labels' => [
                            'view' => 'Abrir el punto de venta',
                            'create' => 'Registrar ventas',
                            'discount' => 'Aplicar descuentos',
                            'credit' => 'Vender a credito',
                            'cancel' => 'Anular una venta cobrada',
                            'suspend' => 'Suspender y recuperar tiquetes',
                            'refund' => 'Registrar devoluciones',
                            'service_remove' => 'Quitar artículos de un servicio en la venta',
                            'service_reduce' => 'Usar menos cantidad de un artículo del servicio',
                            'service_increase' => 'Usar más cantidad de un artículo del servicio',
                            'service_add' => 'Agregar a un servicio un artículo que no trae',
                            'gateway' => 'Cobrar con QR, link de pago o datáfono integrado',
                        ],
                    ],
                ],
            ],

            'sales' => [
                'display' => 'Ventas',
                'icon' => 'Receipt',
                'order' => 3,
                'pages' => [
                    'index' => [
                        'display' => 'Historial de ventas',
                        'route' => 'sales.index',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver las ventas cobradas y su detalle',
                        ],
                    ],
                    'returns' => [
                        'display' => 'Devoluciones de venta',
                        'route' => 'customer_returns.index',
                        'actions' => ['view', 'create', 'approve', 'receive', 'resolve', 'cancel'],
                        'labels' => [
                            'view' => 'Ver las devoluciones de venta',
                            'create' => 'Registrar devoluciones desde el panel',
                            'approve' => 'Aprobar o rechazar solicitudes y devoluciones fuera de plazo',
                            'receive' => 'Recibir la mercancia y decidir si vuelve al inventario o va a averia',
                            'resolve' => 'Resolver: reembolso o saldo a favor',
                            'cancel' => 'Anular solicitudes que aun no se han recibido',
                        ],
                    ],
                ],
            ],

            'registers' => [
                'display' => 'Caja',
                'icon' => 'CashRegister',
                'order' => 4,
                'pages' => [
                    'open' => [
                        'display' => 'Apertura de caja',
                        'route' => 'registers.opening.create',
                        'actions' => ['view', 'create'],
                        'labels' => [
                            'view' => 'Ver la pantalla de apertura',
                            'create' => 'Abrir caja con base inicial',
                        ],
                    ],
                    'close' => [
                        'display' => 'Cierre de caja',
                        'route' => 'registers.closing.create',
                        'actions' => ['view', 'create', 'view_others'],
                        'labels' => [
                            'view' => 'Ver el cierre de caja',
                            'create' => 'Cerrar turno y generar el informe Z',
                            'view_others' => 'Ver y cerrar turnos de otros cajeros',
                        ],
                    ],
                    'movements' => [
                        'display' => 'Movimientos de efectivo',
                        'route' => 'registers.closing.create',
                        'actions' => ['create'],
                        'labels' => [
                            'create' => 'Registrar sangrias e ingresos de efectivo',
                        ],
                    ],
                    'differences' => [
                        'display' => 'Diferencias de caja',
                        'route' => 'registers.differences.index',
                        'actions' => ['view', 'resolve'],
                        'labels' => [
                            'view' => 'Ver los turnos que no cuadraron',
                            'resolve' => 'Decidir que se hace con una diferencia',
                        ],
                    ],
                    'index' => [
                        'display' => 'Listado de cajas',
                        'route' => 'registers.index',
                        'actions' => ['view', 'manage', 'import'],
                        'labels' => [
                            'view' => 'Ver el listado de cajas',
                            'manage' => 'Crear, editar y desactivar cajas',
                            'import' => 'Importar cajas desde un archivo',
                        ],
                    ],
                ],
            ],

            'products' => [
                'display' => 'Articulos',
                'icon' => 'Package',
                'order' => 5,
                'pages' => [
                    'index' => [
                        'display' => 'Catalogo',
                        'route' => 'products.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'print_labels'],
                        'labels' => [
                            'view' => 'Ver el catalogo',
                            'create' => 'Crear articulos',
                            'edit' => 'Editar articulos',
                            'delete' => 'Eliminar articulos',
                            'export' => 'Exportar el catalogo',
                            'import' => 'Importar articulos desde CSV',
                            'print_labels' => 'Imprimir etiquetas de precio',
                        ],
                    ],
                    'show' => [
                        'display' => 'Ficha del articulo',
                        'route' => 'products.show',
                        'actions' => ['view', 'view_cost', 'edit_price'],
                        'labels' => [
                            'view' => 'Ver la ficha del articulo',
                            'view_cost' => 'Ver costo y margen',
                            'edit_price' => 'Cambiar precios',
                        ],
                    ],
                    'attributes' => [
                        'display' => 'Atributos: volumen, peso, sabor, talla, color',
                        'route' => 'attributes.index',
                        'actions' => ['view', 'manage'],
                        'labels' => [
                            'view' => 'Ver las caracteristicas y sus valores',
                            'manage' => 'Crear, ordenar y desactivar caracteristicas y valores',
                        ],
                    ],
                    'catalog_import' => [
                        'display' => 'Importar desde catalogo externo',
                        'route' => 'catalog.import.index',
                        'actions' => ['view', 'run', 'cancel'],
                        'labels' => [
                            'view' => 'Abrir el asistente y previsualizar',
                            'run' => 'Traer articulos al catalogo',
                            'cancel' => 'Cancelar una importacion en curso',
                        ],
                    ],
                ],
            ],

            'categories' => [
                'display' => 'Categorias',
                'icon' => 'SquaresFour',
                'order' => 6,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de categorias',
                        'route' => 'categories.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'import'],
                        'labels' => [
                            'view' => 'Ver categorias',
                            'create' => 'Crear categorias',
                            'edit' => 'Editar categorias',
                            'delete' => 'Eliminar categorias',
                            'import' => 'Importar categorias desde un archivo',
                        ],
                    ],
                ],
            ],

            'suppliers' => [
                'display' => 'Proveedores',
                'icon' => 'Truck',
                'order' => 7,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de proveedores',
                        'route' => 'suppliers.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'import'],
                        'labels' => [
                            'view' => 'Ver proveedores',
                            'create' => 'Crear proveedores',
                            'edit' => 'Editar proveedores',
                            'delete' => 'Eliminar proveedores',
                            'import' => 'Importar proveedores desde un archivo',
                        ],
                    ],
                ],
            ],

            'inventory' => [
                'display' => 'Inventario',
                'icon' => 'Stack',
                'order' => 8,
                'pages' => [
                    'index' => [
                        'display' => 'Existencias',
                        'route' => 'inventory.index',
                        'actions' => ['view', 'adjust', 'view_other_stores'],
                        'labels' => [
                            'view' => 'Ver existencias',
                            'adjust' => 'Registrar ajustes y averias',
                            'view_other_stores' => 'Ver existencias de otras sedes',
                        ],
                    ],
                    'movements' => [
                        'display' => 'Kardex',
                        'route' => 'inventory.movements',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver el kardex de movimientos',
                        ],
                    ],
                    'transfers' => [
                        'display' => 'Traslados',
                        'route' => 'transfers.index',
                        'actions' => ['view', 'create', 'dispatch', 'receive', 'direct', 'cancel'],
                        'labels' => [
                            'view' => 'Ver los traslados',
                            'create' => 'Crear traslados',
                            'dispatch' => 'Despachar un traslado',
                            'receive' => 'Recibir un traslado',
                            'direct' => 'Trasladar directo (despachar y recibir en un solo acto)',
                            'cancel' => 'Anular un traslado por despachar',
                        ],
                    ],
                    'transfer_requests' => [
                        'display' => 'Solicitudes de traslado',
                        'route' => 'transfer_requests.index',
                        'actions' => ['view', 'create', 'fulfill', 'cancel'],
                        'labels' => [
                            'view' => 'Ver las solicitudes de traslado',
                            'create' => 'Pedir mercancia a otra bodega',
                            'fulfill' => 'Atender solicitudes: generar el traslado, rechazar o cerrar',
                            'cancel' => 'Anular una solicitud pendiente',
                        ],
                    ],
                ],
            ],

            'services' => [
                'display' => 'Servicios',
                'icon' => 'Wrench',
                'order' => 6,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de servicios',
                        'route' => 'services.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import'],
                        'labels' => [
                            'view' => 'Ver los servicios',
                            'create' => 'Crear servicios',
                            'edit' => 'Editar servicios',
                            'delete' => 'Eliminar servicios',
                            'export' => 'Exportar servicios',
                            'import' => 'Importar servicios desde CSV',
                        ],
                    ],
                    'show' => [
                        'display' => 'Ficha del servicio',
                        'route' => 'services.show',
                        'actions' => ['view', 'view_cost', 'edit_price'],
                        'labels' => [
                            'view' => 'Ver la ficha del servicio',
                            'view_cost' => 'Ver costo y margen',
                            'edit_price' => 'Cambiar precios',
                        ],
                    ],
                ],
            ],

            'purchases' => [
                'display' => 'Compras',
                'icon' => 'Receipt',
                'order' => 9,
                'pages' => [
                    'index' => [
                        'display' => 'Ordenes de compra',
                        'route' => 'purchases.index',
                        'actions' => ['view', 'create', 'receive', 'cancel'],
                        'labels' => [
                            'view' => 'Ver ordenes de compra',
                            'create' => 'Crear ordenes de compra',
                            'receive' => 'Recibir mercancia',
                            'cancel' => 'Anular ordenes',
                        ],
                    ],
                    'returns' => [
                        'display' => 'Devoluciones a proveedor',
                        'route' => 'supplier_returns.index',
                        'actions' => ['view', 'create', 'dispatch', 'respond', 'cancel'],
                        'labels' => [
                            'view' => 'Ver devoluciones a proveedor',
                            'create' => 'Crear y editar devoluciones en borrador',
                            'dispatch' => 'Despachar devoluciones (saca la mercancia del inventario)',
                            'respond' => 'Registrar la respuesta del proveedor: nota credito, reembolso, reposicion o rechazo',
                            'cancel' => 'Anular devoluciones en borrador',
                        ],
                    ],
                ],
            ],

            'customers' => [
                'display' => 'Clientes',
                'icon' => 'Users',
                'order' => 10,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de clientes',
                        'route' => 'customers.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'import'],
                        'labels' => [
                            'view' => 'Ver clientes',
                            'create' => 'Crear clientes',
                            'edit' => 'Editar clientes',
                            'delete' => 'Eliminar clientes',
                            'import' => 'Importar clientes desde un archivo',
                        ],
                    ],
                    'show' => [
                        'display' => 'Ficha del cliente',
                        'route' => 'customers.show',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver la ficha del cliente',
                        ],
                    ],
                ],
            ],

            'credit' => [
                'display' => 'Cartera',
                'icon' => 'CreditCard',
                'order' => 11,
                'pages' => [
                    'index' => [
                        'display' => 'Estado de cartera',
                        'route' => 'credit.index',
                        'actions' => ['view', 'set_limit', 'block', 'export'],
                        'labels' => [
                            'view' => 'Ver la cartera',
                            'set_limit' => 'Asignar o cambiar el cupo',
                            'block' => 'Bloquear o desbloquear el credito',
                            'export' => 'Exportar el estado de cartera',
                        ],
                    ],
                    'payments' => [
                        'display' => 'Abonos',
                        'route' => 'credit.index',
                        'actions' => ['view', 'create'],
                        'labels' => [
                            'view' => 'Ver el historico de abonos',
                            'create' => 'Registrar abonos',
                        ],
                    ],
                ],
            ],

            'orders' => [
                'display' => 'Pedidos',
                'icon' => 'ClipboardText',
                'order' => 12,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de pedidos',
                        'route' => 'orders.index',
                        'actions' => ['view', 'create'],
                        'labels' => [
                            'view' => 'Ver pedidos',
                            'create' => 'Crear pedidos desde el mostrador',
                        ],
                    ],
                    'show' => [
                        'display' => 'Detalle del pedido',
                        'route' => 'orders.show',
                        'actions' => ['view', 'pick', 'assign_courier', 'status', 'cancel', 'collect'],
                        'labels' => [
                            'view' => 'Ver el detalle del pedido',
                            'pick' => 'Alistar y marcar reemplazos',
                            'assign_courier' => 'Asignar domiciliario',
                            'status' => 'Cambiar el estado del pedido',
                            'cancel' => 'Cancelar pedidos',
                            'collect' => 'Registrar el cobro contraentrega',
                        ],
                    ],
                ],
            ],

            'employees' => [
                'display' => 'Empleados',
                'icon' => 'IdentificationCard',
                'order' => 13,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de empleados',
                        'route' => 'employees.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'export', 'deactivate', 'reactivate', 'import'],
                        'labels' => [
                            'view' => 'Ver el listado',
                            'create' => 'Crear empleados',
                            'edit' => 'Editar empleados',
                            'delete' => 'Eliminar empleados',
                            'export' => 'Exportar el listado',
                            'deactivate' => 'Desactivar empleado',
                            'reactivate' => 'Reactivar empleado',
                            'import' => 'Importar empleados desde un archivo',
                        ],
                    ],
                    'show' => [
                        'display' => 'Ficha del empleado',
                        'route' => 'employees.show',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver la ficha del empleado',
                        ],
                    ],
                    'access' => [
                        'display' => 'Acceso al sistema',
                        'route' => 'employees.show',
                        'actions' => ['create', 'toggle', 'change_role', 'reset_password', 'reset_pin'],
                        'labels' => [
                            'create' => 'Crear la cuenta de acceso',
                            'toggle' => 'Activar o desactivar el acceso',
                            'change_role' => 'Cambiar el rol de la cuenta',
                            'reset_password' => 'Restablecer la contrasena',
                            'reset_pin' => 'Restablecer el PIN de caja',
                        ],
                    ],
                ],
            ],

            'users' => [
                'display' => 'Usuarios',
                'icon' => 'UserGear',
                'order' => 14,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de usuarios',
                        'route' => 'users.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'toggle'],
                        'labels' => [
                            'view' => 'Ver usuarios',
                            'create' => 'Crear usuarios',
                            'edit' => 'Editar usuarios',
                            'delete' => 'Eliminar usuarios',
                            'toggle' => 'Activar o desactivar usuarios',
                        ],
                    ],
                    'edit' => [
                        'display' => 'Permisos del usuario',
                        'route' => 'users.permissions',
                        'actions' => ['permissions'],
                        'labels' => [
                            'permissions' => 'Ajustar los permisos de un usuario',
                        ],
                    ],
                    'show' => [
                        'display' => 'Ficha del usuario',
                        'route' => 'users.show',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver la ficha del usuario',
                        ],
                    ],
                ],
            ],

            'roles' => [
                'display' => 'Roles',
                'icon' => 'ShieldCheck',
                'order' => 15,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de roles',
                        'route' => 'roles.index',
                        'actions' => ['view', 'create', 'edit', 'delete'],
                        'labels' => [
                            'view' => 'Ver roles',
                            'create' => 'Crear roles',
                            'edit' => 'Editar roles',
                            'delete' => 'Eliminar roles',
                        ],
                    ],
                    'edit' => [
                        'display' => 'Propagacion',
                        'route' => 'roles.edit',
                        'actions' => ['propagate'],
                        'labels' => [
                            'propagate' => 'Aplicar el cambio del rol a sus usuarios',
                        ],
                    ],
                ],
            ],

            'reports' => [
                'display' => 'Informes',
                'icon' => 'ChartBar',
                'order' => 16,
                'pages' => [
                    'sales' => [
                        'display' => 'Informe de ventas',
                        'route' => 'reports.index',
                        'actions' => ['view', 'export'],
                        'labels' => [
                            'view' => 'Ver el informe de ventas',
                            'export' => 'Exportar el informe de ventas',
                        ],
                    ],
                    'inventory' => [
                        'display' => 'Informe de inventario',
                        'route' => 'reports.index',
                        'actions' => ['view', 'export'],
                        'labels' => [
                            'view' => 'Ver el informe de inventario',
                            'export' => 'Exportar el informe de inventario',
                        ],
                    ],
                    'credit' => [
                        'display' => 'Informe de cartera',
                        'route' => 'reports.index',
                        'actions' => ['view', 'export'],
                        'labels' => [
                            'view' => 'Ver el informe de cartera',
                            'export' => 'Exportar el informe de cartera',
                        ],
                    ],
                    'employees' => [
                        'display' => 'Informe de empleados',
                        'route' => 'reports.index',
                        'actions' => ['view', 'export'],
                        'labels' => [
                            'view' => 'Ver el informe de empleados',
                            'export' => 'Exportar el informe de empleados',
                        ],
                    ],
                ],
            ],

            'coupons' => [
                'display' => 'Cupones',
                'icon' => 'Ticket',
                'order' => 16,
                'pages' => [
                    'index' => [
                        'display' => 'Cupones de la tienda',
                        'route' => 'coupons.index',
                        'actions' => ['view', 'create', 'edit', 'delete'],
                        'labels' => [
                            'view' => 'Ver los cupones',
                            'create' => 'Crear cupones',
                            'edit' => 'Editar y apagar cupones',
                            'delete' => 'Eliminar cupones',
                        ],
                    ],
                ],
            ],

            'promotions' => [
                'display' => 'Promociones',
                'icon' => 'Tag',
                'order' => 16,
                'pages' => [
                    'index' => [
                        'display' => 'Promociones de la tienda',
                        'route' => 'promotions.index',
                        'actions' => ['view', 'create', 'edit', 'delete'],
                        'labels' => [
                            'view' => 'Ver las promociones',
                            'create' => 'Crear promociones',
                            'edit' => 'Editar y activar promociones',
                            'delete' => 'Eliminar promociones',
                        ],
                    ],
                ],
            ],

            'storefront' => [
                'display' => 'Tienda en linea',
                'icon' => 'Storefront',
                'order' => 17,
                'pages' => [
                    'catalog' => [
                        'display' => 'Catalogo publicado',
                        'route' => 'storefront.catalog.index',
                        'actions' => ['manage'],
                        'labels' => [
                            'manage' => 'Decidir que se publica en la tienda en linea',
                        ],
                    ],
                    'editor' => [
                        'display' => 'Editor de la tienda',
                        'route' => 'storefront.editor.index',
                        'actions' => ['view', 'content', 'appearance', 'settings', 'publish', 'history', 'copy_store'],
                        'labels' => [
                            'view' => 'Abrir el editor de la tienda',
                            'content' => 'Editar bloques y contenido',
                            'appearance' => 'Editar el aspecto: marca, color y tipografia',
                            'settings' => 'Editar funciones: domicilio, franjas y cupones',
                            'publish' => 'Publicar cambios',
                            'history' => 'Ver el historial y revertir',
                            'copy_store' => 'Copiar la configuracion a otras sedes',
                        ],
                    ],
                    // Los correos del formulario de novedades del pie: datos personales, por eso aparte.
                    'newsletter' => [
                        'display' => 'Suscriptores',
                        'route' => 'storefront.newsletter.index',
                        'actions' => ['view'],
                        'labels' => [
                            'view' => 'Ver y descargar los correos suscritos a las novedades',
                        ],
                    ],
                ],
            ],

            'settings' => [
                'display' => 'Ajustes',
                'icon' => 'Gear',
                'order' => 18,
                'pages' => [
                    'index' => [
                        'display' => 'Ajustes de la empresa',
                        'route' => 'settings.index',
                        'actions' => ['view', 'edit', 'brand', 'pos_layout'],
                        'labels' => [
                            'view' => 'Ver los ajustes',
                            'edit' => 'Cambiar reglas de caja y crédito',
                            'brand' => 'Cambiar marca, apariencia y menú',
                            'pos_layout' => 'Cambiar la caja y el tiquete',
                        ],
                    ],
                    // Aparte de `settings.index.edit`: quien cambia el redondeo no tiene por que
                    // cambiar la tarjeta con que se cobra la empresa.
                    'membership' => [
                        'display' => 'Membresía',
                        'route' => 'settings.index',
                        'actions' => ['view', 'manage_payment'],
                        'labels' => [
                            'view' => 'Ver el plan, los vencimientos y los cobros',
                            'manage_payment' => 'Pagar, cambiar la tarjeta, la renovación y el plan',
                        ],
                    ],
                ],
            ],

            // Los medios con que la empresa cobra sus ventas: los manuales y sus propias pasarelas.
            // No confundir con la tarjeta con que la empresa paga su membresia (`settings.membership`).
            'payment_methods' => [
                'display' => 'Medios de pago',
                'icon' => 'Wallet',
                'order' => 20,
                'pages' => [
                    'index' => [
                        'display' => 'Medios de pago',
                        'route' => 'payment_methods.index',
                        'actions' => ['view', 'create', 'edit', 'delete'],
                        'labels' => [
                            'view' => 'Ver los medios de pago',
                            'create' => 'Crear medios de pago',
                            'edit' => 'Editar, ordenar, encender y apagar medios de pago',
                            'delete' => 'Eliminar medios de pago sin uso',
                        ],
                    ],
                    'gateways' => [
                        'display' => 'Pasarelas',
                        'route' => 'payment_methods.gateways.index',
                        'actions' => ['view', 'edit', 'test'],
                        'labels' => [
                            'view' => 'Ver las cuentas de pasarela',
                            'edit' => 'Conectar cuentas, cambiar llaves y rotar el token del webhook',
                            'test' => 'Probar la conexión con la pasarela',
                        ],
                    ],
                    'terminals' => [
                        'display' => 'Datáfonos integrados',
                        'route' => 'payment_methods.terminals.index',
                        'actions' => ['view', 'edit'],
                        'labels' => [
                            'view' => 'Ver los datáfonos',
                            'edit' => 'Registrar datáfonos y enviar cobros de prueba',
                        ],
                    ],
                    'transactions' => [
                        'display' => 'Transacciones',
                        'route' => 'payment_methods.transactions.index',
                        'actions' => ['view', 'reconcile'],
                        'labels' => [
                            'view' => 'Ver las transacciones por pasarela',
                            'reconcile' => 'Conciliar pagos: aplicarlos, abonarlos o registrar su reembolso',
                        ],
                    ],
                ],
            ],

            'payment_terms' => [
                'display' => 'Plazos de pago',
                'icon' => 'CalendarCheck',
                'order' => 21,
                'pages' => [
                    'index' => [
                        'display' => 'Plazos de pago',
                        'route' => 'payment_terms.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate'],
                        'labels' => [
                            'view' => 'Ver los plazos de pago',
                            'create' => 'Crear plazos',
                            'edit' => 'Editar plazos y elegir el de por defecto',
                            'deactivate' => 'Desactivar plazos',
                        ],
                    ],
                ],
            ],

            'stores' => [
                'display' => 'Sedes',
                'icon' => 'Buildings',
                'order' => 19,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de sedes',
                        'route' => 'stores.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'import'],
                        'labels' => [
                            'view' => 'Ver sedes',
                            'create' => 'Crear sedes',
                            'edit' => 'Editar sedes',
                            'delete' => 'Eliminar sedes',
                            'import' => 'Importar sedes desde un archivo',
                        ],
                    ],
                    'warehouses' => [
                        'display' => 'Bodegas de la sede',
                        'route' => 'stores.warehouses.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate'],
                        'labels' => [
                            'view' => 'Ver las bodegas de una sede',
                            'create' => 'Crear bodegas',
                            'edit' => 'Editar bodegas y decidir cual vende',
                            'deactivate' => 'Desactivar bodegas',
                        ],
                    ],
                ],
            ],

            // La plataforma: solo el super usuario. No se asigna a usuarios de empresa.
            'platform' => [
                'display' => 'Plataforma',
                'icon' => 'Globe',
                'order' => 30,
                'super_admin_only' => true,
                'pages' => [
                    'users' => [
                        'display' => 'Usuarios de la plataforma',
                        'route' => 'platform.users.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate', 'reset_password'],
                        'labels' => [
                            'view' => 'Ver los super usuarios',
                            'create' => 'Crear super usuarios',
                            'edit' => 'Editar sus datos',
                            'deactivate' => 'Activar y desactivar super usuarios',
                            'reset_password' => 'Restablecer su contraseña',
                        ],
                    ],
                    'company_users' => [
                        'display' => 'Usuarios de las empresas',
                        'route' => 'platform.company_users.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate', 'reset_password'],
                        'labels' => [
                            'view' => 'Ver los usuarios de todas las empresas',
                            'create' => 'Crear usuarios en cualquier empresa',
                            'edit' => 'Editar sus datos, rol y sedes',
                            'deactivate' => 'Activar y desactivar usuarios de empresa',
                            'reset_password' => 'Restablecer su contraseña',
                        ],
                    ],
                    'leads' => [
                        'display' => 'Solicitudes de contratación',
                        'route' => 'platform.leads.index',
                        'actions' => ['view', 'manage'],
                        'labels' => ['view' => 'Ver las solicitudes', 'manage' => 'Gestionar las solicitudes'],
                    ],
                    'plans' => [
                        'display' => 'Planes de membresía',
                        'route' => 'platform.plans.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate'],
                    ],
                    'billing_cycles' => [
                        'display' => 'Ciclos de cobro',
                        'route' => 'platform.billing_cycles.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate'],
                    ],
                    'business_categories' => [
                        'display' => 'Clasificaciones de empresa',
                        'route' => 'platform.business_categories.index',
                        'actions' => ['view', 'create', 'edit', 'deactivate'],
                        'labels' => [
                            'view' => 'Ver las clasificaciones',
                            'create' => 'Crear clasificaciones',
                            'edit' => 'Editar clasificaciones',
                            'deactivate' => 'Activar y desactivar clasificaciones',
                        ],
                    ],
                    'gateway' => [
                        'display' => 'Pasarela de pagos',
                        'route' => 'platform.gateway.index',
                        'actions' => ['view', 'edit'],
                    ],
                    'maps' => [
                        'display' => 'Mapas',
                        'route' => 'platform.maps.index',
                        'actions' => ['view', 'edit'],
                        'labels' => ['view' => 'Ver el proveedor de mapas', 'edit' => 'Cambiar el proveedor y sus llaves'],
                    ],
                    'landing' => [
                        'display' => 'Landing pública',
                        'route' => 'platform.landing.index',
                        'actions' => ['view', 'edit', 'publish'],
                    ],
                    'store_templates' => [
                        'display' => 'Plantillas de tienda',
                        'route' => 'platform.store_templates.index',
                        'actions' => ['view', 'edit', 'publish'],
                        'labels' => ['view' => 'Ver las plantillas', 'edit' => 'Armar las plantillas', 'publish' => 'Publicarlas y elegir la de por defecto'],
                    ],
                    'imports' => [
                        'display' => 'Carga masiva',
                        'route' => 'platform.imports.index',
                        'actions' => ['view', 'run'],
                        'labels' => ['view' => 'Ver la carga masiva', 'run' => 'Cargar archivos para cualquier empresa'],
                    ],
                ],
            ],

            'companies' => [
                'display' => 'Empresas',
                'icon' => 'Bank',
                'order' => 20,
                'super_admin_only' => true,
                'pages' => [
                    'index' => [
                        'display' => 'Listado de empresas',
                        'route' => 'companies.index',
                        'actions' => ['view', 'create', 'edit', 'delete', 'switch', 'entitlements'],
                        'labels' => [
                            'view' => 'Ver empresas',
                            'create' => 'Crear empresas',
                            'edit' => 'Editar empresas',
                            'delete' => 'Eliminar empresas',
                            'switch' => 'Cambiar la empresa activa',
                            'entitlements' => 'Sumar o quitar funciones del plan y poner precio especial',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function flatPermissions(): array
    {
        $permissions = [];

        foreach (self::getPermissionMatrix() as $module => $config) {
            foreach ($config['pages'] as $page => $pageConfig) {
                foreach ($pageConfig['actions'] as $action) {
                    $permissions[] = "{$module}.{$page}.{$action}";
                }
            }
        }

        return $permissions;
    }

    /**
     * Permisos asignables a un usuario de empresa: todo menos los modulos de super admin.
     *
     * @return list<string>
     */
    public static function assignablePermissions(): array
    {
        $permissions = [];

        foreach (self::getPermissionMatrix() as $module => $config) {
            if ($config['super_admin_only'] ?? false) {
                continue;
            }
            foreach ($config['pages'] as $page => $pageConfig) {
                foreach ($pageConfig['actions'] as $action) {
                    $permissions[] = "{$module}.{$page}.{$action}";
                }
            }
        }

        return $permissions;
    }

    public static function actionLabel(string $module, string $page, string $action): string
    {
        $pageConfig = self::getPermissionMatrix()[$module]['pages'][$page] ?? null;

        return $pageConfig['labels'][$action]
            ?? self::DEFAULT_ACTION_LABELS[$action]
            ?? ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Catalogo listo para pintar el asignador: modulos, sus paginas y cada permiso con su
     * etiqueta. Se arma aqui y no en el front para que el nombre tecnico y el texto que lee
     * el administrador no se separen nunca.
     *
     * Con `$only`, solo esos permisos: el asignador de una empresa pinta lo que su plan incluye y
     * nada mas, ni deshabilitado. Un modulo o una pagina sin nada adentro no se pinta.
     *
     * @param  list<string>|null  $only
     * @return list<array<string, mixed>>
     */
    public static function catalogue(bool $includeSuperAdminOnly = false, ?array $only = null): array
    {
        $modules = [];
        $allowed = $only !== null ? array_flip($only) : null;

        foreach (self::getPermissionMatrix() as $moduleKey => $config) {
            $superAdminOnly = (bool) ($config['super_admin_only'] ?? false);

            if ($superAdminOnly && ! $includeSuperAdminOnly) {
                continue;
            }

            $groups = [];
            $count = 0;

            foreach ($config['pages'] as $pageKey => $pageConfig) {
                $permissions = [];

                foreach ($pageConfig['actions'] as $action) {
                    if ($allowed !== null && ! isset($allowed["{$moduleKey}.{$pageKey}.{$action}"])) {
                        continue;
                    }

                    $permissions[] = [
                        'name' => "{$moduleKey}.{$pageKey}.{$action}",
                        'label' => self::actionLabel($moduleKey, $pageKey, $action),
                    ];
                    $count++;
                }

                if ($permissions === []) {
                    continue;
                }

                $groups[] = [
                    'key' => $pageKey,
                    'display' => $pageConfig['display'] ?? $pageKey,
                    'permissions' => $permissions,
                ];
            }

            if ($groups === []) {
                continue;
            }

            $modules[] = [
                'key' => $moduleKey,
                'display' => $config['display'],
                'icon' => $config['icon'] ?? null,
                'order' => $config['order'] ?? 999,
                'super_admin_only' => $superAdminOnly,
                'total' => $count,
                'groups' => $groups,
            ];
        }

        usort($modules, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $modules;
    }

    public static function permissionExists(string $permission): bool
    {
        return in_array($permission, self::flatPermissions(), true);
    }

    /**
     * Plantillas de permisos de los roles por defecto.
     *
     * @return list<string>
     */
    public static function presetPermissions(string $preset): array
    {
        $permissions = match ($preset) {
            'super_admin' => self::flatPermissions(),
            'admin' => self::assignablePermissions(),
            'supervisor' => self::supervisorPermissions(),
            'cajero' => [
                'dashboard.index.view',
                'pos.sale.view',
                'pos.sale.create',
                'pos.sale.discount',
                'pos.sale.suspend',
                'registers.open.view',
                'registers.open.create',
                'registers.close.view',
                'registers.close.create',
                'registers.movements.create',
                'products.index.view',
                'customers.index.view',
                'credit.payments.create',
                'orders.index.view',
                // Ver los servicios y ajustar sus articulos en la venta; agregar uno que el
                // servicio no trae se autoriza con PIN.
                'services.index.view',
                'pos.sale.service_remove',
                'pos.sale.service_reduce',
                'pos.sale.service_increase',
                // Cobrar con el QR o el datafono de la pasarela de la empresa.
                'pos.sale.gateway',
            ],
            'bodega' => array_merge(
                [
                    'dashboard.index.view',
                    'products.index.view',
                    'products.show.view',
                    'products.attributes.view',
                    'suppliers.index.view',
                    'orders.index.view',
                    'orders.show.view',
                    'orders.show.pick',
                    // Alista pedidos que traen servicios.
                    'services.index.view',
                    // Recibe lo que el cliente devuelve y decide si vuelve al estante o es averia.
                    'sales.returns.view',
                    'sales.returns.receive',
                ],
                self::modulePermissions('inventory'),
                // La respuesta del proveedor es plata: no es de bodega.
                array_diff(self::modulePermissions('purchases'), ['purchases.returns.respond']),
            ),
            'domiciliario' => [
                'orders.index.view',
                'orders.show.view',
                'orders.show.status',
                'orders.show.collect',
            ],
            'auxiliar_contable' => array_merge(
                [
                    'dashboard.index.view',
                    'dashboard.index.sales_totals',
                    'credit.index.view',
                    'credit.index.export',
                    'credit.payments.view',
                    'registers.close.view',
                    'registers.close.view_others',
                    // Cuadrar el dia obliga a poder abrir la venta, no solo el total del informe.
                    'sales.index.view',
                    'registers.differences.view',
                    // Ver el costo sin poder abrir la ficha no serviria de nada.
                    'products.index.view',
                    'products.show.view',
                    'products.show.view_cost',
                    'services.index.view',
                    'services.show.view',
                    'services.show.view_cost',
                    // Lo que responde el proveedor por una devolucion es cartera a favor.
                    'purchases.returns.view',
                    'purchases.returns.respond',
                    // Y lo que se le devuelve al cliente es plata que sale.
                    'sales.returns.view',
                    'sales.returns.resolve',
                ],
                self::modulePermissions('reports'),
            ),
            default => [],
        };

        return array_values(array_unique($permissions));
    }

    /**
     * Todos los permisos de un modulo.
     *
     * @return list<string>
     */
    public static function modulePermissions(string $module): array
    {
        $config = self::getPermissionMatrix()[$module] ?? null;

        if ($config === null) {
            return [];
        }

        $permissions = [];

        foreach ($config['pages'] as $page => $pageConfig) {
            foreach ($pageConfig['actions'] as $action) {
                $permissions[] = "{$module}.{$page}.{$action}";
            }
        }

        return $permissions;
    }

    /**
     * Supervisor de tienda: todo menos la administracion de cuentas y unas pocas acciones
     * que son decision del administrador.
     *
     * @return list<string>
     */
    protected static function supervisorPermissions(): array
    {
        $excludedModules = ['users', 'roles', 'stores'];
        $excludedPermissions = [
            'settings.index.edit',
            // La cara de la empresa (logo, color, menu) es decision del administrador.
            'settings.index.brand',
            // Con que tarjeta y que plan se paga la plataforma, tambien.
            'settings.membership.view',
            'settings.membership.manage_payment',
            'products.index.delete',
            'credit.index.set_limit',
            // Agregar a un servicio un articulo que no trae cambia lo que se entrega: por defecto
            // solo el administrador; el cajero lo pide con PIN.
            'pos.sale.service_add',
            // Mirar el catalogo externo no cuesta nada; traerlo al propio si. Se otorga
            // despues, rol por rol, igual que la importacion de maestras.
            'products.catalog_import.run',
            'products.catalog_import.cancel',
            // Como se cobra y con que llaves es decision del administrador; el supervisor mira.
            'payment_methods.index.create',
            'payment_methods.index.edit',
            'payment_methods.index.delete',
            'payment_methods.gateways.edit',
            'payment_methods.gateways.test',
            'payment_methods.terminals.edit',
            'payment_methods.transactions.reconcile',
        ];

        return array_values(array_filter(
            self::assignablePermissions(),
            function (string $permission) use ($excludedModules, $excludedPermissions) {
                $module = explode('.', $permission)[0];

                return ! in_array($module, $excludedModules, true)
                    && ! in_array($permission, $excludedPermissions, true)
                    // Cargar una maestra completa desde un archivo es decision del
                    // administrador; se puede otorgar despues, rol por rol.
                    && ! str_ends_with($permission, '.index.import');
            }
        ));
    }
}
