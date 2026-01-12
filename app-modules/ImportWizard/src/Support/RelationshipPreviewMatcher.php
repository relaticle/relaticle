<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Data\RelationshipMatchResult;
use Relaticle\ImportWizard\Enums\MatchType;

/**
 * Matches relationship values to existing records during import preview.
 */
final class RelationshipPreviewMatcher
{
    /** @var array<string, array<string, Model>> */
    private array $byIdCache = [];

    private ?string $cachedTeamId = null;

    /**
     * Match a relationship value against existing records.
     *
     * @param  array<string, string>  $relationshipMapping  CSV column → matcher mappings
     * @param  array<string, mixed>  $rowData  The CSV row data
     */
    public function match(
        RelationshipField $field,
        array $relationshipMapping,
        array $rowData,
        string $teamId,
    ): RelationshipMatchResult {
        $this->ensureCacheLoaded($teamId);

        $csvColumn = $relationshipMapping['csvColumn'] ?? '';
        $matcherKey = $relationshipMapping['matcher'] ?? $field->defaultMatcher;

        // No mapping configured
        if ($csvColumn === '') {
            return new RelationshipMatchResult(
                relationshipName: $field->name,
                displayName: $field->label,
                matchType: MatchType::None,
                matcherUsed: '',
                icon: $field->icon,
            );
        }

        $value = trim((string) ($rowData[$csvColumn] ?? ''));

        // Empty value
        if ($value === '') {
            return new RelationshipMatchResult(
                relationshipName: $field->name,
                displayName: $field->label,
                matchType: MatchType::None,
                matcherUsed: $matcherKey,
                icon: $field->icon,
            );
        }

        $matcher = $field->getMatcher($matcherKey);
        if ($matcher === null) {
            return new RelationshipMatchResult(
                relationshipName: $field->name,
                displayName: $field->label,
                matchType: MatchType::None,
                matcherUsed: $matcherKey,
                icon: $field->icon,
            );
        }

        // Try to find a matching record
        $matchedRecord = $this->findRecord($field->targetEntity, $matcherKey, $value);

        if ($matchedRecord instanceof Model) {
            return new RelationshipMatchResult(
                relationshipName: $field->name,
                displayName: $field->label,
                matchType: $this->getMatchTypeForMatcher($matcherKey),
                matcherUsed: $matcherKey,
                matchedRecordId: (string) $matchedRecord->getKey(),
                matchedRecordName: $this->getRecordDisplayName($matchedRecord),
                icon: $field->icon,
            );
        }

        // No match found - check if matcher creates new records
        if ($matcher->createsNew) {
            return new RelationshipMatchResult(
                relationshipName: $field->name,
                displayName: $field->label,
                matchType: MatchType::New,
                matcherUsed: $matcherKey,
                matchedRecordName: $value,
                icon: $field->icon,
            );
        }

        // No match and no creation
        return new RelationshipMatchResult(
            relationshipName: $field->name,
            displayName: $field->label,
            matchType: MatchType::None,
            matcherUsed: $matcherKey,
            icon: $field->icon,
        );
    }

    private function ensureCacheLoaded(string $teamId): void
    {
        if ($this->cachedTeamId === $teamId) {
            return;
        }

        $this->cachedTeamId = $teamId;
        $this->byIdCache = [];

        // Load companies (ID matching only - names are not unique identifiers)
        $companies = Company::query()->where('team_id', $teamId)->get();
        $this->byIdCache['companies'] = $companies->keyBy(fn (Company $c): string => (string) $c->getKey())->all();

        // Load people
        $people = People::query()->where('team_id', $teamId)->get();
        $this->byIdCache['people'] = $people->keyBy(fn (People $p): string => (string) $p->getKey())->all();

        // Load opportunities
        $opportunities = Opportunity::query()->where('team_id', $teamId)->get();
        $this->byIdCache['opportunities'] = $opportunities->keyBy(fn (Opportunity $o): string => (string) $o->getKey())->all();
    }

    private function findRecord(string $targetEntity, string $matcherKey, string $value): ?Model
    {
        return match ($matcherKey) {
            'id' => Str::isUlid($value) ? ($this->byIdCache[$targetEntity][$value] ?? null) : null,
            // Name matcher always creates new - names are not unique identifiers
            'name' => null,
            // Email/domain/phone matching would require loading custom field values - simplified for preview
            'email', 'domain', 'phone' => null,
            default => null,
        };
    }

    private function getMatchTypeForMatcher(string $matcherKey): MatchType
    {
        return match ($matcherKey) {
            'id' => MatchType::Id,
            'domain' => MatchType::Domain,
            'email' => MatchType::Email,
            'phone' => MatchType::Phone,
            default => MatchType::New,
        };
    }

    private function getRecordDisplayName(Model $record): string
    {
        return $record->name ?? (string) $record->getKey();
    }
}
