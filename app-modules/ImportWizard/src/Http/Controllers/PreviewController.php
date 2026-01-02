<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Relaticle\ImportWizard\Data\ImportSessionData;

/**
 * API controller for import preview operations.
 */
final class PreviewController extends Controller
{
    public function status(string $sessionId): JsonResponse
    {
        $data = $this->validateSession($sessionId);
        $data->refresh($sessionId);

        return response()->json([
            'status' => $data->status(),
            'progress' => [
                'processed' => $data->processed,
                'creates' => $data->creates,
                'updates' => $data->updates,
                'total' => $data->total,
            ],
            'hasEnrichedFile' => file_exists(Storage::disk('local')->path("temp-imports/{$sessionId}/enriched.csv")),
        ]);
    }

    public function rows(Request $request, string $sessionId): JsonResponse
    {
        $data = $this->validateSession($sessionId);
        $data->refresh($sessionId);

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

    private function validateSession(string $sessionId): ImportSessionData
    {
        /** @var User|null $user */
        $user = auth()->user();
        $teamId = $user?->currentTeam?->getKey();

        abort_if($teamId === null, 404, 'Session not found');

        $data = ImportSessionData::find($sessionId);

        abort_if(! $data instanceof ImportSessionData || $data->teamId !== $teamId, 404, 'Session not found');

        return $data;
    }
}
