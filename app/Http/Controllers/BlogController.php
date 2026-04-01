<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;
use ManukMinasyan\FilamentBlog\Filament\Resources\PostResource;
use ManukMinasyan\FilamentBlog\Models\Category;
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

    public function category(string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();

        $posts = Post::query()
            ->published()
            ->where('category_id', $category->id)
            ->with(['category', 'author', 'seo'])
            ->latest('published_at')
            ->paginate(config('filament-blog.per_page', 12));

        return view('blog.index', ['posts' => $posts, 'category' => $category]);
    }

    public function preview(Post $post): View
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

        $editUrl = auth()->user()
            ? PostResource::getUrl('edit', ['record' => $post])
            : null;

        return view('blog.preview', ['post' => $post, 'relatedPosts' => $relatedPosts, 'editUrl' => $editUrl]);
    }

    public function feed(): Response
    {
        $posts = Post::query()
            ->published()
            ->with(['category', 'author'])
            ->latest('published_at')
            ->limit(20)
            ->get();

        return response()
            ->view('blog.feed', ['posts' => $posts])
            ->header('Content-Type', 'application/rss+xml');
    }
}
