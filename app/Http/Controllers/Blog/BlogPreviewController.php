<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use App\Models\User;
use Illuminate\View\View;
use ManukMinasyan\FilamentBlog\Filament\Resources\PostResource;
use ManukMinasyan\FilamentBlog\Models\Post;

final readonly class BlogPreviewController
{
    public function __invoke(Post $post): View
    {
        $post->load(['category', 'author', 'seo']);

        $relatedPosts = Post::query()
            ->published()
            ->where('id', '!=', $post->id)
            ->where('category_id', $post->getAttribute('category_id'))
            ->with(['category'])
            ->latest('published_at')
            ->limit(3)
            ->get();

        $user = auth()->user();

        $editUrl = $user instanceof User && $user->currentTeam
            ? PostResource::getUrl('edit', ['record' => $post, 'tenant' => $user->currentTeam])
            : null;

        return view('blog.preview', ['post' => $post, 'relatedPosts' => $relatedPosts, 'editUrl' => $editUrl]);
    }
}
