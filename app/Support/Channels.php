<?php

namespace App\Support;

/**
 * Los nombres de los canales, en un solo sitio.
 *
 * El nombre **es** la frontera de seguridad: lleva la empresa y la sede adentro, y de ahi las
 * saca la autorizacion. Armarlos a mano en cada evento terminaria, tarde o temprano, en un
 * canal sin empresa que le transmite a todo el mundo.
 */
final class Channels
{
    /** Pedidos de una sede: los que entran por la tienda y los que cambian de estado. */
    public static function orders(int $companyId, int $storeId): string
    {
        return "company.{$companyId}.store.{$storeId}.orders";
    }

    /** Punto de venta: ventas cerradas, articulos que cambian de precio o de existencia. */
    public static function pos(int $companyId, int $storeId): string
    {
        return "company.{$companyId}.store.{$storeId}.pos";
    }

    /** Cajas: apertura, cierre, movimientos de efectivo y diferencias. */
    public static function registers(int $companyId, int $storeId): string
    {
        return "company.{$companyId}.store.{$storeId}.registers";
    }

    /** Existencias de una sede. */
    public static function inventory(int $companyId, int $storeId): string
    {
        return "company.{$companyId}.store.{$storeId}.inventory";
    }

    /** Catalogo: es de la empresa, no de la sede. Un precio nuevo lo es en todas. */
    public static function catalog(int $companyId): string
    {
        return "company.{$companyId}.catalog";
    }

    /** Avance de una importacion de catalogo. */
    public static function import(int $companyId, int $importId): string
    {
        return "company.{$companyId}.imports.{$importId}";
    }

    /**
     * «Cambio esto»: el aviso que mantiene al dia las pestañas abiertas (`ResourcesChanged`).
     * Lleva solo el tema y la sede, nunca datos.
     */
    public static function changes(int $companyId): string
    {
        return "company.{$companyId}.changes";
    }

    /** Quien esta conectado y en que sede. Es el canal de presencia. */
    public static function presence(int $companyId, int $storeId): string
    {
        return "company.{$companyId}.store.{$storeId}.presence";
    }
}
