<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;
use Relaticle\Ink\Models\Post;

final readonly class BlogController
{
    public function index(): View
    {
        $posts = Post::query()
            ->published()
            ->with(['category', 'author', 'seo'])
            ->latest('published_at')
            ->paginate(config('ink.per_page', 12));

        return view('blog.index', ['posts' => $posts]);
    }

    public function show(string $slug): View
    {
        $post = Post::query()
            ->published()
            ->with(['category', 'author', 'seo', 'tags'])
            ->where('slug', $slug)
            ->firstOrFail();

        $relatedPosts = $post->relatedPosts()->with(['category'])->get();

        return view('blog.show', ['post' => $post, 'relatedPosts' => $relatedPosts]);
    }
}
