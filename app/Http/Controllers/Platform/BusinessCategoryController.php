<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SaveBusinessCategoryRequest;
use App\Models\BusinessCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plataforma > Clasificaciones: alimentos, textiles, belleza… Cada empresa tiene una, se elige al
 * crearla o al contratar, y la landing agrupa por aqui. Siempre queda al menos una activa.
 */
class BusinessCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/BusinessCategories/Index', [
            'categories' => BusinessCategory::query()
                ->ordered()
                ->withCount('companies')
                ->get()
                ->map(fn (BusinessCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'is_active' => $category->is_active,
                    'sort_order' => $category->sort_order,
                    'companies_count' => $category->companies_count,
                ])->values(),
            'icons' => array_values(config('landing_icons')),
        ]);
    }

    public function store(SaveBusinessCategoryRequest $request): RedirectResponse
    {
        $category = BusinessCategory::query()->create($request->validated());

        return back()->with('success', "La clasificación {$category->name} quedó creada.");
    }

    public function update(SaveBusinessCategoryRequest $request, BusinessCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', "La clasificación {$category->name} quedó guardada.");
    }

    /** Desactivar no le quita la clasificación a nadie: solo deja de ofrecerse al crear o contratar. */
    public function toggle(BusinessCategory $category): RedirectResponse
    {
        if ($category->is_active && ! BusinessCategory::query()->active()->whereKeyNot($category->id)->exists()) {
            return back()->with('error', 'Es la única clasificación activa: sin ella nadie podría crear ni contratar una empresa.');
        }

        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', $category->is_active
            ? "{$category->name} quedó activa."
            : "{$category->name} quedó desactivada. Las empresas que la tienen la conservan.");
    }
}
