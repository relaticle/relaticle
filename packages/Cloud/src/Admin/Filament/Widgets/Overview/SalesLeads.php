<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Widgets\Overview;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Relaticle\Cloud\Admin\Enums\LeadStage;
use Relaticle\Cloud\Admin\Metrics\OverviewCache;
use Relaticle\Cloud\Admin\Metrics\SalesLeadsQuery;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;

final class SalesLeads extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public LeadStage $stage = LeadStage::Trialing;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SalesLeadsQuery::make($this->stage))
            ->heading(HelpLabel::make('Who should I talk to next?', 'Workspaces that use the product but do not pay: they have records of their own and no paid or Enterprise plan. Your own workspaces are left out. Pick a stage: trials about to end, trials that just ended, or free workspaces. Log outreach in the Relaticle HQ workspace.'))
            ->description(fn (): string => $this->stage->getHelp())
            ->headerActions(array_map($this->stageAction(...), LeadStage::cases()))
            ->paginated([10])
            ->columns([
                TextColumn::make('name')
                    ->label(__('cloud::admin.sales_leads.workspace'))
                    ->weight('semibold')
                    ->color('primary')
                    ->url(fn (Workspace $record): string => WorkspaceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('stage')
                    ->label(__('cloud::admin.sales_leads.stage'))
                    ->state(fn (Workspace $record): string => $this->stageOf($record))
                    ->badge()
                    ->color(fn (Workspace $record): string => $record->billingStatus()->getColor()),
                TextColumn::make('why')
                    ->label(__('cloud::admin.sales_leads.why'))
                    ->state(fn (Workspace $record): string => $this->why($record)),
                TextColumn::make('last_active')
                    ->label(__('cloud::admin.sales_leads.last_active'))
                    ->date(),
            ])
            ->recordActions([
                Impersonate::workspaceOwner()->label(__('cloud::admin.sales_leads.open_as_user')),
                Action::make('emailOwner')
                    ->label(__('cloud::admin.sales_leads.email_owner'))
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (Workspace $record): bool => $record->owner !== null)
                    ->url(fn (Workspace $record): string => "mailto:{$record->owner?->email}"),
            ]);
    }

    private function stageAction(LeadStage $stage): Action
    {
        $count = (int) (new OverviewCache)->remember("sales.count.{$stage->value}", fn (): int => SalesLeadsQuery::make($stage)->count());

        return Action::make("stage_{$stage->value}")
            ->label("{$stage->getLabel()} ({$count})")
            ->color(fn (): string => $this->stage === $stage ? 'primary' : 'gray')
            ->outlined(fn (): bool => $this->stage !== $stage)
            ->action(function () use ($stage): void {
                $this->stage = $stage;
                $this->resetPage();
            });
    }

    private function stageOf(Workspace $record): string
    {
        return match ($this->stage) {
            LeadStage::Trialing => $this->daysLeft($record->trial_ends_at),
            LeadStage::TrialEnded => $this->endedAgo($record->getAttribute('trial_ended_at')),
            LeadStage::Free => $record->billingStatus()->getLabel(),
        };
    }

    private function daysLeft(?CarbonImmutable $endsAt): string
    {
        $days = $endsAt instanceof CarbonImmutable ? (int) ceil(now()->diffInDays($endsAt)) : 0;

        return $days <= 0 ? 'Ends today' : "{$days} ".Str::plural('day', $days).' left';
    }

    private function endedAgo(mixed $endedAt): string
    {
        if ($endedAt === null) {
            return 'Trial ended';
        }

        $days = (int) floor(CarbonImmutable::parse((string) $endedAt)->diffInDays(now()));

        return $days <= 0 ? 'Ended today' : "Ended {$days} ".Str::plural('day', $days).' ago';
    }

    private function why(Workspace $record): string
    {
        $records = (int) $record->getAttribute('own_records');
        $activeDays = (int) $record->getAttribute('active_days_30');
        $credits = (int) $record->getAttribute('credits_used');
        $teammates = max(0, (int) $record->getAttribute('users_count'));
        $sources = explode(',', (string) $record->getAttribute('sources'));

        $parts = [
            number_format($records).' '.Str::plural('record', $records).(in_array('import', $sources, true) ? ' (imported)' : ''),
            number_format($activeDays).' active '.Str::plural('day', $activeDays),
        ];

        if ($credits > 0) {
            $parts[] = number_format($credits).' chat '.Str::plural('credit', $credits).' used';
        }

        if ($teammates > 0) {
            $parts[] = "{$teammates} ".Str::plural('teammate', $teammates);
        }

        foreach (['api' => 'uses API', 'mcp' => 'uses MCP'] as $source => $label) {
            if (in_array($source, $sources, true)) {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }
}
