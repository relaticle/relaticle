<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use Illuminate\View\View;
use Relaticle\Ink\Filament\Resources\PostResource;
use Relaticle\Ink\Models\Post;

final readonly class BlogPreviewController
{
    public function __invoke(Post $post): View
    {
        $post->load(['category', 'author', 'seo', 'tags']);

        $relatedPosts = $post->relatedPosts()->with(['category'])->get();

        // The blog admin lives in the sysadmin panel, which has no tenancy — only a
        // signed-in system administrator gets an edit link, and it carries no tenant.
        $editUrl = auth('sysadmin')->check()
            ? PostResource::getUrl('edit', ['record' => $post], panel: 'sysadmin')
            : null;

        return view('blog.preview', ['post' => $post, 'relatedPosts' => $relatedPosts, 'editUrl' => $editUrl]);
    }
}
