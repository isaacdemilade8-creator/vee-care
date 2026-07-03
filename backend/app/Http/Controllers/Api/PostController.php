<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $posts = $this->basePostQuery()
            ->when($request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', $term)
                        ->orWhere('body', 'like', $term)
                        ->orWhereHas('author', fn ($author) => $author->where('name', 'like', $term));
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 12));

        return PostResource::collection($posts);
    }

    public function show(Post $post): PostResource
    {
        return new PostResource($this->freshPost($post));
    }

    public function store(Request $request, AuditService $audit): PostResource
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $post = Post::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        $audit->record($request, 'post.created', $post);

        return new PostResource($this->freshPost($post));
    }

    public function update(Request $request, Post $post, AuditService $audit): PostResource
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $post->update($data);

        $audit->record($request, 'post.updated', $post);

        return new PostResource($this->freshPost($post));
    }

    public function destroy(Request $request, Post $post, AuditService $audit): JsonResponse
    {
        $audit->record($request, 'post.deleted', $post, ['title' => $post->title]);

        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function comment(Request $request, Post $post, AuditService $audit, NotificationService $notifications): PostResource
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1200'],
        ]);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $audit->record($request, 'post.comment', $comment, ['post_id' => $post->id]);

        $this->notifyPostOwner(
            $post,
            $request->user(),
            $notifications,
            'blog.comment',
            'New comment on your post',
            "{$request->user()->name} commented: ".str($comment->body)->limit(90),
            ['commentId' => $comment->id]
        );

        return new PostResource($this->freshPost($post));
    }

    public function destroyComment(PostComment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    private function basePostQuery()
    {
        return Post::query()
            ->with(['author', 'comments' => fn ($query) => $query->with('author')->latest()->limit(25)])
            ->withCount('comments');
    }

    private function freshPost(Post $post): Post
    {
        return $this->basePostQuery()->findOrFail($post->id);
    }

    private function notifyPostOwner(
        Post $post,
        User $actor,
        NotificationService $notifications,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        if ($post->user_id === $actor->id) {
            return;
        }

        $post->loadMissing('author');

        $notifications->send(
            $post->author,
            $type,
            $title,
            $body,
            [
                'postId' => $post->id,
                'actorId' => $actor->id,
                ...$data,
            ]
        );
    }
}
