<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Models\EmailTemplate;

/**
 * The email templates table, rendered inside the Templates tab of the email page.
 * Columns and row actions come from {@see EmailTemplateResource::table()}, the same
 * definition the standalone templates page uses.
 */
final class TemplatesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        $createTemplate = $this->createTemplateAction();

        return EmailTemplateResource::table($table)
            ->query(fn () => EmailTemplateResource::getEloquentQuery()
                ->where('team_id', filament()->getTenant()?->getKey()))
            ->headerActions([$createTemplate])
            ->emptyStateHeading(__('filament/resources/email-template.empty.heading'))
            ->emptyStateDescription(__('filament/resources/email-template.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentDuplicate)
            ->emptyStateActions([$createTemplate]);
    }

    private function createTemplateAction(): CreateAction
    {
        return CreateAction::make()
            ->model(EmailTemplate::class)
            ->label(__('filament/resources/email-template.actions.create.label'))
            ->icon('heroicon-o-plus')
            ->schema(fn (Schema $schema): Schema => EmailTemplateResource::form($schema))
            ->mutateFormDataUsing(function (array $data): array {
                $data['team_id'] = filament()->getTenant()?->getKey();
                $data['created_by'] = auth()->id();

                return $data;
            });
    }

    public function render(): View
    {
        return view('email-integration::livewire.table');
    }
}
