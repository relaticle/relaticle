<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Relaticle\ImportWizard\Infrastructure\ImportCache;
use Relaticle\ImportWizard\Services\ValueValidationService;

/**
 * API controller for managing import value corrections.
 *
 * This bypasses Livewire's state sync to avoid PayloadTooLargeException
 * when dealing with large datasets.
 */
final class ImportCorrectionsController extends Controller
{
    public function __construct(
        private readonly ImportCache $cache,
        private readonly ValueValidationService $validation,
    ) {}

    /**
     * Store or update a correction.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'old_value' => ['required', 'string'],
            'new_value' => ['present', 'nullable', 'string'],
            'csv_column' => ['nullable', 'string'],
        ]);

        $sessionId = $validated['session_id'];
        $fieldName = $validated['field_name'];
        $oldValue = $validated['old_value'];
        $newValue = $validated['new_value'] ?? '';
        $csvColumn = $validated['csv_column'] ?? null;

        // Store correction using centralized cache
        $this->cache->setCorrection($sessionId, $fieldName, $oldValue, $newValue);

        // Validate the new value and return any issue
        // Skip validation for empty values (skipped rows)
        $issue = null;
        if ($newValue !== '' && $csvColumn !== null) {
            $issue = $this->validation->validateCorrectedValue($sessionId, $csvColumn, $newValue);
        }

        return response()->json([
            'success' => true,
            'isSkipped' => $newValue === '',
            'issue' => $issue,
        ]);
    }

    /**
     * Remove a correction (unskip).
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'old_value' => ['required', 'string'],
        ]);

        $sessionId = $validated['session_id'];
        $fieldName = $validated['field_name'];
        $oldValue = $validated['old_value'];

        // Remove correction using centralized cache
        $this->cache->removeCorrection($sessionId, $fieldName, $oldValue);

        return response()->json([
            'success' => true,
        ]);
    }
}
