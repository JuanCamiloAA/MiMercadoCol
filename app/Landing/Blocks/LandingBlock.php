<?php

namespace App\Landing\Blocks;

/**
 * Un tipo de bloque de la landing de plataforma.
 *
 * Como en la tienda: el editor arma el formulario solo con `fields()`, el servidor guarda solo
 * lo que declara `defaults()`, y el front lo pinta en `LandingBlocks.tsx`. Los datos que vienen
 * de la base (sedes, empresas, planes) no se escriben aqui: los resuelve `LandingRenderer`.
 *
 * Tipos de campo: text · textarea · url · toggle · select · image · icon · color · repeater
 */
abstract class LandingBlock
{
    abstract public function type(): string;

    abstract public function label(): string;

    /** Icono de Phosphor del editor, sin el sufijo `Icon`. */
    abstract public function icon(): string;

    abstract public function help(): string;

    /** @return array<string, array<string, mixed>> */
    abstract public function fields(): array;

    /** @return array<string, mixed> */
    abstract public function defaults(): array;

    /** Solo puede haber uno en la pagina. */
    public function unique(): bool
    {
        return false;
    }

    /** Se puede borrar. Los obligatorios no. */
    public function deletable(): bool
    {
        return true;
    }

    /** Se puede ocultar. */
    public function hideable(): bool
    {
        return true;
    }

    /** Advertencia al ocultarlo, si la hay. */
    public function hideWarning(): ?string
    {
        return null;
    }

    /**
     * Deja los datos con la forma exacta del tipo: descarta lo que sobra y completa lo que falta.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $clean = [];

        foreach ($this->defaults() as $key => $default) {
            $value = array_key_exists($key, $data) ? $data[$key] : $default;
            $field = $this->fields()[$key] ?? null;
            $clean[$key] = $field ? $this->cleanValue($field, $value, $default) : $value;
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCatalogue(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'help' => $this->help(),
            'fields' => $this->fields(),
            'defaults' => $this->defaults(),
            'unique' => $this->unique(),
            'deletable' => $this->deletable(),
            'hideable' => $this->hideable(),
            'hide_warning' => $this->hideWarning(),
        ];
    }

    /**
     * Un valor con el tipo de su campo. Texto recortado a su maximo, URL sin esquemas raros,
     * listas con sus subcampos: la landing es publica y se pinta tal cual.
     *
     * @param  array<string, mixed>  $field
     */
    protected function cleanValue(array $field, mixed $value, mixed $default): mixed
    {
        return match ($field['type']) {
            'text', 'textarea' => mb_substr(trim((string) ($value ?? '')), 0, (int) ($field['max'] ?? 500)),
            'url' => $this->cleanUrl($value),
            'image' => is_string($value) && preg_match('#^landing/[A-Za-z0-9/_\-.]+$#', $value) ? $value : null,
            'toggle' => (bool) $value,
            'select' => in_array($value, array_column($field['options'] ?? [], 'value'), true) ? $value : $default,
            'icon' => in_array($value, (array) config('landing_icons'), true) ? $value : $default,
            'color' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $default,
            'repeater' => $this->cleanItems($field, $value),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array<string, mixed>>
     */
    protected function cleanItems(array $field, mixed $value): array
    {
        $items = [];

        foreach (array_slice(is_array($value) ? array_values($value) : [], 0, (int) ($field['max_items'] ?? 12)) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($field['item'] as $key => $sub) {
                $row[$key] = $this->cleanValue($sub, $item[$key] ?? null, $sub['default'] ?? null);
            }

            $items[] = $row;
        }

        return $items;
    }

    /**
     * Enlaces internos (`/contratar`, `#tiendas`), `mailto:`, `tel:`, `https://` y `http://`.
     * Nada de `javascript:` ni esquemas raros en una pagina publica.
     */
    protected function cleanUrl(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '') {
            return '';
        }

        return preg_match('#^(/(?!/)|\#|https?://|mailto:|tel:)#i', $url) ? mb_substr($url, 0, 300) : '';
    }

    /** @return array<string, mixed> */
    protected static function repeater(string $label, string $singular, array $item, int $max = 8): array
    {
        return ['type' => 'repeater', 'label' => $label, 'singular' => $singular, 'max_items' => $max, 'item' => $item];
    }
}
