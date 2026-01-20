<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Enums\CustomFields\CompanyField;
use App\Filament\Actions\GenerateRecordSummaryAction;
use App\Filament\Components\Infolists\AvatarName;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateRecordSummaryAction::make(),
            EditAction::make()->icon('heroicon-o-pencil-square')->label('Edit'),
            ActionGroup::make([
                ActionGroup::make([
                    Action::make('copyPageUrl')
                        ->label('Copy page URL')
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (Company $record): void {
                            $url = CompanyResource::getUrl('view', [$record]);
                            $this->js("
                            navigator.clipboard.writeText('{$url}').then(() => {
                                new FilamentNotification()
                                    .title('URL copied to clipboard')
                                    .success()
                                    .send()
                            })
                        ");
                        }),
                    Action::make('copyRecordId')
                        ->label('Copy record ID')
                        ->icon('heroicon-o-clipboard-document')
                        ->action(function (Company $record): void {
                            $id = $record->getKey();
                            $this->js("
                            navigator.clipboard.writeText('{$id}').then(() => {
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

    /**
     * Dispatch favicon fetch job if domains custom field has changed.
     *
     * @param  array<string, mixed>  $data
     */
    private function dispatchFaviconFetchIfNeeded(Company $company, array $data): void
    {
        $customFieldsData = $data['custom_fields'] ?? [];
        $newDomains = $customFieldsData[CompanyField::DOMAINS->value] ?? null;

        // Get the old domain value from the database
        $domainField = $company->customFields()
            ->whereBelongsTo($company->team)
            ->where('code', CompanyField::DOMAINS->value)
            ->first();

        $oldDomains = $domainField !== null ? $company->getCustomFieldValue($domainField) : null;

        // Only dispatch if domains changed and new value is not empty
        if ($newDomains !== $oldDomains && filled($newDomains)) {
            dispatch(new \App\Jobs\FetchFaviconForCompany($company))->afterCommit();
        }
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Flex::make([
                    Section::make([
                        Flex::make([
                            AvatarName::make('logo')
                                ->avatar('logo')
                                ->name('name')
                                ->avatarSize('lg')
                                ->textSize('xl')
                                ->square()
                                ->label(''),
                            AvatarName::make('creator')
                                ->avatar('creator.avatar')
                                ->name('creator.name')
                                ->avatarSize('sm')
                                ->textSize('sm')  // Default text size for creator
                                ->circular()
                                ->label('Created By'),
                            AvatarName::make('accountOwner')
                                ->avatar('accountOwner.avatar')
                                ->name('accountOwner.name')
                                ->avatarSize('sm')
                                ->textSize('sm')  // Default text size for account owner
                                ->circular()
                                ->label('Account Owner'),
                        ]),
                        CustomFields::infolist()->forSchema($schema)->build(),
                    ]),
                    Section::make([
                        TextEntry::make('created_at')
                            ->label('Created Date')
                            ->icon('heroicon-o-clock')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->icon('heroicon-o-clock')
                            ->dateTime(),
                    ])->grow(false),
                ])->columnSpan('full'),
            ]);
    }

    public function getRelationManagers(): array
    {
        return [
            PeopleRelationManager::class,
            TasksRelationManager::class,
            NotesRelationManager::class,
        ];
    }
}
