<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('filament/resources/email-template.fields.name.label'))
                    ->required()
                    ->maxLength(100),

                TextInput::make('subject')
                    ->label(__('filament/resources/email-template.fields.subject.label'))
                    ->required()
                    ->maxLength(255),

                RichEditor::make('body_html')
                    ->label(__('filament/resources/email-template.fields.body_html.label'))
                    ->required()
                    ->mergeTags(EmailTemplateRenderService::MERGE_TAGS)
                    ->activePanel('mergeTags')
                    ->toolbarButtons([
                        'bold', 'italic', 'underline', 'strike',
                        'link', 'bulletList', 'orderedList',
                        'blockquote', 'h2', 'h3', 'undo', 'redo',
                    ])
                    ->columnSpanFull(),

                Toggle::make('is_shared')
                    ->label(__('filament/resources/email-template.fields.is_shared.label'))
                    ->helperText(__('filament/resources/email-template.fields.is_shared.helper_text'))
                    ->disabled(fn (?EmailTemplate $record): bool => $record instanceof EmailTemplate && $record->created_by === null),
            ]);
    }
}
