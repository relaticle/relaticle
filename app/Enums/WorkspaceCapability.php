<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceCapability: string
{
    case RecordsView = 'records.view';
    case RecordsCreate = 'records.create';
    case RecordsUpdate = 'records.update';
    case RecordsDelete = 'records.delete';
    case RecordsForceDelete = 'records.force_delete';
    case DataImport = 'data.import';
    case DataExport = 'data.export';
    case MembersManage = 'members.manage';
    case MembersPromoteAdmin = 'members.promote_admin';
    case FieldsManage = 'fields.manage';
    case BillingManage = 'billing.manage';
    case WorkspaceManage = 'workspace.manage';
    case ActivityView = 'activity.view';

    public function label(): string
    {
        return __("workspaces.capabilities.{$this->value}.label");
    }

    /** @return array<int, self> */
    public static function forOwner(): array
    {
        return self::cases();
    }
}
