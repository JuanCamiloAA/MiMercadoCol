<?php

namespace App\Landing\Blocks;

use App\Landing\Demo\DemoWorkspace;

/**
 * Una ventana del panel con datos de ejemplo, para que quien visita lo pruebe: cobrar en la caja,
 * mover un pedido, buscar un articulo. Los modulos los escoge quien arma la landing, en su orden.
 * Lo que se hace en la demo se queda en el navegador: no hay rutas detras ni se guarda nada
 * (`DemoWorkspace`).
 */
class AppDemoBlock extends LandingBlock
{
    public function type(): string
    {
        return 'app_demo';
    }

    public function label(): string
    {
        return 'Demo del sistema';
    }

    public function icon(): string
    {
        return 'Browser';
    }

    public function help(): string
    {
        return 'Una ventana del panel con datos de ejemplo que el visitante puede usar. No guarda nada.';
    }

    public function fields(): array
    {
        $modules = collect(DemoWorkspace::MODULES)->map(fn (array $module, string $key) => ['value' => $key, 'label' => $module['label']])->values()->all();
        $kinds = collect(DemoWorkspace::KINDS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values()->all();

        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'max' => 80, 'section' => 'Contenido'],
            'text' => ['type' => 'textarea', 'label' => 'Texto', 'max' => 240],
            'modules' => self::repeater('Módulos', 'módulo', [
                'module' => ['type' => 'select', 'label' => 'Módulo', 'options' => $modules, 'default' => 'dashboard'],
            ], count(DemoWorkspace::MODULES)),
            'business_name' => ['type' => 'text', 'label' => 'Nombre del negocio de ejemplo', 'max' => 40, 'section' => 'Datos de ejemplo'],
            'business_kind' => ['type' => 'select', 'label' => 'Qué vende', 'options' => $kinds, 'help' => 'Cambia los artículos, los precios y las cifras de la demo.'],
            'tour' => ['type' => 'toggle', 'label' => 'Recorrido automático', 'help' => 'Pasa sola por los módulos hasta que el visitante toca algo.', 'section' => 'Comportamiento'],
            'notifications' => ['type' => 'toggle', 'label' => 'Avisos en vivo', 'help' => 'De vez en cuando aparece un pedido o una venta nueva.'],
            'tone' => ['type' => 'select', 'label' => 'Fondo', 'section' => 'Diseño', 'options' => [
                ['value' => 'dark', 'label' => 'Oscuro'],
                ['value' => 'light', 'label' => 'Claro'],
            ]],
        ];
    }

    public function defaults(): array
    {
        return [
            'title' => 'Pruébalo aquí mismo',
            'text' => 'Así se ve tu negocio por dentro. Toca, cobra y mueve pedidos: son datos de ejemplo y no se guarda nada.',
            'modules' => [
                ['module' => 'dashboard'],
                ['module' => 'pos'],
                ['module' => 'orders'],
                ['module' => 'products'],
                ['module' => 'finance'],
            ],
            'business_name' => 'Mercado La Esquina',
            'business_kind' => 'market',
            'tour' => true,
            'notifications' => true,
            'tone' => 'dark',
        ];
    }
}
