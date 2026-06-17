<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

final readonly class BlogTagController
{
    public function __invoke(string $slug): View
    {
        $tag = Tag::query()->where('slug', $slug)->firstOrFail();

        $posts = Post::query()
            ->published()
            ->whereHas('tags', fn (Builder $query) => $query->where('blog_tags.id', $tag->id))
            ->with(['category', 'author', 'seo'])
            ->latest('published_at')
            ->paginate(config('ink.per_page', 12));

        return view('blog.index', ['posts' => $posts, 'tag' => $tag]);
    }
}
