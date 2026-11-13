<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Note\AttachNoteRelationships;
use App\Actions\Note\DetachNoteRelationships;
use App\Actions\Task\AttachTaskRelationships;
use App\Actions\Task\DetachTaskRelationships;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Support\CrmRelationshipSync;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class LinkedRecordActions
{
    public static function attach(): AttachAction
    {
        return AttachAction::make()
            ->authorize(fn (RelationManager $livewire): bool => Gate::allows('update', $livewire->getOwnerRecord()))
            ->using(fn (Model $record, RelationManager $livewire): Model => self::link($record, $livewire->getOwnerRecord()));
    }

    public static function detach(): DetachAction
    {
        return DetachAction::make()
            ->authorize(fn (mixed $record): bool => $record instanceof Model && Gate::allows('update', $record))
            ->using(fn (Model $record, RelationManager $livewire): Model => self::unlink($record, $livewire->getOwnerRecord()));
    }

    public static function detachBulk(): DetachBulkAction
    {
        return DetachBulkAction::make()
            ->authorize(fn (RelationManager $livewire): bool => Gate::allows('update', $livewire->getOwnerRecord()))
            ->authorizeIndividualRecords('update')
            ->using(fn (Collection $records, RelationManager $livewire): Collection => $records->each(
                fn (Model $record): Model => self::unlink($record, $livewire->getOwnerRecord()),
            ));
    }

    private static function link(Model $record, Model $owner): Model
    {
        $data = self::payload($owner);

        return match (true) {
            $record instanceof Task => resolve(AttachTaskRelationships::class)->execute(self::user(), $record, $data),
            $record instanceof Note => resolve(AttachNoteRelationships::class)->execute(self::user(), $record, $data),
            default => throw new InvalidArgumentException($record::class.' cannot be linked from a relation manager.'),
        };
    }

    private static function unlink(Model $record, Model $owner): Model
    {
        $data = self::payload($owner);

        return match (true) {
            $record instanceof Task => resolve(DetachTaskRelationships::class)->execute(self::user(), $record, $data),
            $record instanceof Note => resolve(DetachNoteRelationships::class)->execute(self::user(), $record, $data),
            default => throw new InvalidArgumentException($record::class.' cannot be unlinked from a relation manager.'),
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function payload(Model $owner): array
    {
        return [CrmRelationshipSync::payloadKeyFor($owner) => [$owner->getKey()]];
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
