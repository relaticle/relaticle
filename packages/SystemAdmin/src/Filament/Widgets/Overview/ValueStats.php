<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Scopes\ExternalWorkspace;
use Relaticle\SystemAdmin\Metrics\Scopes\FormedHabit;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;
use Relaticle\SystemAdmin\Metrics\Scopes\ReachedFirstValue;

final class ValueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Is anyone getting value?';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $week = now()->subDays(15)->startOfWeek(CarbonInterface::MONDAY);
        $signups = $this->signups($cache, $week);
        $change = $signups - $this->signups($cache, $week->subWeek());
        $firstValue = $this->signups($cache, $week, firstValue: true);
        $share = $signups === 0 ? null : (int) round($firstValue / $signups * 100);
        $currentWeek = now()->startOfWeek(CarbonInterface::MONDAY);
        $habits = $this->habits($cache, $currentWeek);
        $habitsBefore = $this->habits($cache, $currentWeek->subWeek());
        $filters = [
            'genuine_signup' => ['isActive' => true],
            'signed_up' => ['from' => $week->toDateString(), 'until' => $week->addDays(6)->toDateString()],
        ];

        return [
            Stat::make(HelpLabel::make('Real signups', 'New accounts from the latest full week that has had 7 days to act. Only verified people who signed up on their own count: invited teammates, suspected trial abuse and your own accounts are left out. The arrow compares with the week before.'), number_format($signups))
                ->description('Week of '.$week->format('M j').', '.($change >= 0 ? '+' : '').$change.' vs the week before')
                ->descriptionIcon(match (true) {
                    $change > 0 => 'heroicon-m-arrow-trending-up',
                    $change < 0 => 'heroicon-m-arrow-trending-down',
                    default => 'heroicon-m-minus',
                })
                ->color('gray')
                ->url(UserResource::getUrl('index', ['filters' => $filters])),
            Stat::make(HelpLabel::make('Reached first value', 'Share of that week\'s real signups who added their own record (a company, person, deal, task or note) within 7 days. Sample data does not count. Red under 20%, amber under 30%, green from 30%.'), $share === null ? "\u{2014}" : "{$share}%")
                ->description($signups === 0 ? 'No real signups that week' : "{$firstValue} of {$signups} added their own data within 7 days")
                ->color(match (true) {
                    $share === null => 'gray',
                    $share < 20 => 'danger',
                    $share < 30 => 'warning',
                    default => 'success',
                })
                ->url(UserResource::getUrl('index', ['filters' => [...$filters, 'reached_first_value' => ['isActive' => true]]])),
            Stat::make(HelpLabel::make('Formed a habit', 'Customer workspaces that were active in at least 3 of the last 4 full weeks. Active means someone added their own record or typed a chat message. Green when up on last week, amber when flat, red when down.'), number_format($habits))
                ->description(($habits - $habitsBefore >= 0 ? '+' : '').($habits - $habitsBefore).' vs a week earlier')
                ->color(match (true) {
                    $habits > $habitsBefore => 'success',
                    $habits === $habitsBefore => 'warning',
                    default => 'danger',
                })
                ->url(WorkspaceResource::getUrl('index', ['filters' => ['formed_habit' => ['isActive' => true]]])),
            ...(EmailIntegrationServiceProvider::enabled() ? [$this->mailboxes($cache)] : []),
        ];
    }

    private function mailboxes(OverviewCache $cache): Stat
    {
        $customers = (int) $cache->remember('value.mailboxes.customers', fn (): int => Workspace::query()
            ->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace)
            ->count());
        $connected = (int) $cache->remember('value.mailboxes.connected', fn (): int => Workspace::query()
            ->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace)
            ->withConnectedMailbox()
            ->count());
        $share = $customers === 0 ? 0 : (int) round($connected / $customers * 100);

        return Stat::make(HelpLabel::make('Connected a mailbox', 'Customer workspaces with at least one connected Gmail or Microsoft mailbox. A mailbox that needs sign-in or has a sync error still counts. A disconnected one does not.'), number_format($connected))
            ->description($customers === 0 ? 'No customer workspaces yet' : "{$share}% of {$customers} customer workspaces")
            ->color('gray')
            ->url(WorkspaceResource::getUrl('index', ['filters' => [
                'internal' => ['value' => '0'],
                'connected_mailbox' => ['isActive' => true],
            ]]));
    }

    private function signups(OverviewCache $cache, CarbonImmutable $week, bool $firstValue = false): int
    {
        $kind = $firstValue ? 'first_value' : 'signups';
        $zone = ViewerTime::timezone();

        return (int) $cache->remember("value.{$kind}.{$zone}.{$week->toDateString()}", function () use ($week, $firstValue): int {
            $query = User::query()
                ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
                ->where('users.created_at', '>=', ViewerTime::startOfDayUtc($week->toDateString()))
                ->where('users.created_at', '<=', ViewerTime::endOfDayUtc($week->addDays(6)->toDateString()));

            if ($firstValue) {
                $query->withGlobalScope(ReachedFirstValue::class, new ReachedFirstValue);
            }

            return $query->count();
        });
    }

    private function habits(OverviewCache $cache, CarbonImmutable $weekStart): int
    {
        return (int) $cache->remember("value.habits.{$weekStart->toDateString()}", fn (): int => Workspace::query()
            ->withGlobalScope(FormedHabit::class, new FormedHabit($weekStart))
            ->count());
    }
}
