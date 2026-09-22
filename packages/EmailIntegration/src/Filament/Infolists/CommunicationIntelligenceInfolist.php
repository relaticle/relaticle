<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists;

use App\Features\EmailIntegration;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Data\VisibleCommunicationIntelligence;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class CommunicationIntelligenceInfolist
{
    public static function section(): Section
    {
        $translationKey = 'filament/communication-intelligence';

        return Section::make(__("{$translationKey}.heading"))
            ->visible(fn (): bool => Feature::active(EmailIntegration::class))
            ->icon(Heroicon::ChartBar)
            ->compact()
            ->schema([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Fieldset::make(__("{$translationKey}.groups.connection"))
                            ->schema([
                                self::timestampEntry(
                                    'visible_first_interaction_at',
                                    'first_interaction',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->firstInteractionAt(),
                                    Heroicon::OutlinedClock,
                                ),
                                self::timestampEntry(
                                    'visible_last_interaction_at',
                                    'last_interaction',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->lastInteractionAt(),
                                    Heroicon::OutlinedClock,
                                ),
                                TextEntry::make('visible_connection_strength')
                                    ->label(__("{$translationKey}.fields.connection_strength.label"))
                                    ->getStateUsing(fn (People|Company|Opportunity $record): ConnectionStrength => self::metrics($record)->connectionStrength)
                                    ->badge(),
                                TextEntry::make('visible_strongest_connection')
                                    ->label(__("{$translationKey}.fields.strongest_connection.label"))
                                    ->getStateUsing(fn (People|Company|Opportunity $record): ?string => self::metrics($record)->strongestConnectionName)
                                    ->placeholder(__("{$translationKey}.fields.strongest_connection.placeholder"))
                                    ->icon(Heroicon::OutlinedUser),
                            ])
                            ->columns(1),
                        Fieldset::make(__("{$translationKey}.groups.email"))
                            ->schema([
                                self::timestampEntry(
                                    'visible_first_email_at',
                                    'first_email',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->firstEmailAt,
                                    Heroicon::OutlinedEnvelope,
                                ),
                                self::timestampEntry(
                                    'visible_last_email_at',
                                    'last_email',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->lastEmailAt,
                                    Heroicon::OutlinedEnvelope,
                                ),
                            ])
                            ->columns(1),
                        Fieldset::make(__("{$translationKey}.groups.calendar"))
                            ->schema([
                                self::timestampEntry(
                                    'visible_first_calendar_at',
                                    'first_calendar',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->firstMeetingAt,
                                    Heroicon::OutlinedCalendar,
                                ),
                                self::timestampEntry(
                                    'visible_last_calendar_at',
                                    'last_calendar',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->lastMeetingAt,
                                    Heroicon::OutlinedCalendar,
                                ),
                                self::timestampEntry(
                                    'visible_next_calendar_at',
                                    'next_calendar',
                                    fn (People|Company|Opportunity $record): ?CarbonInterface => self::metrics($record)->nextMeetingAt,
                                    Heroicon::OutlinedCalendar,
                                    withTime: true,
                                ),
                            ])
                            ->columns(1),
                    ]),
            ])
            ->columns(1)
            ->columnSpanFull()
            ->collapsible()
            ->collapsed();
    }

    /**
     * @param  Closure(People|Company|Opportunity): ?CarbonInterface  $state
     */
    private static function timestampEntry(
        string $name,
        string $field,
        Closure $state,
        Heroicon $icon,
        bool $withTime = false,
    ): TextEntry {
        $entry = TextEntry::make($name)
            ->label(__("filament/communication-intelligence.fields.{$field}.label"))
            ->getStateUsing($state)
            ->placeholder(__("filament/communication-intelligence.fields.{$field}.placeholder"))
            ->icon($icon);

        if ($withTime) {
            return $entry->dateTime();
        }

        return $entry->date();
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
