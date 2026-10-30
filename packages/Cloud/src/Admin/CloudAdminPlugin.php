<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin;

use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Relaticle\Cloud\Admin\Filament\Pages\Overview;
use Relaticle\Cloud\Admin\Filament\RelationManagers\SubscriptionsRelationManager;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource;
use Relaticle\Cloud\Admin\Filament\Support\EndTrial;
use Relaticle\Cloud\Admin\Filament\Support\ExtendTrial;
use Relaticle\Cloud\Admin\Filament\Support\ManagedPlan;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\CohortTable;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\ProblemsStats;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\ValueStats;
use Relaticle\SystemAdmin\Support\Extensions;

final class CloudAdminPlugin implements Plugin
{
    private const string THEME = 'packages/Cloud/resources/css/filament/admin/theme.css';

    public function getId(): string
    {
        return 'cloud-admin';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                SubscriptionResource::class,
                AiCreditBalanceResource::class,
                AiCreditTransactionResource::class,
            ])
            ->pages([Overview::class])
            ->widgets([
                MoneyStats::class,
                ValueStats::class,
                CohortTable::class,
                SalesLeads::class,
                ProblemsStats::class,
            ]);

        Extensions::addWorkspaceTableActions(static fn (): array => self::trialAndPlanActions());
        Extensions::addWorkspaceHeaderActions(static fn (): array => self::trialAndPlanActions());
        Extensions::addWorkspaceBulkActions(static fn (): array => [EndTrial::bulkAction()]);
        Extensions::addWorkspaceRelationManagers([SubscriptionsRelationManager::class]);
    }

    public function boot(Panel $panel): void
    {
        $panel->viteTheme(self::THEME);
    }

    /**
     * @return list<Action>
     */
    private static function trialAndPlanActions(): array
    {
        return [
            ExtendTrial::action(),
            EndTrial::action(),
            ManagedPlan::startAction(),
            ManagedPlan::withdrawAction(),
        ];
    }
}
