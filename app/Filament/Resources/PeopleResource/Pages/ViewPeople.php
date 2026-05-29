<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Filament\Actions\GenerateRecordSummaryAction;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\PeopleResource;
use App\Models\People;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Relaticle\ActivityLog\Filament\Actions\ActivityLogAction;
use Relaticle\CustomFields\Facades\CustomFields;

final class ViewPeople extends ViewRecord
{
    protected static string $resource = PeopleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateRecordSummaryAction::make(),
            ActivityLogAction::make(),
            Action::make('viewEmails')
                ->label(__('filament/resources/person.pages.view.actions.view_emails.label'))
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->url(fn (): string => PeopleResource::getUrl('emails', ['record' => $this->getRecord()])),
            EditAction::make()->icon('heroicon-o-pencil-square')->label(__('filament/resources/person.pages.view.actions.edit.label')),
            ActionGroup::make([
                ActionGroup::make([
                    Action::make('copyPageUrl')
                        ->label(__('filament/resources/person.pages.view.actions.copy_page_url.label'))
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (People $record): void {
                            $jsUrl = Js::from(PeopleResource::getUrl('view', [$record]));
                            $this->js("
                            navigator.clipboard.writeText({$jsUrl}).then(() => {
                                new FilamentNotification()
                                    .title('URL copied to clipboard')
                                    .success()
                                    .send()
                            })
                        ");
                        }),
                    Action::make('copyRecordId')
                        ->label(__('filament/resources/person.pages.view.actions.copy_record_id.label'))
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (People $record): void {
                            $jsId = Js::from((string) $record->getKey());
                            $this->js("
                            navigator.clipboard.writeText({$jsId}).then(() => {
                                new FilamentNotification()
                                    .title('Record ID copied to clipboard')
                                    .success()
                                    .send()
                            })
                        ");
                        }),
                ])->dropdown(false),
                DeleteAction::make(),
            ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                Flex::make([
                    ImageEntry::make('avatar')
                        ->label(__('filament/resources/person.pages.view.infolist.fields.avatar.label'))
                        ->height(30)
                        ->circular()
                        ->grow(false),
                    TextEntry::make('name')
                        ->label(__('filament/resources/person.pages.view.infolist.fields.name.label'))
                        ->size(TextSize::Large),
                    TextEntry::make('company.name')
                        ->label(__('filament/resources/person.pages.view.infolist.fields.company.label'))
                        ->color('primary')
                        ->url(fn (People $record): ?string => $record->company ? CompanyResource::getUrl('view', [$record->company]) : null),
                ]),
                CustomFields::infolist()->forSchema($schema)->build()->columnSpanFull(),
            ])->columnSpanFull(),

            Section::make('Communication Intelligence')
                ->icon(Heroicon::ChartBar)
                ->schema([
                    TextEntry::make('last_interaction_at')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.last_interaction.label'))
                        ->dateTime()
                        ->placeholder(__('filament/resources/person.pages.view.communication_intelligence.fields.last_interaction.placeholder')),

                    TextEntry::make('last_email_at')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.last_email.label'))
                        ->dateTime()
                        ->placeholder(__('filament/resources/person.pages.view.communication_intelligence.fields.last_email.placeholder')),

                    TextEntry::make('days_since_last_email')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.days_since_last_email.label'))
                        ->getStateUsing(fn (People $record): string => $record->last_email_at
                            ? __('filament/resources/person.pages.view.communication_intelligence.fields.days_since_last_email.value', ['days' => now()->diffInDays($record->last_email_at)])
                            : __('filament/resources/person.pages.view.communication_intelligence.fields.days_since_last_email.empty')
                        ),

                    TextEntry::make('email_count')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.email_count.label'))
                        ->default(0),

                    TextEntry::make('inbound_email_count')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.inbound_email_count.label')),

                    TextEntry::make('outbound_email_count')
                        ->label(__('filament/resources/person.pages.view.communication_intelligence.fields.outbound_email_count.label')),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(fn (People $record): bool => ($record->email_count ?? 0) === 0),
        ]);
    }
}
