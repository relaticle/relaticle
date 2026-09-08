<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Relaticle\EmailIntegration\Data\VisibleCommunicationIntelligence;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class CommunicationIntelligenceInfolist
{
    public static function section(): Section
    {
        $translationKey = 'filament/communication-intelligence';

        return Section::make(__("{$translationKey}.heading"))
            ->icon(Heroicon::ChartBar)
            ->schema([
                Section::make(__("{$translationKey}.groups.connection"))
                    ->schema([
                        TextEntry::make('visible_first_interaction_at')
                            ->label(__("{$translationKey}.fields.first_interaction.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->firstInteractionAt()?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.first_interaction.placeholder")),

                        TextEntry::make('visible_last_interaction_at')
                            ->label(__("{$translationKey}.fields.last_interaction.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->lastInteractionAt()?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.last_interaction.placeholder")),

                        TextEntry::make('visible_connection_strength')
                            ->label(__("{$translationKey}.fields.connection_strength.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): string => self::metrics($record)->connectionStrength->getLabel()),

                        TextEntry::make('visible_strongest_connection')
                            ->label(__("{$translationKey}.fields.strongest_connection.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->strongestConnectionName)
                            ->placeholder(__("{$translationKey}.fields.strongest_connection.placeholder")),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(),

                Fieldset::make(__("{$translationKey}.groups.email"))
                    ->schema([
                        TextEntry::make('visible_first_email_at')
                            ->label(__("{$translationKey}.fields.first_email.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->firstEmailAt?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.first_email.placeholder")),

                        TextEntry::make('visible_last_email_at')
                            ->label(__("{$translationKey}.fields.last_email.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->lastEmailAt?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.last_email.placeholder")),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Fieldset::make(__("{$translationKey}.groups.calendar"))
                    ->schema([
                        TextEntry::make('visible_first_calendar_at')
                            ->label(__("{$translationKey}.fields.first_calendar.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->firstMeetingAt?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.first_calendar.placeholder")),

                        TextEntry::make('visible_last_calendar_at')
                            ->label(__("{$translationKey}.fields.last_calendar.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->lastMeetingAt?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.last_calendar.placeholder")),

                        TextEntry::make('visible_next_calendar_at')
                            ->label(__("{$translationKey}.fields.next_calendar.label"))
                            ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->nextMeetingAt?->toDateTimeString())
                            ->dateTime()
                            ->placeholder(__("{$translationKey}.fields.next_calendar.placeholder")),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->columns(1)
            ->columnSpanFull()
            ->collapsible()
            ->collapsed(fn (People|Company|Opportunity $record): bool => ! self::metrics($record)->lastInteractionAt() instanceof Carbon);
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
