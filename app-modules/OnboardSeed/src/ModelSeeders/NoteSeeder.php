<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Enums\CustomFields\NoteField as NoteCustomField;
use App\Models\Note;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;
use Relaticle\OnboardSeed\Support\FixtureRegistry;

final class NoteSeeder extends BaseModelSeeder
{
    protected string $modelClass = Note::class;

    protected string $entityType = 'notes';

    /**
     * @var array<string, string>
     */
    private array $entityTypeMap = [
        'company' => 'companies',
        'person' => 'people',
        'opportunity' => 'opportunities',
        'task' => 'tasks',
        'note' => 'notes',
    ];

    protected array $fieldCodes = [
        NoteCustomField::BODY->value,
    ];

    protected function createEntitiesFromFixtures(Team $team, Authenticatable $user): void
    {
        $fixtures = $this->loadEntityFixtures();

        foreach ($fixtures as $key => $data) {
            $noteableType = $data['noteable_type'] ?? null;
            $noteableKey = $data['noteable_key'] ?? null;

            if (! $noteableType || ! $noteableKey) {
                Log::warning("Missing noteable reference for note: {$key}");

                continue;
            }

            $registryKey = $this->getPluralEntityType($noteableType);
            $noteable = FixtureRegistry::get($registryKey, $noteableKey);

            if (! $noteable instanceof Model) {
                Log::warning("Noteable entity not found for note: {$key}, entity type: {$noteableType} (registry key: {$registryKey}), key: {$noteableKey}");

                continue;
            }

            $this->createNoteFromFixture($noteable, $user, $key, $data);
        }
    }

    private function getPluralEntityType(string $singularType): string
    {
        return $this->entityTypeMap[$singularType] ?? Str::plural($singularType);
    }

    /** @param  array<string, mixed>  $data */
    private function createNoteFromFixture(
        Model $noteable,
        Authenticatable $user,
        string $key,
        array $data
    ): Note {
        $customFields = $data['custom_fields'] ?? [];

        assert(method_exists($noteable, 'notes'));

        /** @var Note $note */
        $note = $noteable->notes()->create([
            'title' => $data['title'],
            'team_id' => $this->teamId,
            'creator_id' => $user->getAuthIdentifier(),
            ...$this->getGlobalAttributes(),
        ]);

        $this->applyCustomFields($note, $customFields);

        FixtureRegistry::register($this->entityType, $key, $note);

        return $note;
    }
}
