<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\Documentation\Data\DocumentData;
use Relaticle\Documentation\Data\DocumentSearchRequest;
use Relaticle\Documentation\Data\DocumentSearchResultData;

final class DocumentationService
{
    /**
     * Get a document by its type
     */
    public function getDocument(string $type): DocumentData
    {
        if (! config('documentation.cache.enabled', true)) {
            return DocumentData::fromType($type);
        }

        return Cache::remember(
            "documentation.{$type}",
            config('documentation.cache.ttl', 3600),
            fn (): DocumentData => DocumentData::fromType($type)
        );
    }

    /**
     * Get all document types
     *
     * @return array<string, array<string, string>>
     */
    public function getAllDocumentTypes(): array
    {
        return config('documentation.documents', []);
    }

    /**
     * Search for documents matching the query
     *
     * @return Collection<int, DocumentSearchResultData>
     */
    public function search(DocumentSearchRequest $searchRequest): Collection
    {
        $query = $searchRequest->query;
        $type = $searchRequest->type;

        $minLength = config('documentation.search.min_length', 3);

        if (strlen($query) < $minLength) {
            return DocumentSearchResultData::collect([], Collection::class);
        }

        $documentTypes = $this->getAllDocumentTypes();

        // If a specific type is requested, only search that type
        if ($type && isset($documentTypes[$type])) {
            $documentTypes = [$type => $documentTypes[$type]];
        }

        $results = collect();

        foreach ($documentTypes as $docType => $document) {
            if (! isset($document['file'])) {
                continue;
            }

            $path = $this->getMarkdownPath($document['file']);

            if (! file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);

            if ($content === false) {
                continue;
            }

            if (stripos($content, $query) === false) {
                continue;
            }

            $results->push(
                new DocumentSearchResultData(
                    type: $docType,
                    title: $document['title'],
                    excerpt: $this->generateExcerpt($content, $query),
                    url: DocumentSearchResultData::generateUrl($docType),
                    relevance: $this->calculateRelevance($content, $query),
                )
            );
        }

        // Sort by relevance
        $sortedResults = $results->sortByDesc('relevance')->values();

        return DocumentSearchResultData::collect($sortedResults, Collection::class);
    }

    /**
     * Generate an excerpt from content with the query highlighted
     */
    private function generateExcerpt(string $content, string $query): string
    {
        $position = stripos($content, $query);

        if ($position === false) {
            return Str::limit($content, 150);
        }

        $start = max(0, $position - 75);
        $length = 150;
        $excerpt = substr($content, $start, $length);

        // Add ellipsis if needed
        if ($start > 0) {
            $excerpt = '...'.$excerpt;
        }

        if ($start + $length < strlen($content)) {
            $excerpt .= '...';
        }

        // Highlight the matched query if configured
        if (config('documentation.search.highlight', true)) {
            $pattern = '/('.preg_quote($query, '/').')/i';
            $excerpt = preg_replace($pattern, '<mark>$1</mark>', $excerpt);
        }

        return $excerpt;
    }

    /**
     * Calculate the relevance score for a document based on the query
     */
    private function calculateRelevance(string $content, string $query): float
    {
        // Count occurrences
        $count = substr_count(strtolower($content), strtolower($query));

        // Check if the query is in a heading (more relevant)
        $headingRelevance = 0;
        if (preg_match('/#+ .*'.preg_quote($query, '/').'.*$/im', $content)) {
            $headingRelevance = 2.0;
        }

        // Give more weight to exact case matches
        $exactCaseCount = substr_count($content, $query);
        $exactCaseMultiplier = $exactCaseCount > 0 ? 1.5 : 1.0;

        // Calculate base relevance
        $baseRelevance = $count / (strlen($content) / 1000);

        return ($baseRelevance * $exactCaseMultiplier) + $headingRelevance;
    }

    /**
     * Get the path to the markdown file
     */
    private function getMarkdownPath(string $file): string
    {
        return config('documentation.markdown.base_path').'/'.$file;
    }
}
