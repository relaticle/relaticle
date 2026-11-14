<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

trait LinksRecords
{
    private function linkedRecordJson(JsonResource $resource, Model $record, User $user): string
    {
        /** @var object{data: stdClass} $decoded */
        $decoded = json_decode($resource->toJson(), flags: JSON_THROW_ON_ERROR);

        $this->linkRecord($decoded->data, $record, $user);

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    private function linkRecord(stdClass $data, Model $record, User $user): void
    {
        $entity = CrmEntity::tryFromModel($record);
        $workspace = $user->currentWorkspace;

        if (! $entity instanceof CrmEntity || ! $workspace instanceof Workspace) {
            return;
        }

        $url = resolve(CanonicalRecordUrl::class)->build($entity, (string) $record->getKey(), $workspace);

        if ($url === null) {
            return;
        }

        $data->url = $url;
    }
}
