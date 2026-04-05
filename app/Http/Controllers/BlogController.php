<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;
use ManukMinasyan\FilamentBlog\Models\Post;

final readonly class BlogController
{
    public function index(): View
    {
        $posts = Post::query()
            ->published()
            ->with(['category', 'author', 'seo'])
            ->latest('published_at')
            ->paginate(config('filament-blog.per_page', 12));

        return view('blog.index', ['posts' => $posts]);
    }

    public function show(string $slug): View
    {
        $post = Post::query()
            ->published()
            ->with(['category', 'author', 'seo'])
            ->where('slug', $slug)
            ->firstOrFail();

        $relatedPosts = Post::query()
            ->published()
            ->where('id', '!=', $post->id)
            ->where('category_id', $post->getAttribute('category_id'))
            ->with(['category'])
            ->latest('published_at')
            ->limit(3)
            ->get();

        return view('blog.show', ['post' => $post, 'relatedPosts' => $relatedPosts]);
    }
}
