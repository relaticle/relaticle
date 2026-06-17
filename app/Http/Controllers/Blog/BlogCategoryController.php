<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use Illuminate\View\View;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;

final readonly class BlogCategoryController
{
    public function __invoke(string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();

        $posts = Post::query()
            ->published()
            ->where('category_id', $category->id)
            ->with(['category', 'author', 'seo'])
            ->latest('published_at')
            ->paginate(config('ink.per_page', 12));

        return view('blog.index', ['posts' => $posts, 'category' => $category]);
    }
}
