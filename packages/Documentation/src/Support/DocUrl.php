<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The one place that knows how a content path maps onto a public URL.
 *
 * Help pages keep both of their segments (/help/{category}/{slug}); the
 * developer guides collapse their single "guides" category away and live flat
 * at /developers/{slug}. Nav, search index, and every view resolve links through
 * here so that shape is stated once.
 */
final readonly class DocUrl
{
    public const string HELP = 'help';

    public const string DOCS = 'docs';

    public static function root(): string
    {
        return rtrim(Config::string('documentation.public_url'), '/');
    }

    public static function helpPage(string $category, string $slug): string
    {
        return self::area(self::HELP)."/{$category}/{$slug}";
    }

    public static function helpCategory(string $category): string
    {
        return self::area(self::HELP)."/{$category}";
    }

    public static function docsPage(string $slug): string
    {
        return self::area(self::DOCS)."/{$slug}";
    }

    public static function page(DocPage $page): string
    {
        return $page->area === self::HELP
            ? self::helpPage($page->category, $page->slug)
            : self::docsPage($page->slug);
    }

    /**
     * The `.md` variant every page answers to, courtesy of the markdown
     * response middleware, which is what the article's "copy page" action fetches.
     */
    public static function markdown(DocPage $page): string
    {
        return self::page($page).'.md';
    }

    public static function category(DocCategory $category): string
    {
        return $category->area === self::HELP
            ? self::helpCategory(Str::after($category->path, '/'))
            : self::area(self::DOCS);
    }

    public static function area(string $area): string
    {
        return self::root().($area === self::HELP ? '/help' : '/developers');
    }

    public static function areaTitle(string $area): string
    {
        return $area === self::HELP ? __('Help Centre') : __('Developers');
    }
}
