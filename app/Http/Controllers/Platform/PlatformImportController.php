<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PreviewPlatformImportRequest;
use App\Models\Company;
use App\Models\PlatformImportBatch;
use App\Services\Platform\PlatformImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plataforma > Carga masiva (CSV): tipo, empresa destino, archivo, vista previa y procesar.
 */
class PlatformImportController extends Controller
{
    public function __construct(protected PlatformImportService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Platform/Imports/Index', [
            'types' => collect(PlatformImportService::types())
                ->map(function (string $class, string $type) {
                    $import = new $class();

                    return [
                        'value' => $type,
                        'label' => $import->title(),
                        'description' => $import->description(),
                        'needs_company' => PlatformImportService::needsCompany($type),
                        'columns' => collect($import->columns())->map(fn (string $hint, string $name) => [
                            'name' => $name,
                            'hint' => $hint,
                            'required' => in_array($name, $import->required(), true),
                        ])->values()->all(),
                        'notes' => $import->notes(),
                    ];
                })->values(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'tax_id', 'is_active'])->map(fn (Company $company) => [
                'value' => $company->id,
                'label' => $company->name.($company->is_active ? '' : ' (desactivada)'),
            ])->values(),
            'batches' => PlatformImportBatch::query()
                ->with(['user:id,name,last_name', 'company:id,name'])
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (PlatformImportBatch $batch) => [
                    'id' => $batch->id,
                    'type' => $batch->type,
                    'type_label' => PlatformImportService::supports($batch->type) ? PlatformImportService::import($batch->type)->title() : $batch->type,
                    'company' => $batch->company?->name,
                    'user' => $batch->user?->full_name,
                    'original_filename' => $batch->original_filename,
                    'status' => $batch->status,
                    'status_label' => PlatformImportBatch::STATUS_LABELS[$batch->status] ?? $batch->status,
                    'rows_total' => $batch->rows_total,
                    'rows_success' => $batch->rows_success,
                    'rows_failed' => $batch->rows_failed,
                    'message' => $batch->message,
                    'has_errors' => $batch->error_report_path !== null,
                    'created_at' => $batch->created_at?->toIso8601String(),
                    'finished_at' => $batch->finished_at?->toIso8601String(),
                ]),
            // La vista previa de la ultima subida, hasta que se procese o se descarte.
            'preview' => $request->session()->get('platform_import_preview'),
        ]);
    }

    public function preview(PreviewPlatformImportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        ['batch' => $batch, 'result' => $result] = $this->service->preview(
            $request->file('file'),
            $data['type'],
            isset($data['company_id']) ? (int) $data['company_id'] : null,
            $request->user(),
        );

        return redirect()->route('platform.imports.index')->with('platform_import_preview', [
            'batch_id' => $batch->id,
            'type' => $batch->type,
            'company' => $batch->company?->name,
            'filename' => $batch->original_filename,
            'rows' => $result['rows'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'sample' => $result['sample'],
        ]);
    }

    public function process(Request $request, PlatformImportBatch $batch): RedirectResponse
    {
        try {
            $batch = $this->service->process($batch, $request->user());
        } catch (RuntimeException $exception) {
            return redirect()->route('platform.imports.index')->with('error', $exception->getMessage());
        }

        $flash = match ($batch->status) {
            PlatformImportBatch::STATUS_COMPLETED => ['success', "Carga terminada: {$batch->message}"],
            PlatformImportBatch::STATUS_PARTIAL => ['warning', "Carga terminada con filas omitidas: {$batch->message} Descarga el reporte de errores."],
            default => ['error', 'La carga no entró: '.($batch->message ?? 'revisa el reporte de errores.')],
        };

        return redirect()->route('platform.imports.index')->with(...$flash);
    }

    /**
     * Descarta una carga simulada que no se va a procesar: borra el archivo subido.
     */
    public function discard(PlatformImportBatch $batch): RedirectResponse
    {
        if ($batch->status !== PlatformImportBatch::STATUS_PENDING) {
            return redirect()->route('platform.imports.index')->with('error', 'Solo se descarta una carga que no se ha procesado.');
        }

        Storage::disk(PlatformImportService::DISK)->delete($batch->stored_path);
        $batch->delete();

        return redirect()->route('platform.imports.index')->with('success', 'La carga quedó descartada.');
    }

    public function original(PlatformImportBatch $batch): StreamedResponse
    {
        abort_unless(Storage::disk(PlatformImportService::DISK)->exists($batch->stored_path), 404);

        return Storage::disk(PlatformImportService::DISK)->download($batch->stored_path, $batch->original_filename);
    }

    public function errors(PlatformImportBatch $batch): StreamedResponse
    {
        abort_unless($batch->error_report_path && Storage::disk(PlatformImportService::DISK)->exists($batch->error_report_path), 404);

        return Storage::disk(PlatformImportService::DISK)->download($batch->error_report_path, "errores-carga-{$batch->id}.csv");
    }

    public function template(string $type): \Illuminate\Http\Response
    {
        abort_unless(PlatformImportService::supports($type), 404);

        return response($this->service->template($type), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"plantilla-{$type}.csv\"",
        ]);
    }

    public function templates(): BinaryFileResponse
    {
        return response()->download($this->service->templatesZip(), 'plantillas-carga-masiva.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
