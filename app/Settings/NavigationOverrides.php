<?php

namespace App\Settings;

/**
 * Lo que la empresa cambia del menu lateral: orden, nombres y lo oculto.
 *
 * Solo se guardan diferencias. El catalogo de lo que existe sigue en `resources/js/lib/navigation.ts`
 * (los iconos son componentes y no viajan por JSON), y alla `applyNavigationOverrides` aplica esto.
 * Lo desconocido se ignora y lo nuevo aparece en su sitio: una pantalla que se agregue manana se
 * ve sin reconfigurar nada.
 */
final class NavigationOverrides
{
    /**
     * Lo que no se puede ocultar: la seccion y el grupo donde vive Ajustes, y el enlace mismo.
     * Si se ocultaran, nadie podria volver a esta pantalla para deshacerlo. `NavigationLockTest`
     * verifica que en `navigation.ts` el enlace siga dentro de estas claves.
     */
    public const LOCKED = ['admin', 'company', 'settings.index'];

    /** Clave de seccion, de grupo o `routeName` de un enlace. */
    public const KEY_PATTERN = '/^[a-z0-9_.]+$/';

    public const LABEL_MAX = 30;

    /** Tope de elementos por lista: el menu entero tiene unas cuarenta entradas. */
    public const MAX_ITEMS = 200;

    public const SECTIONS = ['section_order', 'entry_order', 'labels', 'hidden'];

    /** Como se nombra lo bloqueado cuando alguien intenta ocultarlo. */
    public const LOCKED_MESSAGES = [
        'admin' => 'Ajustes vive en Administración: si la ocultas, nadie podría volver a esta pantalla para deshacerlo.',
        'company' => 'Ajustes vive en Empresa: si lo ocultas, nadie podría volver a esta pantalla para deshacerlo.',
        'settings.index' => 'Ajustes no se puede ocultar: nadie podría volver a esta pantalla para deshacerlo.',
    ];

    /**
     * Revisa la forma de lo que llega. Devuelve los errores en espanol, por campo.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $input): array
    {
        if (! is_array($input)) {
            return ['panel.navigation' => 'El menú no tiene el formato esperado.'];
        }

        $errors = [];

        foreach (array_keys($input) as $section) {
            if (! in_array($section, self::SECTIONS, true)) {
                $errors['panel.navigation'] = 'El menú no tiene el formato esperado.';
            }
        }

        foreach (['section_order', 'hidden'] as $list) {
            if (isset($input[$list]) && ! self::isKeyList($input[$list])) {
                $errors["panel.navigation.{$list}"] = 'El menú no tiene el formato esperado.';
            }
        }

        if (isset($input['entry_order'])) {
            if (! is_array($input['entry_order'])) {
                $errors['panel.navigation.entry_order'] = 'El menú no tiene el formato esperado.';
            } else {
                foreach ($input['entry_order'] as $section => $keys) {
                    if (! is_string($section) || ! preg_match(self::KEY_PATTERN, $section) || ! self::isKeyList($keys)) {
                        $errors['panel.navigation.entry_order'] = 'El menú no tiene el formato esperado.';
                    }
                }
            }
        }

        if (isset($input['labels'])) {
            if (! is_array($input['labels'])) {
                $errors['panel.navigation.labels'] = 'El menú no tiene el formato esperado.';
            } else {
                foreach ($input['labels'] as $key => $label) {
                    if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key) || ! (is_string($label) || $label === null)) {
                        $errors['panel.navigation.labels'] = 'El menú no tiene el formato esperado.';
                    } elseif (is_string($label) && mb_strlen(trim($label)) > self::LABEL_MAX) {
                        $errors['panel.navigation.labels'] = "«{$label}» es muy largo: el nombre en el menú admite máximo ".self::LABEL_MAX.' caracteres.';
                    }
                }
            }
        }

        if (is_array($input['hidden'] ?? null)) {
            foreach (self::LOCKED as $locked) {
                if (in_array($locked, $input['hidden'], true)) {
                    $errors['panel.navigation.hidden'] = self::LOCKED_MESSAGES[$locked];
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Lo que se guarda: sin nombres vacios, sin repetidos y sin secciones que no dicen nada.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $clean = [];

        if (! empty($input['section_order'])) {
            $clean['section_order'] = array_values(array_unique($input['section_order']));
        }

        foreach ($input['entry_order'] ?? [] as $section => $keys) {
            if (! empty($keys)) {
                $clean['entry_order'][$section] = array_values(array_unique($keys));
            }
        }

        foreach ($input['labels'] ?? [] as $key => $label) {
            $label = is_string($label) ? trim($label) : '';

            if ($label !== '') {
                $clean['labels'][$key] = $label;
            }
        }

        if (! empty($input['hidden'])) {
            $clean['hidden'] = array_values(array_unique(array_diff($input['hidden'], self::LOCKED)));
        }

        return array_filter($clean, fn ($value) => $value !== []);
    }

    protected static function isKeyList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_ITEMS) {
            return false;
        }

        foreach ($value as $key) {
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                return false;
            }
        }

        return true;
    }
}
