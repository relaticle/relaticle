<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Scopes\AbuseSuspect;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;
use Relaticle\SystemAdmin\Metrics\Scopes\RecentGenuineWorkspace;
use Relaticle\SystemAdmin\Metrics\Scopes\StuckAfterSetup;

final class ProblemsStats extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = "What's going wrong?";

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $stats = [];

        if (BillingStatus::billingEnabled()) {
            $stats[] = $this->abuse($cache);
        }

        $stats[] = $this->stuck($cache);
        $stats[] = $this->leftWizard($cache);
        $stats[] = $this->thumbsDown($cache);

        if (EmailIntegrationServiceProvider::enabled()) {
            $stats[] = $this->mailboxes($cache);
        }

        return $stats;
    }

    public static function thumbsDownThisWeek(): int
    {
        return ChatMessageFeedback::query()
            ->where('rating', ChatMessageFeedback::RATING_DOWN)
            ->createdThisWeek()
            ->count();
    }

    private function abuse(OverviewCache $cache): Stat
    {
        $suspects = (int) $cache->remember('problems.abuse', fn (): int => Workspace::query()
            ->withGlobalScope(AbuseSuspect::class, new AbuseSuspect)
            ->count());
        $spending = (bool) $cache->remember('problems.abuse.spending', fn (): bool => AiCreditTransaction::query()
            ->where('type', AiCreditType::Chat)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('workspace_id', Workspace::query()->withGlobalScope(AbuseSuspect::class, new AbuseSuspect)->select('id'))
            ->exists());

        return Stat::make(HelpLabel::make('Trial abuse suspects', 'Workspaces still on the Pro trial with no records of their own that either spent at least half their chat credits on premium models or belong to an owner in a region our AI providers do not serve. Red when any of them spent credits in the last 7 days. Open the list to end their trials.'), number_format($suspects))
            ->description($spending ? 'Some are still spending credits' : 'None spent credits this week')
            ->color($suspects === 0 ? 'success' : ($spending ? 'danger' : 'warning'))
            ->url(WorkspaceResource::getUrl('index', ['filters' => ['abuse_suspect' => ['isActive' => true]]]));
    }

    private function stuck(OverviewCache $cache): Stat
    {
        $recent = (int) $cache->remember('problems.recent', fn (): int => Workspace::query()
            ->withGlobalScope(RecentGenuineWorkspace::class, new RecentGenuineWorkspace)
            ->count());
        $stuck = (int) $cache->remember('problems.stuck', fn (): int => Workspace::query()
            ->withGlobalScope(StuckAfterSetup::class, new StuckAfterSetup)
            ->count());
        $share = $recent === 0 ? null : (int) round($stuck / $recent * 100);

        return Stat::make(HelpLabel::make('Stuck after setup', 'Share of new owners, with a workspace 3 to 30 days old, who added no records of their own and typed no chat message in their first 3 days. Red from 60%, amber from 40%.'), $share === null ? "\u{2014}" : "{$share}%")
            ->description($share === null ? 'No owners 3 to 30 days old yet' : "{$stuck} of {$recent} new owners did nothing in their first 3 days")
            ->color(match (true) {
                $share === null => 'gray',
                $share >= 60 => 'danger',
                $share >= 40 => 'warning',
                default => 'success',
            })
            ->url(WorkspaceResource::getUrl('index', ['filters' => ['stuck_after_setup' => ['isActive' => true]]]));
    }

    private function leftWizard(OverviewCache $cache): Stat
    {
        $from = ViewerTime::today()->subDays(29)->toDateString();
        $until = ViewerTime::today()->toDateString();

        /** @var array{all: int, left: int, methods: array<string, int>} $counts */
        $counts = $cache->remember('problems.wizard.'.ViewerTime::timezone().".{$from}", function () use ($from, $until): array {
            $recent = User::query()
                ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
                ->where('users.created_at', '>=', ViewerTime::startOfDayUtc($from))
                ->where('users.created_at', '<=', ViewerTime::endOfDayUtc($until));
            $left = (clone $recent)->whereDoesntHave('ownedWorkspaces')->whereDoesntHave('workspaces');
            $leftCount = $left->count();
            $methods = ['Password' => $leftCount - (clone $left)->signedUpWith()->count()];

            foreach (UserSocialAccount::query()->distinct()->orderBy('provider_name')->pluck('provider_name') as $provider) {
                $methods[ucfirst((string) $provider)] = (clone $left)->signedUpWith((string) $provider)->count();
            }

            return ['all' => $recent->count(), 'left' => $leftCount, 'methods' => array_filter($methods)];
        });
        $share = $counts['all'] === 0 ? null : (int) round($counts['left'] / $counts['all'] * 100);

        return Stat::make(HelpLabel::make('Left the setup wizard', 'Share of real signups from the last 30 days who never created or joined a workspace, split by how they signed up. Amber from 10%.'), $share === null ? "\u{2014}" : "{$share}%")
            ->description($share === null ? 'No signups in the last 30 days' : "{$counts['left']} of {$counts['all']} signups in 30 days".$this->methodSplit($counts['methods']))
            ->color(match (true) {
                $share === null => 'gray',
                $share >= 10 => 'warning',
                default => 'success',
            })
            ->url(UserResource::getUrl('index', ['filters' => [
                'genuine_signup' => ['isActive' => true],
                'no_workspace' => ['isActive' => true],
                'signed_up' => ['from' => $from, 'until' => $until],
            ]]));
    }

    /**
     * @param  array<string, int>  $methods
     */
    private function methodSplit(array $methods): string
    {
        if ($methods === []) {
            return '';
        }

        return ': '.implode(', ', array_map(
            fn (string $method, int $count): string => "{$count} {$method}",
            array_keys($methods),
            $methods,
        ));
    }

    private function mailboxes(OverviewCache $cache): Stat
    {
        $failing = (int) $cache->remember('problems.mailboxes.failing', fn (): int => ConnectedAccount::query()->failing()->count());
        $stale = (int) $cache->remember('problems.mailboxes.stale', fn (): int => ConnectedAccount::query()->stale()->count());
        $connected = (int) $cache->remember('problems.mailboxes.connected', fn (): int => ConnectedAccount::query()->connected()->count());
        $count = $failing + $stale;

        return Stat::make(HelpLabel::make('Mailboxes needing attention', 'Connected mailboxes that stopped syncing: the provider returned an error, the owner has to sign in again, or an active mailbox has not synced for over an hour. Red when any has an error or needs sign-in, amber when the only problem is a late sync.'), number_format($count))
            ->description(match (true) {
                $connected === 0 => 'No mailboxes connected yet',
                $count === 0 => 'Every connected mailbox is syncing',
                default => "{$failing} failing, {$stale} late to sync",
            })
            ->color(match (true) {
                $connected === 0 => 'gray',
                $count === 0 => 'success',
                $failing > 0 => 'danger',
                default => 'warning',
            })
            ->url(ConnectedAccountResource::getUrl('index', ['filters' => ['needs_attention' => ['isActive' => true]]]));
    }

    private function thumbsDown(OverviewCache $cache): Stat
    {
        $week = now()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $count = (int) $cache->remember("problems.thumbs.{$week}", fn (): int => self::thumbsDownThisWeek());

        return Stat::make(HelpLabel::make('Thumbs down this week', 'Assistant answers that people rated thumbs down since Monday. Green at 0, amber at 1 or 2, red from 3. Open the list to read them.'), number_format($count))
            ->description('Answers people rated down since Monday')
            ->color(match (true) {
                $count === 0 => 'success',
                $count <= 2 => 'warning',
                default => 'danger',
            })
            ->url(ChatMessageFeedbackResource::getUrl('index', ['filters' => [
                'rating' => ['value' => ChatMessageFeedback::RATING_DOWN],
                'this_week' => ['isActive' => true],
            ]]));
    }
}
