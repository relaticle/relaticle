<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use Illuminate\Http\Response;
use ManukMinasyan\FilamentBlog\Models\Post;

final readonly class BlogFeedController
{
    public function __invoke(): Response
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
