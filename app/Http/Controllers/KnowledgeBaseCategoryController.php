<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GroupAccess;
use App\Models\KnowledgeBaseCategory;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseCategoryController extends Controller
{
    use GroupAccess;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
        ]);

        $groupId = (int)$validated['group_id'];
        $userId = (int)$request->user()->id;

        $this->assertInGroup($userId, $groupId);

        $cats = KnowledgeBaseCategory::query()
            ->where('group_id', $groupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($cats);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $groupId = (int)$validated['group_id'];
        $userId = (int)$request->user()->id;

        $this->assertGroupAdmin($userId, $groupId);

        $max = KnowledgeBaseCategory::where('group_id', $groupId)->max('sort_order');
        $validated['sort_order'] = is_null($max) ? 10 : ((int)$max + 10);

        $cat = KnowledgeBaseCategory::create($validated);

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_category',
            entityId: $cat->id,
            action: 'created',
            changes: $cat->only(['title','description','sort_order'])
        );

        return response()->json($cat, 201);
    }

    public function update(Request $request, $category): JsonResponse
    {
        $category = KnowledgeBaseCategory::findOrFail($category);

        $userId = (int)$request->user()->id;
        $groupId = (int)$category->group_id;

        $this->assertGroupAdmin($userId, $groupId);

        $old = $category->only(['title','description','sort_order']);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
        ]);

        $category->update($validated);

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_category',
            entityId: $category->id,
            action: 'updated',
            changes: ['old' => $old, 'new' => $category->only(['title','description','sort_order'])]
        );

        return response()->json($category);
    }

    public function destroy(Request $request, $category): JsonResponse
    {
        $category = KnowledgeBaseCategory::findOrFail($category);

        $userId = (int)$request->user()->id;
        $groupId = (int)$category->group_id;

        $this->assertGroupAdmin($userId, $groupId);

        $snapshot = $category->only(['id','title','description','sort_order']);
        $category->delete();

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_category',
            entityId: $snapshot['id'],
            action: 'deleted',
            changes: $snapshot
        );

        return response()->json(null, 200);
    }

    /**
     * PATCH /knowledge-base/categories/order
     * body: { "group_id": 1, "categories": [ { "id": 10, "sort_order": 10 }, ... ] }
     */
    public function updateOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
            'categories' => 'required|array|min:1',
            'categories.*.id' => 'required|exists:knowledge_base_categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        $groupId = (int)$validated['group_id'];
        $userId = (int)$request->user()->id;

        $this->assertGroupAdmin($userId, $groupId);

        $ids = array_map(fn($c) => (int)$c['id'], $validated['categories']);

        $count = KnowledgeBaseCategory::where('group_id', $groupId)
            ->whereIn('id', $ids)
            ->count();

        abort_unless($count === count($ids), 422, 'Переданы категории не из этой группы');

        DB::transaction(function () use ($validated) {
            foreach ($validated['categories'] as $c) {
                KnowledgeBaseCategory::where('id', $c['id'])
                    ->update(['sort_order' => (int)$c['sort_order']]);
            }
        });

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_category',
            entityId: null,
            action: 'order_updated',
            changes: ['categories' => $validated['categories']]
        );

        return response()->json(null, 200);
    }
}
