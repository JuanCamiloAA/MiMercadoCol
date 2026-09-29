<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Store;
use App\Models\User;

/**
 * Lo que deja el aprovisionamiento: la empresa, su administrador, su primera sede y, si se
 * genero, la contrasena temporal (para mostrarla una sola vez).
 */
final class ProvisionResult
{
    public function __construct(
        public readonly Company $company,
        public readonly User $admin,
        public readonly Store $store,
        public readonly ?string $temporaryPassword,
    ) {}
}
