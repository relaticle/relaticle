<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use App\Models\User;
use Illuminate\View\View;
use Relaticle\Ink\Filament\Resources\PostResource;
use Relaticle\Ink\Models\Post;

final readonly class BlogPreviewController
{
    public function __invoke(Post $post): View
    {
        $post->load(['category', 'author', 'seo', 'tags']);

        $relatedPosts = $post->relatedPosts()->with(['category'])->get();

        $user = auth()->user();

        $editUrl = $user instanceof User && $user->currentTeam
            ? PostResource::getUrl('edit', ['record' => $post, 'tenant' => $user->currentTeam])
            : null;

        return view('blog.preview', ['post' => $post, 'relatedPosts' => $relatedPosts, 'editUrl' => $editUrl]);
    }
}
