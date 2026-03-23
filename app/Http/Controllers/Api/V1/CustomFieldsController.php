<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexCustomFieldsRequest;
use App\Http\Resources\V1\CustomFieldResource;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Custom Fields
 *
 * List custom field definitions configured for your workspace.
 */
final readonly class CustomFieldsController
{
    #[ResponseFromApiResource(CustomFieldResource::class, CustomField::class, collection: true, paginate: 15)]
    public function index(IndexCustomFieldsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $teamId = $user->currentTeam->getKey();

        $query = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->active()
            ->with(['options' => fn (HasMany $q) => $q->withoutGlobalScopes()]);

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        $perPage = (int) $request->query('per_page', '15');
        $perPage = max(1, min($perPage, $request->maxPerPage()));

        return CustomFieldResource::collection($query->paginate($perPage));
    }
}
