<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Scopes\ExternalWorkspace;

final class PlanStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Who is on which plan?';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return BillingStatus::billingEnabled();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $customers = (int) $cache->remember('plans.customers', fn (): int => $this->customers()->count());

        return [
            $this->plan($cache, Plan::Pro, $customers, 'Customer workspaces on Cloud Pro, including the ones still on the 14-day trial.'),
            $this->plan($cache, Plan::Scale, $customers, 'Customer workspaces on Cloud Scale, the plan with more credits, more mailboxes and '.Plan::Scale->throughputMultiplier().'x API throughput.'),
            $this->plan($cache, Plan::Enterprise, $customers, 'Customer workspaces on an Enterprise grant, started by hand from the workspace list and invoiced outside self-serve checkout.'),
            $this->packs($cache),
            ...(EmailIntegrationServiceProvider::enabled() ? [$this->mailboxCap($cache)] : []),
        ];
    }

    private function plan(OverviewCache $cache, Plan $plan, int $customers, string $help): Stat
    {
        $count = (int) $cache->remember("plans.on.{$plan->value}", fn (): int => $this->customers()
            ->where('plan', $plan->value)
            ->count());
        $share = $customers === 0 ? 0 : (int) round($count / $customers * 100);

        return Stat::make(HelpLabel::make($plan->marketingLabel(), $help), number_format($count))
            ->description($count === 0 ? 'No customer workspaces yet' : "{$share}% of {$customers} customer workspaces")
            ->color($count === 0 ? 'gray' : $plan->getColor())
            ->url(WorkspaceResource::getUrl('index', ['filters' => [
                'internal' => ['value' => '0'],
                'plan' => ['value' => $plan->value],
            ]]));
    }

    private function packs(OverviewCache $cache): Stat
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $zone = ViewerTime::timezone();
        $purchases = fn (): Builder => AiCreditTransaction::query()
            ->where('type', AiCreditType::Purchase->value)
            ->where('created_at', '>=', ViewerTime::startOfDayUtc($monthStart))
            ->whereIn('workspace_id', $this->customers()->select('id'));
        $packs = (int) $cache->remember("plans.packs.{$zone}.{$monthStart}", fn (): int => $purchases()->count());
        $credits = (int) $cache->remember("plans.pack_credits.{$zone}.{$monthStart}", fn (): int => (int) $purchases()->sum('credits_charged'));

        return Stat::make(HelpLabel::make('Credit packs this month', 'Credit packs customers bought since the first of the month, on your calendar. A pack is the expansion path on every plan: a workspace that keeps buying them is a Scale or Enterprise prospect.'), number_format($packs))
            ->description($packs === 0 ? 'No packs bought this month' : number_format($credits).' credits bought')
            ->color($packs === 0 ? 'gray' : 'success')
            ->url(AiCreditTransactionResource::getUrl('index', ['filters' => [
                'type' => ['value' => AiCreditType::Purchase->value],
                'date_range' => ['from' => $monthStart],
            ]]));
    }

    private function mailboxCap(OverviewCache $cache): Stat
    {
        $capped = (int) $cache->remember('plans.mailbox_cap', fn (): int => $this->customers()
            ->atMailboxCap()
            ->count());
        $pro = Plan::Pro->connectedMailboxes();
        $scale = Plan::Scale->connectedMailboxes();

        return Stat::make(HelpLabel::make('At the mailbox cap', "Customer workspaces on Cloud Pro or Cloud Scale that have connected every mailbox their plan allows ({$pro} on Pro, {$scale} on Scale). They cannot connect another one without upgrading. A mailbox that needs sign-in still counts. A disconnected one does not."), number_format($capped))
            ->description($capped === 0 ? 'Nobody is blocked by the cap' : 'Blocked from connecting another mailbox')
            ->color($capped === 0 ? 'gray' : 'warning')
            ->url(WorkspaceResource::getUrl('index', ['filters' => [
                'internal' => ['value' => '0'],
                'at_mailbox_cap' => ['isActive' => true],
            ]]));
    }

    /**
     * @return Builder<Workspace>
     */
    private function customers(): Builder
    {
        return Workspace::query()->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace);
    }
}
