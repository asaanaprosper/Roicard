<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'in:seeking,offering'],
            'is_active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount(['children', 'profiles'])
            ->when($validated['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
            ->when($validated['q'] ?? null, function ($query, $term) {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $query->where(function ($search) use ($like) {
                    $search->where('name', 'like', $like)->orWhere('slug', 'like', $like);
                });
            })
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories,
            'kinds' => Category::kinds(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request);

        $category = Category::create([
            'parent_id' => $validated['parent_id'] ?? null,
            'kind' => $validated['kind'],
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Category::makeSlug($validated['name'], $validated['kind']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $audit->log('category.created', $category, [
            'kind' => $category->kind,
            'name' => $category->name,
        ]);

        return response()->json([
            'category' => $category->fresh(),
            'message' => 'Category created successfully',
        ], 201);
    }

    public function update(Request $request, Category $category, AuditLogger $audit): JsonResponse
    {
        $validated = $this->validated($request, $category->id, $category->kind);

        $kind = $validated['kind'] ?? $category->kind;
        $parentId = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : $category->parent_id;

        if ($kind !== $category->kind && $category->children()->exists()) {
            return response()->json([
                'message' => 'Move child categories to another taxonomy before changing this parent category kind.',
            ], 422);
        }

        $this->assertParentValid($category, $kind, $parentId);

        $before = $category->only(['name', 'slug', 'kind', 'is_active', 'parent_id']);

        $category->fill([
            'parent_id' => $parentId,
            'kind' => $kind,
            'name' => $validated['name'] ?? $category->name,
            'slug' => $validated['slug'] ?? ($kind !== $category->kind
                ? Category::makeSlug($validated['name'] ?? $category->name, $kind, $category->id)
                : $category->slug),
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $category->description,
            'is_active' => $validated['is_active'] ?? $category->is_active,
            'sort_order' => $validated['sort_order'] ?? $category->sort_order,
        ])->save();

        $audit->log('category.updated', $category, [
            'before' => $before,
            'after' => $category->only(['name', 'slug', 'kind', 'is_active', 'parent_id']),
        ]);

        return response()->json([
            'category' => $category->fresh()->load('parent:id,name'),
            'message' => 'Category updated successfully',
        ]);
    }

    public function destroy(Category $category, AuditLogger $audit): JsonResponse
    {
        if ($category->children()->exists()) {
            return response()->json([
                'message' => 'Move or delete child categories before deleting this parent.',
            ], 422);
        }

        DB::transaction(function () use ($category) {
            $category->profiles()->detach();
            $category->delete();
        });

        $audit->log('category.deleted', $category, [
            'kind' => $category->kind,
            'name' => $category->name,
        ]);

        return response()->json(['message' => 'Category deleted successfully']);
    }

    private function validated(Request $request, ?int $ignoreId = null, ?string $fallbackKind = null): array
    {
        $kind = $request->input('kind', $fallbackKind);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'kind' => [$ignoreId ? 'sometimes' : 'required', 'string', Rule::in(Category::kinds())],
            'name' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query->where('kind', $kind))
                    ->ignore($ignoreId),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')
                    ->where(fn ($query) => $query->where('kind', $kind))
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return $validated;
    }

    private function assertParentValid(Category $category, string $kind, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ((int) $parentId === (int) $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be its own parent.',
            ]);
        }

        $parent = Category::find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => 'The selected parent category does not exist.',
            ]);
        }

        if ($parent->kind !== $kind) {
            throw ValidationException::withMessages([
                'parent_id' => 'Parent and child categories must belong to the same taxonomy (seeking or offering).',
            ]);
        }

        // Prevent cycles: the selected parent cannot be a descendant.
        $cursor = $parent;
        $guard = 0;
        while ($cursor->parent_id !== null && $guard < 50) {
            if ((int) $cursor->parent_id === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The selected parent would create a category cycle.',
                ]);
            }
            $cursor = Category::find($cursor->parent_id);
            if (! $cursor) {
                break;
            }
            $guard++;
        }
    }
}
