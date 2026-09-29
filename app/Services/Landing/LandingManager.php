<?php

namespace App\Services\Landing;

use App\Landing\LandingBlockCatalogue;
use App\Models\LandingBlock;
use App\Models\LandingVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El borrador y las publicaciones de la landing.
 *
 * Lo que se edita son las filas de `landing_blocks` (el borrador). El publico ve la ultima
 * version publicada, que es un retrato completo: borrar o reordenar en el borrador no toca lo
 * publicado hasta que alguien publica. Restaurar una version la vuelve borrador y la publica
 * como version nueva: la historia no se reescribe.
 */
class LandingManager
{
    /**
     * @return Collection<int, LandingBlock>
     */
    public function blocks(): Collection
    {
        return LandingBlock::query()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Si la landing nunca se armo, la arma con los bloques por defecto y la publica. Asi `/`
     * nunca queda vacia, ni despues de `migrate:fresh`.
     */
    public function ensureSeeded(?User $user = null): void
    {
        if (LandingBlock::query()->exists() || LandingVersion::query()->exists()) {
            return;
        }

        DB::transaction(function () use ($user) {
            foreach (array_values(LandingBlockCatalogue::all()) as $position => $definition) {
                LandingBlock::query()->create([
                    'type' => $definition->type(),
                    'position' => $position,
                    'is_visible' => true,
                    'data' => $definition->defaults(),
                ]);
            }

            $this->publish($user, 'Landing inicial');
        });
    }

    public function add(string $type): LandingBlock
    {
        $definition = LandingBlockCatalogue::for($type);

        if ($definition->unique() && LandingBlock::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages(['type' => "Solo puede haber un bloque de {$definition->label()}."]);
        }

        return DB::transaction(function () use ($definition) {
            // Nuevo al final, pero antes del pie de pagina.
            $footer = LandingBlock::query()->where('type', 'footer')->first();
            $position = $footer ? $footer->position : ((int) LandingBlock::query()->max('position') + 1);

            LandingBlock::query()->where('position', '>=', $position)->increment('position');

            return LandingBlock::query()->create([
                'type' => $definition->type(),
                'position' => $position,
                'is_visible' => true,
                'data' => $definition->defaults(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LandingBlock $block, array $data): LandingBlock
    {
        $block->update(['data' => LandingBlockCatalogue::for($block->type)->normalize($data)]);

        return $block;
    }

    public function duplicate(LandingBlock $block): LandingBlock
    {
        $definition = LandingBlockCatalogue::for($block->type);

        if ($definition->unique()) {
            throw ValidationException::withMessages(['block' => "Solo puede haber un bloque de {$definition->label()}."]);
        }

        return DB::transaction(function () use ($block) {
            LandingBlock::query()->where('position', '>', $block->position)->increment('position');

            return LandingBlock::query()->create([
                'type' => $block->type,
                'position' => $block->position + 1,
                'is_visible' => $block->is_visible,
                'data' => $block->data,
            ]);
        });
    }

    public function remove(LandingBlock $block): void
    {
        $definition = LandingBlockCatalogue::for($block->type);

        if (! $definition->deletable()) {
            throw ValidationException::withMessages(['block' => "El bloque de {$definition->label()} es obligatorio: no se puede borrar".($definition->hideable() ? ', solo ocultar.' : '.')]);
        }

        $block->delete();
    }

    public function setVisible(LandingBlock $block, bool $visible): void
    {
        $definition = LandingBlockCatalogue::for($block->type);

        if (! $visible && ! $definition->hideable()) {
            throw ValidationException::withMessages(['block' => "El bloque de {$definition->label()} no se puede ocultar."]);
        }

        $block->update(['is_visible' => $visible]);
    }

    /**
     * Reordena. El encabezado va siempre primero y el pie siempre al final.
     *
     * @param  list<string>  $uuids
     */
    public function reorder(array $uuids): void
    {
        DB::transaction(function () use ($uuids) {
            $blocks = LandingBlock::query()->get()->keyBy('uuid');
            $ordered = collect($uuids)->filter(fn ($uuid) => $blocks->has($uuid))->unique()->values();

            // Los que no vinieron en la lista conservan su orden relativo al final.
            $rest = $blocks->keys()->diff($ordered)->sortBy(fn ($uuid) => $blocks[$uuid]->position)->values();
            $all = $ordered->merge($rest);

            $header = $all->filter(fn ($uuid) => $blocks[$uuid]->type === 'header');
            $footer = $all->filter(fn ($uuid) => $blocks[$uuid]->type === 'footer');
            $middle = $all->diff($header)->diff($footer);

            foreach ($header->merge($middle)->merge($footer)->values() as $position => $uuid) {
                $blocks[$uuid]->update(['position' => $position]);
            }
        });
    }

    /**
     * El borrador tal como quedaria publicado.
     *
     * @return list<array{uuid: string, type: string, is_visible: bool, data: array<string, mixed>}>
     */
    public function draftSnapshot(): array
    {
        return $this->blocks()
            ->filter(fn (LandingBlock $block) => LandingBlockCatalogue::has($block->type))
            ->map(fn (LandingBlock $block) => [
                'uuid' => $block->uuid,
                'type' => $block->type,
                'is_visible' => (bool) $block->is_visible,
                'data' => LandingBlockCatalogue::for($block->type)->normalize($block->data ?? []),
            ])
            ->values()
            ->all();
    }

    public function latestVersion(): ?LandingVersion
    {
        return LandingVersion::query()->orderByDesc('number')->first();
    }

    /**
     * Lo que ve el publico: la ultima version, solo los bloques visibles.
     *
     * @return list<array{uuid: string, type: string, is_visible: bool, data: array<string, mixed>}>
     */
    public function publishedBlocks(): array
    {
        $snapshot = $this->latestVersion()?->snapshot ?? [];

        return array_values(array_filter($snapshot, fn ($block) => ($block['is_visible'] ?? false) && LandingBlockCatalogue::has($block['type'] ?? '')));
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draftSnapshot() != ($this->latestVersion()?->snapshot ?? []);
    }

    /**
     * Los problemas que impiden publicar: faltan los obligatorios.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        $types = $this->blocks()->pluck('type')->all();
        $issues = [];

        foreach (LandingBlockCatalogue::REQUIRED as $type) {
            if (! in_array($type, $types, true)) {
                $issues[] = 'Falta el bloque de '.LandingBlockCatalogue::for($type)->label().'.';
            }
        }

        return $issues;
    }

    public function publish(?User $user, ?string $note = null): LandingVersion
    {
        $issues = $this->issues();

        if ($issues !== []) {
            throw ValidationException::withMessages(['publish' => $issues[0]]);
        }

        return DB::transaction(function () use ($user, $note) {
            $snapshot = $this->draftSnapshot();

            $version = LandingVersion::query()->create([
                'number' => (int) LandingVersion::query()->lockForUpdate()->max('number') + 1,
                'snapshot' => $snapshot,
                'note' => $note ? mb_substr($note, 0, 160) : null,
                'published_by' => $user?->id,
                'published_at' => now(),
            ]);

            foreach ($this->blocks() as $position => $block) {
                $block->update([
                    'published_data' => $block->data,
                    'published_visible' => $block->is_visible,
                    'published_position' => $position,
                ]);
            }

            return $version;
        });
    }

    /**
     * Vuelve el borrador a una version y la publica como version nueva.
     */
    public function restore(LandingVersion $version, User $user): LandingVersion
    {
        return DB::transaction(function () use ($version, $user) {
            LandingBlock::query()->delete();

            foreach (array_values($version->snapshot ?? []) as $position => $block) {
                if (! LandingBlockCatalogue::has($block['type'] ?? '')) {
                    continue;
                }

                LandingBlock::query()->create([
                    'uuid' => $block['uuid'] ?? null,
                    'type' => $block['type'],
                    'position' => $position,
                    'is_visible' => (bool) ($block['is_visible'] ?? true),
                    'data' => $block['data'] ?? [],
                ]);
            }

            return $this->publish($user, "Restaurada la versión {$version->number}");
        });
    }
}
