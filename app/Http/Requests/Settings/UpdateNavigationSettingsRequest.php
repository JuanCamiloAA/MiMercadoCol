<?php

namespace App\Http\Requests\Settings;

use App\Models\Role;
use App\Settings\NavigationOverrides;
use App\Settings\SettingsSchema;
use App\Support\HomeResolver;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

/**
 * Ajustes > Menu: orden, nombres y lo oculto del menu lateral, y la pagina de inicio por rol.
 *
 * La configuracion puede ocultar, nunca mostrar: el permiso filtra despues. Y lo que contiene
 * Ajustes no se puede ocultar, o nadie podria volver a esta pantalla para deshacerlo.
 */
class UpdateNavigationSettingsRequest extends SettingsTabRequest
{
    protected function tab(): string
    {
        return SettingsSchema::TAB_NAVIGATION;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('panel.navigation')) {
                    return;
                }

                foreach (NavigationOverrides::errors($this->input('panel.navigation')) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
            function (Validator $validator) {
                $homes = $this->input('panel.home_by_role');

                if ($validator->errors()->has('panel.home_by_role') || ! is_array($homes)) {
                    return;
                }

                $roles = $this->companyRoles();

                foreach ($homes as $role => $routeName) {
                    if (! is_string($role) || ! in_array($role, $roles, true)) {
                        $validator->errors()->add('panel.home_by_role', 'Uno de los roles ya no existe en la empresa. Recarga la página.');

                        return;
                    }

                    if ($routeName !== null && (! is_string($routeName) || ! array_key_exists($routeName, HomeResolver::PAGES))) {
                        $validator->errors()->add('panel.home_by_role', 'Esa página no se puede elegir como inicio.');

                        return;
                    }
                }
            },
        ];
    }

    public function values(): array
    {
        $navigation = $this->input('panel.navigation');
        $homes = array_filter(
            (array) $this->input('panel.home_by_role', []),
            // El inicio por defecto no se guarda: es lo que pasa sin configurar nada.
            fn ($routeName) => is_string($routeName) && $routeName !== HomeResolver::DEFAULT,
        );

        return [
            'panel.navigation' => is_array($navigation) ? NavigationOverrides::sanitize($navigation) : [],
            'panel.home_by_role' => $homes,
        ];
    }

    /**
     * @return list<string>
     */
    protected function companyRoles(): array
    {
        $companyId = TenantContext::requireCompanyId();

        return Role::query()->where('company_id', $companyId)->pluck('name')->all();
    }
}
