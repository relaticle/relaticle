<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Data;

use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelMarkdown\MarkdownRenderer;

final class DocumentData extends Data
{
    public function __construct(
        #[StringType]
        public string $type,

        #[StringType]
        public string $title,

        #[StringType]
        public string $content,

        /** @var array<string, string> $tableOfContents */
        public array $tableOfContents,

        public ?string $description = null,
    ) {}

    /**
     * Create a document from the given type
     */
    public static function fromType(string $type): self
    {
        $documents = config('documentation.documents', []);

        abort_if(! isset($documents[$type]), 404, 'Document not found');

        $documentConfig = $documents[$type];
        $file = $documentConfig['file'];
        $title = $documentConfig['title'];
        $description = $documentConfig['description'] ?? null;

        $path = self::getMarkdownPath($file);

        // Validate the path is within the intended directory
        $realPath = realpath($path);
        $resourcePath = realpath(config('documentation.markdown.base_path'));

        abort_if($resourcePath === false, 500, 'Unable to determine resource path');

        abort_if($realPath === '0' || $realPath === false || ! str_starts_with($realPath, $resourcePath) || ! file_exists($realPath), 404, 'Document not found');

        $content = file_get_contents($realPath);

        abort_if($content === false, 500, 'Unable to read document content');

        $renderedContent = resolve(MarkdownRenderer::class)->toHtml($content);

        $tableOfContents = self::extractTableOfContents($renderedContent);

        return new self(
            type: $type,
            title: $title,
            content: $renderedContent,
            tableOfContents: $tableOfContents,
            description: $description,
        );
    }

    /**
     * Extract table of contents from the rendered HTML
     *
     * @return array<string, string>
     */
    private static function extractTableOfContents(string $contents): array
    {
        $matches = [];

        preg_match_all('/<h2.*><a.*id="([^"]+)".*>#<\/a>([^<]+)/', $contents, $matches);

        if (empty($matches[1]) || empty($matches[2])) {
            return [];
        }

        return array_combine($matches[1], $matches[2]);
    }

    /**
     * Get the path to the Markdown file
     */
    private static function getMarkdownPath(string $file): string
    {
        return config('documentation.markdown.base_path').'/'.$file;
    }
}
