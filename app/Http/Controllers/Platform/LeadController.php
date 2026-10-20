<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Solicitudes: los mercados que pidieron que los contacten, y las contrataciones
 * que necesitan que alguien las mire.
 */
class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->string('estado');
        $search = trim((string) $request->string('q'));

        $leads = PlatformLead::query()
            ->with(['plan:id,name', 'cycle:id,name', 'handledBy:id,name,last_name', 'company:id,name', 'businessCategory:id,name'])
            ->when(in_array($status, PlatformLead::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('company_name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PlatformLead $lead) => [
                'id' => $lead->id,
                'source' => $lead->source,
                'source_label' => PlatformLead::SOURCE_LABELS[$lead->source] ?? $lead->source,
                'company_name' => $lead->company_name,
                'tax_id' => $lead->tax_id,
                'city' => $lead->city,
                'category' => $lead->businessCategory?->name,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'estimated_stores' => $lead->estimated_stores,
                'plan' => $lead->plan?->name,
                'cycle' => $lead->cycle?->name,
                'message' => $lead->message,
                'status' => $lead->status,
                'status_label' => PlatformLead::STATUS_LABELS[$lead->status] ?? $lead->status,
                'notes' => $lead->notes,
                'handled_by' => $lead->handledBy?->full_name,
                'company' => $lead->company ? ['id' => $lead->company->id, 'name' => $lead->company->name] : null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Platform/Leads/Index', [
            'leads' => $leads,
            'filters' => ['estado' => $status, 'q' => $search],
            'statusOptions' => collect(PlatformLead::STATUS_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'counts' => PlatformLead::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'selectedId' => $request->integer('solicitud') ?: null,
        ]);
    }

    public function update(Request $request, PlatformLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PlatformLead::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead->update([...$data, 'handled_by_user_id' => $request->user()->id]);

        return back()->with('success', 'La solicitud quedó actualizada.');
    }
}
