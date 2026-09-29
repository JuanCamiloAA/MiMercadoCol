<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingController;
use App\Services\SettingsService;
use App\Settings\NavigationOverrides;
use App\Settings\SettingsSchema;
use Tests\TestCase;

/**
 * Los defectos viven en `SettingsService::DEFAULTS` y los metadatos en `SettingsSchema`. Si una
 * clave esta en uno y no en el otro, o se guarda sin validar, o se valida algo que no existe.
 */
class SettingsSchemaTest extends TestCase
{
    public function test_defaults_y_esquema_tienen_las_mismas_claves(): void
    {
        $defaults = array_keys(SettingsService::DEFAULTS);
        $schema = array_keys(SettingsSchema::definitions());

        sort($defaults);
        sort($schema);

        $this->assertSame($defaults, $schema);
    }

    public function test_toda_clave_no_gestionada_tiene_reglas(): void
    {
        foreach (SettingsSchema::definitions() as $key => $definition) {
            if ($definition['managed'] ?? false) {
                continue;
            }

            $this->assertNotEmpty($definition['rules'] ?? [], "{$key} no tiene reglas.");
        }
    }

    public function test_toda_clave_con_alcance_de_sede_es_del_tiquete(): void
    {
        $this->assertNotEmpty(SettingsSchema::storeScopedKeys());

        foreach (SettingsSchema::storeScopedKeys() as $key) {
            $this->assertSame(SettingsSchema::TAB_RECEIPT, SettingsSchema::definitions()[$key]['tab'], "{$key} admite sede pero no es del tiquete.");
        }
    }

    public function test_las_claves_caben_en_la_columna(): void
    {
        foreach (array_keys(SettingsService::DEFAULTS) as $key) {
            $this->assertLessThanOrEqual(60, strlen($key), "{$key} no cabe en settings.key.");
        }
    }

    public function test_los_defectos_pasan_sus_propias_reglas(): void
    {
        foreach (array_keys(SettingController::TABS) as $tab) {
            $data = [];

            foreach (SettingsSchema::keysFor($tab) as $key) {
                [$prefix, $name] = explode('.', $key, 2);
                $data[$prefix][$name] = SettingsService::DEFAULTS[$key];
            }

            $validator = validator($data, SettingsSchema::rulesFor($tab));

            $this->assertFalse($validator->fails(), "Los defectos de {$tab} no pasan: ".json_encode($validator->errors()->all()));
        }
    }

    /**
     * Lo bloqueado en el servidor tiene que ser, en `navigation.ts`, lo que contiene Ajustes: si
     * alguien mueve el enlace a otro grupo, el candado quedaria protegiendo otra cosa.
     */
    public function test_el_enlace_de_ajustes_sigue_dentro_de_lo_bloqueado(): void
    {
        $source = file_get_contents(resource_path('js/lib/navigation.ts'));

        $admin = strpos($source, "key: 'admin'");
        $company = strpos($source, "key: 'company'", (int) $admin);
        $settings = strpos($source, "routeName: 'settings.index'", (int) $company);
        $nextSection = strpos($source, "        key: '", (int) $company + 1);

        $this->assertNotFalse($admin, 'La seccion admin ya no existe en navigation.ts.');
        $this->assertNotFalse($company, 'El grupo company ya no esta dentro de admin.');
        $this->assertNotFalse($settings, 'El enlace de Ajustes ya no esta dentro de company.');
        $this->assertTrue($nextSection === false || $settings < $nextSection, 'El enlace de Ajustes salio de la seccion admin.');

        $this->assertSame(['admin', 'company', 'settings.index'], NavigationOverrides::LOCKED);
        $this->assertStringContainsString("export const LOCKED_NAVIGATION = ['admin', 'company', 'settings.index'];", $source);
    }
}
