<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MediaAsset;
use App\Services\ImageService;
use App\Services\Storefront\StorefrontStructure;
use App\Support\TenantContext;
use App\Support\Uploads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(protected ImageService $images) {}

    public function index(): Response
    {
        $companyId = TenantContext::requireCompanyId();

        $categories = Category::query()
            ->where('company_id', $companyId)
            ->withCount('products')
            ->with(['parent:id,name', 'image'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $this->iconName($category->icon),
                'image' => $category->image ? StorefrontStructure::imageUrl($category->image) : null,
                'sort_order' => $category->sort_order,
                'is_active' => (bool) $category->is_active,
                'products_count' => $category->products_count,
                'parent' => $category->parent ? ['id' => $category->parent->id, 'name' => $category->parent->name] : null,
            ]);

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            // Los iconos de la tienda en linea: el menu y el bloque de categorias los usan.
            'icons' => config('storefront_icons'),
            'parents' => $categories->whereNull('parent')->map(fn ($category) => [
                'value' => $category['id'],
                'label' => $category['name'],
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = TenantContext::requireCompanyId();
        $validated = $this->validateCategory($request, $companyId);

        $category = Category::create(array_merge(collect($validated)->except(['image', 'remove_image'])->all(), [
            'company_id' => $companyId,
            'slug' => $this->uniqueSlug($validated['name'], $companyId),
        ]));

        $this->saveImage($request, $category);

        return back()->with('success', 'La categoria quedo creada.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeCompany($category);

        $validated = $this->validateCategory($request, $category->company_id, $category->id);

        $category->update(collect($validated)->except(['image', 'remove_image'])->all());
        $this->saveImage($request, $category);

        return back()->with('success', 'La categoria quedo actualizada.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorizeCompany($category);

        if ($category->products()->exists()) {
            return back()->with('error', 'Esa categoria todavia tiene articulos: muevelos antes de eliminarla.');
        }

        if ($category->children()->exists()) {
            return back()->with('error', 'Esa categoria tiene subcategorias.');
        }

        $category->delete();

        return back()->with('success', 'La categoria quedo eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateCategory(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
                Rule::notIn($ignoreId ? [$ignoreId] : []),
            ],
            'icon' => ['nullable', Rule::in(config('storefront_icons'))],
            'image' => ['nullable', 'image', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'parent_id.not_in' => 'Una categoria no puede ser su propia madre.',
            'icon.in' => 'Elige uno de los iconos de la lista.',
            'image.max' => 'La imagen no puede pesar mas de 4 MB.',
        ]);
    }

    /**
     * La foto de la categoria para la tienda en linea. Va a la biblioteca de la empresa, con
     * el nombre de la categoria como texto alternativo.
     */
    protected function saveImage(Request $request, Category $category): void
    {
        if ($request->boolean('remove_image')) {
            $category->update(['image_media_id' => null]);
        }

        if (! $request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');
        $stored = $this->images->storeWithVariants($file, 'categories', MediaAsset::VARIANTS);

        $asset = MediaAsset::query()->withoutGlobalScopes()->create([
            'company_id' => $category->company_id,
            'path' => $stored['path'],
            'disk' => Uploads::diskName(),
            'mime' => $file->getMimeType(),
            'width' => $stored['width'],
            'height' => $stored['height'],
            'bytes' => $file->getSize(),
            'alt' => $category->name,
            'variants' => $stored['variants'],
            'created_by_user_id' => $request->user()?->id,
        ]);

        $category->update(['image_media_id' => $asset->id]);
    }

    /** El icono con el nombre que entiende la tienda (sin el sufijo `Icon`), o nada. */
    protected function iconName(?string $icon): ?string
    {
        $name = preg_replace('/Icon$/', '', (string) $icon);

        return in_array($name, config('storefront_icons'), true) ? $name : null;
    }

    protected function uniqueSlug(string $name, int $companyId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Category::query()->where('company_id', $companyId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    protected function authorizeCompany(Category $category): void
    {
        abort_if(
            ! auth()->user()->isSuperAdmin() && $category->company_id !== TenantContext::activeCompanyId(),
            403,
            'Esa categoria no pertenece a tu empresa.'
        );
    }
}
