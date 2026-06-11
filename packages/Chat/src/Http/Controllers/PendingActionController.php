<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\RecordReferenceResolver;
use RuntimeException;

final readonly class PendingActionController
{
    public function __construct(
        private PendingActionService $service,
        private RecordReferenceResolver $resolver,
    ) {}

    public function approve(Request $request, PendingAction $pendingAction): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($pendingAction->team_id !== $user->currentTeam->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if ($pendingAction->user_id !== $user->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        try {
            $result = $this->service->approve($pendingAction, $user);

            $this->ensureFilamentTenantContext($user);

            return response()->json([
                'status' => 'approved',
                'result_data' => $result->result_data,
                'record' => $this->resolveRecordReference($result),
                'records' => $this->resolveBatchRecordReferences($result),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Filament v5 panel routes embed the tenant slug, so URL generation requires
     * an active tenant. The /chat/actions/* routes don't run the panel middleware,
     * so we set it from the authenticated user's current team here.
     */
    private function ensureFilamentTenantContext(User $user): void
    {
        if (Filament::getTenant() !== null) {
            return;
        }

        $team = $user->currentTeam;

        if ($team === null) {
            return;
        }

        Filament::setTenant($team, isQuiet: true);
    }

    /**
     * @return array{id: string, type: string, url: string}|null
     */
    private function resolveRecordReference(PendingAction $pendingAction): ?array
    {
        $resultData = $pendingAction->result_data;
        $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;

        if (! is_string($recordId) && ! is_int($recordId)) {
            return null;
        }

        return $this->resolver->resolve($pendingAction->entity_type, (string) $recordId);
    }

    /**
     * @return list<array{id: string, type: string, url: string}>|null
     */
    private function resolveBatchRecordReferences(PendingAction $pendingAction): ?array
    {
        $resultData = $pendingAction->result_data;
        $ids = is_array($resultData) ? ($resultData['ids'] ?? null) : null;

        if (! is_array($ids) || $ids === []) {
            return null;
        }

        $refs = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            $ref = $this->resolver->resolve($pendingAction->entity_type, (string) $id);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs === [] ? null : $refs;
    }

    public function reject(Request $request, PendingAction $pendingAction): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($pendingAction->team_id !== $user->currentTeam->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if ($pendingAction->user_id !== $user->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        try {
            $this->service->reject($pendingAction);

            return response()->json(['status' => 'rejected']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function restore(Request $request, PendingAction $pendingAction): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($pendingAction->team_id !== $user->currentTeam->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if ($pendingAction->user_id !== $user->getKey()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        try {
            $result = $this->service->restore($pendingAction, $user);

            $this->ensureFilamentTenantContext($user);

            return response()->json([
                'status' => 'restored',
                'record' => $this->resolveDeletedRecordReference($result),
            ]);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'undo_window_expired') {
                return response()->json(['error' => 'undo_window_expired'], 410);
            }

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array{id: string, type: string, url: string}|null
     */
    private function resolveDeletedRecordReference(PendingAction $pendingAction): ?array
    {
        $ids = $pendingAction->action_data['_record_ids'] ?? null;

        // Only a single restored record gets a "View" link; a bulk restore has no one target.
        if (! is_array($ids) || count($ids) !== 1) {
            return null;
        }

        $recordId = array_values($ids)[0];

        if (! is_string($recordId) && ! is_int($recordId)) {
            return null;
        }

        return $this->resolver->resolve($pendingAction->entity_type, (string) $recordId);
    }
}
