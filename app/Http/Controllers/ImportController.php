<?php

namespace App\Http\Controllers;

use App\Imports\ImportRegistry;
use App\Models\AccessLog;
use App\Services\MasterImportService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importacion de maestras desde un archivo.
 *
 * Una sola pantalla y un solo lector para todos los modulos: lo que cambia entre articulos,
 * clientes o proveedores son las columnas, y eso lo declara cada `MasterImport`. El permiso
 * si es propio de cada modulo (`<modulo>.index.import`) y se exige en la ruta.
 */
class ImportController extends Controller
{
    public function __construct(protected MasterImportService $imports) {}

    public function form(Request $request, string $module): Response
    {
        $import = ImportRegistry::for($module);

        return Inertia::render('Imports/Form', [
            'module' => $import->module(),
            'title' => $import->title(),
            'description' => $import->description(),
            'columns' => collect($import->columns())
                ->map(fn (string $hint, string $name) => [
                    'name' => $name,
                    'hint' => $hint,
                    'required' => in_array($name, $import->required(), true),
                ])
                ->values()
                ->all(),
            'example' => $import->example(),
            'notes' => $import->notes(),
            'indexRoute' => $import->indexRoute(),
            'submitRoute' => $import->submitRoute(),
            'result' => $request->session()->get('import_result'),
        ]);
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $import = ImportRegistry::for($module);
        $companyId = TenantContext::requireCompanyId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'file.required' => 'Sube el archivo CSV.',
            'file.mimes' => 'El archivo debe ser CSV.',
            'file.max' => 'El archivo no puede pesar mas de 2 MB.',
        ]);

        $result = $this->imports->run($import, $request->file('file')->getRealPath(), $companyId);

        // Una importacion cambia muchos registros de un golpe: queda quien la hizo y con que.
        AccessLog::log('master_import', $request->user()->id, [
            'module' => $import->module(),
            'file' => $request->file('file')->getClientOriginalName(),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        $summary = "Importacion terminada: {$result['created']} nuevos, {$result['updated']} actualizados, {$result['skipped']} omitidos.";

        if ($result['skipped'] > 0) {
            return redirect()->route($import->formRoute())
                ->with('warning', $summary)
                ->with('import_result', $result);
        }

        return redirect()->route($import->indexRoute())->with('success', $summary);
    }
}
