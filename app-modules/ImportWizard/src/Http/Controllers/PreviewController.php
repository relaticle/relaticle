<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * API controller for import preview operations.
 */
final class PreviewController extends Controller
{
    /**
     * Get the current preview processing status.
     */
    public function status(string $sessionId): JsonResponse
    {
        $this->validateSession($sessionId);

        $enrichedPath = Storage::disk('local')->path("temp-imports/{$sessionId}/enriched.csv");

        return response()->json([
            'status' => Cache::get("import:{$sessionId}:status", 'pending'),
            'progress' => Cache::get("import:{$sessionId}:progress", [
                'processed' => 0,
                'creates' => 0,
                'updates' => 0,
                'total' => 0,
            ]),
            'hasEnrichedFile' => file_exists($enrichedPath),
        ]);
    }

    /**
     * Fetch a range of rows from the enriched CSV for virtual scroll.
     */
    public function rows(Request $request, string $sessionId): JsonResponse
    {
        $this->validateSession($sessionId);

        $enrichedPath = Storage::disk('local')->path("temp-imports/{$sessionId}/enriched.csv");

        if (! file_exists($enrichedPath)) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $start = $request->integer('start', 0);
        $limit = min($request->integer('limit', 100), 500);

        try {
            $csv = Reader::createFromPath($enrichedPath, 'r');
            $csv->setHeaderOffset(0);

            $rows = iterator_to_array(
                Statement::create()->offset($start)->limit($limit)->process($csv)
            );

            return response()->json([
                'rows' => array_values($rows),
                'start' => $start,
                'count' => count($rows),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Failed to read preview data'], 500);
        }
    }

    /**
     * Validate that the session belongs to the current team.
     */
    private function validateSession(string $sessionId): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        $teamId = $user?->currentTeam?->getKey();

        if ($teamId === null || Cache::get("import:{$sessionId}:team") !== $teamId) {
            abort(404, 'Session not found');
        }
    }
}
