<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

use Relaticle\Ink\Models\Category;

final readonly class BlogCategoryName
{
    public static function of(Category $category): string
    {
        $key = 'cloud::marketing.blog_categories.'.$category->getAttribute('slug');
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : (string) $category->getAttribute('name');
    }
}
