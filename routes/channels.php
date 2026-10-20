<?php

use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canales de tiempo real
|--------------------------------------------------------------------------
|
| Todos son privados o de presencia: no hay ninguno publico. El nombre lleva la empresa y la
| sede adentro, y `ChannelAccess` responde las tres preguntas —empresa, sede y permiso— antes
| de dejar escuchar. Un canal que se autorice mal le transmite a otra empresa lo que pasa en
| esta, en vivo y sin dejar rastro en ningun listado.
|
| El guard es `web`: la tienda en linea usa `customer` y no escucha nada de aqui.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('company.{company}.store.{store}.orders', function (User $user, string $company, string $store) {
    return ChannelAccess::storeWithAny($user, $company, $store, ['orders.index.view']);
});

Broadcast::channel('company.{company}.store.{store}.pos', function (User $user, string $company, string $store) {
    return ChannelAccess::storeWithAny($user, $company, $store, ['pos.sale.view', 'sales.index.view']);
});

// Escuchar el canal de caja es ver los montos del turno pasar en vivo: mismo permiso que
// abrir el cierre en pantalla.
Broadcast::channel('company.{company}.store.{store}.registers', function (User $user, string $company, string $store) {
    return ChannelAccess::storeWithAny($user, $company, $store, [
        'registers.open.view', 'registers.close.view', 'registers.differences.view',
    ]);
});

// Bodega, no mostrador. Lo que el cajero necesita saber de existencias le llega por el canal
// del punto de venta: ver el kardex moverse en vivo es otra cosa, y la pide `inventory`.
Broadcast::channel('company.{company}.store.{store}.inventory', function (User $user, string $company, string $store) {
    return ChannelAccess::storeWithAny($user, $company, $store, ['inventory.index.view']);
});

// El catalogo es de la empresa: un precio nuevo lo es en todas las sedes.
Broadcast::channel('company.{company}.catalog', function (User $user, string $company) {
    return ChannelAccess::companyWithAny($user, $company, ['products.index.view']);
});

// El aviso de «cambio esto» que mantiene al dia las pestañas abiertas. Basta ser de la empresa:
// lleva solo el tema y la sede, y los datos se vuelven a pedir por la ruta de siempre, que exige
// su propio permiso.
Broadcast::channel('company.{company}.changes', function (User $user, string $company) {
    return ChannelAccess::company($user, $company);
});

// El `{import}` no se mira: quien puede ver las importaciones de su empresa puede seguir el
// avance de cualquiera de ellas, y el id ya esta acotado por la empresa del nombre del canal.
Broadcast::channel('company.{company}.imports.{import}', function (User $user, string $company) {
    return ChannelAccess::companyWithAny($user, $company, ['products.catalog_import.view']);
});

/*
 * Presencia: quien esta conectado y en que sede.
 *
 * Lo que se devuelve aqui lo ve todo el que este en el canal, asi que va lo justo para
 * saludar a alguien: nombre, iniciales y cargo. Ni correo, ni documento, ni permisos.
 */
Broadcast::channel('company.{company}.store.{store}.presence', function (User $user, string $company, string $store) {
    if (! ChannelAccess::store($user, $company, $store)) {
        return null;
    }

    return [
        'id' => $user->id,
        'name' => $user->full_name,
        'initials' => $user->initials,
        'role' => $user->primaryRole()?->label(),
        'avatar' => $user->avatar,
    ];
});
