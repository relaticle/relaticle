<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Illuminate\Support\Str;

/**
 * The one place that knows how a content path maps onto a public URL.
 *
 * Help pages keep both of their segments (/help/{category}/{slug}); the
 * developer guides collapse their single "guides" category away and live flat
 * at /docs/{slug}. Nav, search index, and every view resolve links through
 * here so that shape is stated once.
 */
final readonly class DocUrl
{
    public const string HELP = 'help';

    public const string DOCS = 'docs';

    public static function page(DocPage $page): string
    {
        return $page->area === self::HELP
            ? route('help.show', ['category' => $page->category, 'slug' => $page->slug])
            : route('documentation.show', ['type' => $page->slug]);
    }

    /**
     * The `.md` variant every page answers to, courtesy of the markdown
     * response middleware — what the article's "copy page" action fetches.
     */
    public static function markdown(DocPage $page): string
    {
        return self::page($page).'.md';
    }

    public static function category(DocCategory $category): string
    {
        return $category->area === self::HELP
            ? route('help.category', ['category' => Str::after($category->path, '/')])
            : route('documentation.index');
    }

    public static function area(string $area): string
    {
        return $area === self::HELP ? route('help.index') : route('documentation.index');
    }

    public static function areaTitle(string $area): string
    {
        return $area === self::HELP ? __('Help centre') : __('Developers');
    }
}
