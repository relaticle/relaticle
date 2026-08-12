<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Carbon\CarbonImmutable;
use Spatie\SchemaOrg\Article;
use Spatie\SchemaOrg\BreadcrumbList;
use Spatie\SchemaOrg\Graph;
use Spatie\SchemaOrg\Schema;

/**
 * `/help` bypasses `<x-documentation::layout>` (see HelpController), which is
 * the only place `/docs` gets its BreadcrumbList from — so without this,
 * `/help` pages carry zero structured data. Views call this directly, inline,
 * the same way the marketing pages build their own Graph
 * (resources/views/home/index.blade.php).
 */
final readonly class DocsJsonLd
{
    /**
     * @param  list<array{name: string, url: string}>  $trail
     */
    public function breadcrumbs(array $trail): Graph
    {
        return (new Graph)->add($this->breadcrumbList($trail));
    }

    /**
     * @param  list<array{name: string, url: string}>  $trail
     */
    public function article(DocPage $page, string $url, array $trail): Graph
    {
        return (new Graph)
            ->add($this->articleNode($page, $url))
            ->add($this->breadcrumbList($trail));
    }

    private function articleNode(DocPage $page, string $url): Article
    {
        $article = Schema::article()
            ->headline($page->title)
            ->description($page->description)
            ->mainEntityOfPage($url)
            ->url($url);

        return $page->updated instanceof CarbonImmutable
            ? $article->dateModified($page->updated)
            : $article;
    }

    /**
     * @param  list<array{name: string, url: string}>  $trail
     */
    private function breadcrumbList(array $trail): BreadcrumbList
    {
        $items = [];

        foreach ($trail as $index => $crumb) {
            // ->item() is typed ThingContract-only, but schema.org's spec (and
            // Google's own breadcrumb examples) allow a plain URL string --
            // exactly what the /docs breadcrumbs already pass here. setProperty()
            // is the real, untyped primitive every fluent setter wraps.
            $items[] = Schema::listItem()
                ->position($index + 1)
                ->name($crumb['name'])
                ->setProperty('item', $crumb['url']);
        }

        return Schema::breadcrumbList()->itemListElement($items);
    }
}
