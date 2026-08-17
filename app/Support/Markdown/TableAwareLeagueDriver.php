<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Spatie\MarkdownResponse\Drivers\MarkdownDriver;

/**
 * league/html-to-markdown's default Environment never registers TableConverter,
 * so <table> markup collapses into a run-on line of cell text instead of a pipe
 * table. Spatie's own LeagueDriver just does `new HtmlConverter($options)`, which
 * takes the library default — there is no config option to add converters, so
 * this driver builds the same HtmlConverter and adds TableConverter to its
 * environment before converting. Registered in place of the vendor binding in
 * AppServiceProvider.
 */
final readonly class TableAwareLeagueDriver implements MarkdownDriver
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private array $options = [],
    ) {}

    public function convert(string $html): string
    {
        $converter = new HtmlConverter($this->options);
        $converter->getEnvironment()->addConverter(new TableConverter);

        return $converter->convert($html);
    }
}
