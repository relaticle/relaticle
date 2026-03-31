<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Relaticle\Documentation\Data\DocumentSearchRequest;
use Relaticle\Documentation\Services\DocumentationService;

final readonly class DocumentationController
{
    public function __construct(
        private DocumentationService $documentationService
    ) {}

    /**
     * Display the documentation index page
     */
    public function index(): View
    {
        return view('documentation::index', [
            'documentTypes' => $this->documentationService->getAllDocumentTypes(),
        ]);
    }

    /**
     * Display a specific documentation page
     */
    public function show(string $type = 'business'): View
    {
        $document = $this->documentationService->getDocument($type);

        return view('documentation::show', [
            'document' => $document,
            'content' => $document->content,
            'tableOfContents' => $document->tableOfContents,
            'currentType' => $document->type,
            'documentTitle' => $document->title,
            'documentTypes' => $this->documentationService->getAllDocumentTypes(),
        ]);
    }

    /**
     * Search documentation
     */
    public function search(Request $request): View
    {
        $searchRequest = DocumentSearchRequest::from($request);
        $results = $this->documentationService->search($searchRequest);

        return view('documentation::search', [
            'results' => $results,
            'query' => $searchRequest->query,
            'documentTypes' => $this->documentationService->getAllDocumentTypes(),
        ]);
    }
}
