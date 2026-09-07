<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Relaticle\EmailIntegration\Data\VisibleCommunicationIntelligence;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class CommunicationIntelligenceInfolist
{
    public static function section(
        string $translationKey,
        bool $includeDirectionCounts = true,
    ): Section {
        return Section::make(__("{$translationKey}.heading"))
            ->icon(Heroicon::ChartBar)
            ->schema([
                TextEntry::make('visible_last_interaction_at')
                    ->label(__("{$translationKey}.fields.last_interaction.label"))
                    ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->lastInteractionAt()?->toDateTimeString())
                    ->dateTime()
                    ->placeholder(__("{$translationKey}.fields.last_interaction.placeholder")),

                TextEntry::make('visible_last_email_at')
                    ->label(__("{$translationKey}.fields.last_email.label"))
                    ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->lastEmailAt?->toDateTimeString())
                    ->dateTime()
                    ->placeholder(__("{$translationKey}.fields.last_email.placeholder")),

                TextEntry::make('visible_days_since_last_email')
                    ->label(__("{$translationKey}.fields.days_since_last_email.label"))
                    ->getStateUsing(function (People|Company|Opportunity $record) use ($translationKey): string {
                        $lastEmailAt = self::metrics($record)->lastEmailAt;

                        return $lastEmailAt
                            ? __("{$translationKey}.fields.days_since_last_email.value", ['days' => (int) now()->diffInDays($lastEmailAt, true)])
                            : __("{$translationKey}.fields.days_since_last_email.empty");
                    }),

                TextEntry::make('visible_email_count')
                    ->label(__("{$translationKey}.fields.email_count.label"))
                    ->getStateUsing(fn (People|Company|Opportunity $record): int => self::metrics($record)->emailCount)
                    ->default(0),

                ...($includeDirectionCounts ? [
                    TextEntry::make('visible_inbound_email_count')
                        ->label(__("{$translationKey}.fields.inbound_email_count.label"))
                        ->getStateUsing(fn (People|Company|Opportunity $record): int => self::metrics($record)->inboundEmailCount),

                    TextEntry::make('visible_outbound_email_count')
                        ->label(__("{$translationKey}.fields.outbound_email_count.label"))
                        ->getStateUsing(fn (People|Company|Opportunity $record): int => self::metrics($record)->outboundEmailCount),
                ] : []),
            ])
            ->columns($includeDirectionCounts ? 3 : 2)
            ->columnSpanFull()
            ->collapsible()
            ->collapsed(fn (People|Company|Opportunity $record): bool => self::metrics($record)->emailCount === 0);
    }

    /**
     * Filament evaluates each TextEntry and the collapsed callback separately, and
     * schema components do not share loaded infolist state. Cache on the current
     * request, keyed by record and viewer, so one render runs the aggregates once
     * without leaking across records, users, Livewire requests, or tests.
     */
    private static function metrics(People|Company|Opportunity $record): VisibleCommunicationIntelligence
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        $key = sprintf(
            'email-integration.visible-communication-intelligence.%s.%s.%s',
            $record::class,
            $record->getKey(),
            $viewer->getKey(),
        );

        $cached = request()->attributes->get($key);

        if ($cached instanceof VisibleCommunicationIntelligence) {
            return $cached;
        }

        $metrics = resolve(EmailVisibilityService::class)->visibleCommunicationIntelligence($record, $viewer);
        request()->attributes->set($key, $metrics);

        return $metrics;
    }
}
