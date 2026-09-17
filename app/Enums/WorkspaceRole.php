<?php

declare(strict_types=1);

namespace App\Enums;

use Laravel\Jetstream\Jetstream;

enum WorkspaceRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __("workspaces.roles.{$this->value}.label");
    }

    public static function keyIsAdmin(?string $key): bool
    {
        return self::tryFrom((string) $key) === self::Admin;
    }

    public static function description(string $role): ?string
    {
        $registeredRole = Jetstream::findRole($role);

        if ($registeredRole === null) {
            return null;
        }

        return $registeredRole->description;
    }

    /** @return array<int, WorkspaceCapability> */
    public function capabilities(): array
    {
        return match ($this) {
            self::Admin => [
                WorkspaceCapability::RecordsView,
                WorkspaceCapability::RecordsCreate,
                WorkspaceCapability::RecordsUpdate,
                WorkspaceCapability::RecordsDelete,
                WorkspaceCapability::RecordsForceDelete,
                WorkspaceCapability::DataImport,
                WorkspaceCapability::DataExport,
                WorkspaceCapability::MembersManage,
                WorkspaceCapability::FieldsManage,
                WorkspaceCapability::ActivityView,
            ],
            self::Member => [
                WorkspaceCapability::RecordsView,
                WorkspaceCapability::RecordsCreate,
                WorkspaceCapability::RecordsUpdate,
                WorkspaceCapability::RecordsDelete,
                WorkspaceCapability::DataImport,
                WorkspaceCapability::DataExport,
            ],
            self::Viewer => [WorkspaceCapability::RecordsView],
        };
    }
}
