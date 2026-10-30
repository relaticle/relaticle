<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Resources\RelationManagers\RelationManager;

final readonly class Extensions
{
    /**
     * @param  list<Closure(): array<int, Action|ActionGroup>>  $workspaceTableActions
     * @param  list<Closure(): array<int, BulkAction>>  $workspaceBulkActions
     * @param  list<Closure(): array<int, Action|ActionGroup>>  $workspaceHeaderActions
     * @param  list<class-string<RelationManager>>  $workspaceRelationManagers
     */
    public function __construct(
        private array $workspaceTableActions = [],
        private array $workspaceBulkActions = [],
        private array $workspaceHeaderActions = [],
        private array $workspaceRelationManagers = [],
    ) {}

    /** @param Closure(): array<int, Action|ActionGroup> $actions */
    public static function addWorkspaceTableActions(Closure $actions): void
    {
        app()->extend(self::class, static fn (self $extensions): self => new self(
            [...$extensions->workspaceTableActions, $actions],
            $extensions->workspaceBulkActions,
            $extensions->workspaceHeaderActions,
            $extensions->workspaceRelationManagers,
        ));
    }

    /** @param Closure(): array<int, BulkAction> $actions */
    public static function addWorkspaceBulkActions(Closure $actions): void
    {
        app()->extend(self::class, static fn (self $extensions): self => new self(
            $extensions->workspaceTableActions,
            [...$extensions->workspaceBulkActions, $actions],
            $extensions->workspaceHeaderActions,
            $extensions->workspaceRelationManagers,
        ));
    }

    /** @param Closure(): array<int, Action|ActionGroup> $actions */
    public static function addWorkspaceHeaderActions(Closure $actions): void
    {
        app()->extend(self::class, static fn (self $extensions): self => new self(
            $extensions->workspaceTableActions,
            $extensions->workspaceBulkActions,
            [...$extensions->workspaceHeaderActions, $actions],
            $extensions->workspaceRelationManagers,
        ));
    }

    /** @param list<class-string<RelationManager>> $relationManagers */
    public static function addWorkspaceRelationManagers(array $relationManagers): void
    {
        app()->extend(self::class, static fn (self $extensions): self => new self(
            $extensions->workspaceTableActions,
            $extensions->workspaceBulkActions,
            $extensions->workspaceHeaderActions,
            [...$extensions->workspaceRelationManagers, ...$relationManagers],
        ));
    }

    /** @return array<int, Action|ActionGroup> */
    public function workspaceTableActions(): array
    {
        return $this->build($this->workspaceTableActions);
    }

    /** @return array<int, BulkAction> */
    public function workspaceBulkActions(): array
    {
        return $this->build($this->workspaceBulkActions);
    }

    /** @return array<int, Action|ActionGroup> */
    public function workspaceHeaderActions(): array
    {
        return $this->build($this->workspaceHeaderActions);
    }

    /** @return list<class-string<RelationManager>> */
    public function workspaceRelationManagers(): array
    {
        return $this->workspaceRelationManagers;
    }

    /**
     * @template TAction of Action|ActionGroup|BulkAction
     *
     * @param  list<Closure(): array<int, TAction>>  $registered
     * @return array<int, TAction>
     */
    private function build(array $registered): array
    {
        return array_merge(...array_map(static fn (Closure $actions): array => $actions(), $registered));
    }
}
