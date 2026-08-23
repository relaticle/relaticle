<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\UpdateNote;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;

final class UpdateNoteTool extends BaseWriteUpdateTool
{
    use NormalizesToolInput;

    public function description(): string
    {
        return 'Propose updating an existing note. Returns a proposal for user approval.';
    }

    protected function modelClass(): string
    {
        return Note::class;
    }

    protected function actionClass(): string
    {
        return UpdateNote::class;
    }

    protected function entityType(): string
    {
        return 'note';
    }

    protected function entityLabel(): string
    {
        return 'Note';
    }

    protected function nameAttribute(): string
    {
        return 'title';
    }

    protected function ownedForeignKeyLists(): array
    {
        return [
            'company_ids' => Company::class,
            'people_ids' => People::class,
            'opportunity_ids' => Opportunity::class,
        ];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The new note title.'),
            'people_ids' => $schema->array()->description('People ULIDs to link. Pass null or [] to clear linked people.'),
            'company_ids' => $schema->array()->description('Company ULIDs to link. Pass null or [] to clear linked companies.'),
            'opportunity_ids' => $schema->array()->description('Opportunity ULIDs to link. Pass null or [] to clear linked opportunities.'),
        ];
    }

    protected function extractActionData(Request $request): array
    {
        $data = [];

        if (array_key_exists('title', $request->all())) {
            $data['title'] = $request['title'];
        }
        foreach (['people_ids', 'company_ids', 'opportunity_ids'] as $key) {
            if (! array_key_exists($key, $request->all())) {
                continue;
            }

            $data[$key] = $this->idListOrNull($request, $key);
        }

        return array_filter($data, static fn (mixed $v): bool => $v !== null);
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        throw_unless($model instanceof Note, \InvalidArgumentException::class, 'Expected a note model.');

        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $fields = [];
        if (array_key_exists('title', $request->all())) {
            $fields[] = ['label' => 'Title', 'old' => $model->getAttribute('title'), 'new' => $request['title']];
        }

        foreach ([
            ['people_ids', 'Linked people', 'people', People::class],
            ['company_ids', 'Linked companies', 'companies', Company::class],
            ['opportunity_ids', 'Linked opportunities', 'opportunities', Opportunity::class],
        ] as [$key, $label, $relation, $modelClass]) {
            $ids = $this->idListOrNull($request, $key);

            if ($ids === null) {
                continue;
            }

            $fields[] = [
                'label' => $label,
                'old' => $this->joinNames(array_values($model->{$relation}()->pluck('name')->all())),
                'new' => $this->namesForIds($ids, $modelClass, 'name', $team),
                '_oldValue' => array_map(strval(...), $model->{$relation}()->pluck($model->{$relation}()->getRelated()->getQualifiedKeyName())->all()),
                '_newValue' => $ids,
            ];
        }

        return [
            'title' => 'Update Note',
            'summary' => "Update note \"{$model->getAttribute('title')}\"",
            'fields' => $fields,
        ];
    }

    /**
     * @param  list<mixed>  $names
     */
    private function joinNames(array $names): string
    {
        $names = array_values(array_filter($names, static fn (mixed $name): bool => is_string($name) && $name !== ''));

        return $names === [] ? __('(none)') : implode(', ', $names);
    }

    /**
     * @param  list<string>  $ids
     * @param  class-string<Model>  $modelClass
     */
    private function namesForIds(array $ids, string $modelClass, string $nameAttribute, ?Team $team): string
    {
        if ($ids === []) {
            return __('(none)');
        }

        $instance = new $modelClass;
        $query = $modelClass::query()->whereIn($instance->getKeyName(), $ids);
        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return $query->pluck($nameAttribute)->implode(', ');
    }
}
