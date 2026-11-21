<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Platform\PlatformSnapshot;
use Illuminate\Console\Command;

class PlatformSnapshotCommand extends Command
{
    protected $signature = 'platform:snapshot
        {action : export (de esta base a database/data/platform_snapshot) o import (de esa carpeta a esta base)}
        {--author= : Correo del super usuario que firma lo importado; por defecto, el primero que haya}';

    protected $description = 'Exporta o importa los datos de plataforma: planes, ciclos, clasificaciones, ajustes, landing y plantillas de tienda';

    public function handle(PlatformSnapshot $snapshot): int
    {
        return match ($this->argument('action')) {
            'export' => $this->export($snapshot),
            'import' => $this->import($snapshot),
            default => $this->invalid(),
        };
    }

    private function export(PlatformSnapshot $snapshot): int
    {
        $result = $snapshot->export();

        $this->info(sprintf('Exportado a %s: %d archivos (%.1f MB).', PlatformSnapshot::path(), $result['files'], $result['bytes'] / 1048576));

        foreach ($result['missing'] as $file) {
            $this->warn("No está en el disco y no viaja: {$file}");
        }

        return self::SUCCESS;
    }

    private function import(PlatformSnapshot $snapshot): int
    {
        $query = User::query()->withoutGlobalScopes()->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'));
        $author = $this->option('author') ? $query->where('email', $this->option('author'))->first() : $query->orderBy('id')->first();

        if (! $author) {
            $this->error('No hay un super usuario para firmar lo importado.');

            return self::FAILURE;
        }

        $result = $snapshot->import($author);

        foreach ($result as $key => $value) {
            $this->line(str_pad($key, 22).(is_array($value) ? json_encode($value) : $value));
        }

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('La acción es «export» o «import».');

        return self::FAILURE;
    }
}
