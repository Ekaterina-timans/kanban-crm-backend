<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GroupAccess;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseArticleController extends Controller
{
    use GroupAccess;

    private function assertGroupAdmin(int $userId, int $groupId): void
    {
        abort_unless($this->isGroupAdmin($userId, $groupId), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
            'category_id' => 'nullable|exists:knowledge_base_categories,id',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,published',
        ]);

        $groupId = (int)$validated['group_id'];
        $userId  = (int)$request->user()->id;

        $this->assertInGroup($userId, $groupId);
        $isAdmin = $this->isGroupAdmin($userId, $groupId);

        $q = KnowledgeBaseArticle::query()
            ->where('group_id', $groupId)
            ->with(['author:id,name,email', 'category:id,title']);

        if (!empty($validated['category_id'])) {
            $q->where('category_id', (int)$validated['category_id']);
        }

        if (!$isAdmin) {
            $q->where('status', 'published');
        } else {
            if (!empty($validated['status'])) {
                $q->where('status', $validated['status']);
            }
        }

        if (!empty($validated['search'])) {
            $s = trim($validated['search']);
            $q->where(function ($w) use ($s) {
                $w->where('title', 'like', "%{$s}%")
                  ->orWhere('content_md', 'like', "%{$s}%");
            });
        }

        return response()->json($q->orderByDesc('updated_at')->paginate(20));
    }

    public function show(Request $request, $article): JsonResponse
    {
        $article = KnowledgeBaseArticle::with([
            'author:id,name,email',
            'category:id,title',
            'attachments.uploader:id,name,email',
        ])->findOrFail($article);

        $userId  = (int)$request->user()->id;
        $groupId = (int)$article->group_id;

        $this->assertInGroup($userId, $groupId);

        $isAdmin = $this->isGroupAdmin($userId, $groupId);
        if (!$isAdmin) {
            abort_unless($article->status === 'published', 404);
        }

        return response()->json($article);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
            'category_id' => 'nullable|exists:knowledge_base_categories,id',
            'title' => 'required|string|max:255',
            'content_md' => 'required|string',
            'status' => 'nullable|in:draft,published',
        ]);

        $groupId = (int)$validated['group_id'];
        $userId  = (int)$request->user()->id;

        $this->assertGroupAdmin($userId, $groupId);

        if (!empty($validated['category_id'])) {
            $ok = KnowledgeBaseCategory::where('id', (int)$validated['category_id'])
                ->where('group_id', $groupId)
                ->exists();
            abort_unless($ok, 422, 'Категория не принадлежит этой группе');
        }

        $article = KnowledgeBaseArticle::create([
            'group_id' => $groupId,
            'category_id' => $validated['category_id'] ?? null,
            'author_id' => $userId,
            'title' => $validated['title'],
            'content_md' => $validated['content_md'],
            'status' => $validated['status'] ?? 'published',
        ]);

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_article',
            entityId: $article->id,
            action: 'created',
            changes: $article->only(['title','status','category_id'])
        );

        return response()->json($article, 201);
    }

    public function update(Request $request, $article): JsonResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($article);

        $userId  = (int)$request->user()->id;
        $groupId = (int)$article->group_id;

        $this->assertGroupAdmin($userId, $groupId);

        $old = $article->only(['title','content_md','status','category_id']);

        $validated = $request->validate([
            'category_id' => 'sometimes|nullable|exists:knowledge_base_categories,id',
            'title' => 'sometimes|required|string|max:255',
            'content_md' => 'sometimes|required|string',
            'status' => 'sometimes|required|in:draft,published',
        ]);

        if (array_key_exists('category_id', $validated) && !empty($validated['category_id'])) {
            $ok = KnowledgeBaseCategory::where('id', (int)$validated['category_id'])
                ->where('group_id', $groupId)
                ->exists();
            abort_unless($ok, 422, 'Категория не принадлежит группе статьи');
        }

        $article->update($validated);

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_article',
            entityId: $article->id,
            action: 'updated',
            changes: ['old' => $old, 'new' => $article->only(['title','status','category_id'])]
        );

        return response()->json($article);
    }

    public function destroy(Request $request, $article): JsonResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($article);

        $userId  = (int)$request->user()->id;
        $groupId = (int)$article->group_id;

        $this->assertGroupAdmin($userId, $groupId);

        $snapshot = $article->only(['id','title','status','category_id','group_id']);
        $article->delete();

        ActivityLogService::log(
            groupId: $groupId,
            userId: $userId,
            entityType: 'kb_article',
            entityId: $snapshot['id'],
            action: 'deleted',
            changes: $snapshot
        );

        return response()->json(null, 200);
    }
}
