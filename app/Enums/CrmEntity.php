<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * The five CRM record types, keyed by their polymorphic morph alias.
 *
 * The alias is the storage vocabulary: `activity_log.subject_type` and
 * `custom_field_values.entity_type` hold it, so a case value cannot be renamed
 * without migrating data. `urlType()` is the separate citation vocabulary that the
 * search and fetch tools publish, where People is spelled `person` because that is
 * what reads correctly in a chat client's citation. Both are public contracts; this
 * enum exists to hold them together rather than have each call site re-derive one
 * from the other.
 */
enum CrmEntity: string
{
    case Company = 'company';
    case People = 'people';
    case Opportunity = 'opportunity';
    case Task = 'task';
    case Note = 'note';

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Company => Company::class,
            self::People => People::class,
            self::Opportunity => Opportunity::class,
            self::Task => Task::class,
            self::Note => Note::class,
        };
    }

    public function table(): string
    {
        $model = $this->model();

        return (new $model)->getTable();
    }

    /** The column holding the record's human-readable name. */
    public function titleColumn(): string
    {
        return match ($this) {
            self::Task, self::Note => 'title',
            self::Company, self::People, self::Opportunity => 'name',
        };
    }

    public function urlType(): string
    {
        return match ($this) {
            self::People => 'person',
            self::Company, self::Opportunity, self::Task, self::Note => $this->value,
        };
    }

    /** @return array<string, class-string<Model>> */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::cases() as $case) {
            $map[$case->value] = $case->model();
        }

        return $map;
    }

    /** @return list<string> */
    public static function morphAliases(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
