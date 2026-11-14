<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RichContent;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Support\Facades\Vite;
use Relaticle\EmailIntegration\Services\EmailVariableCatalogue;
use Tiptap\Core\Extension;

final readonly class ComposerCommandsPlugin implements RichContentPlugin
{
    /**
     * @param  list<array{id: string, label: string, kind: string}>  $commands
     * @return array<string, string>
     */
    public static function attributes(User $user, array $commands = []): array
    {
        return ['data-email-commands' => base64_encode((string) json_encode([
            'variables' => resolve(EmailVariableCatalogue::class)->variables($user),
            'commands' => $commands,
            'labels' => [
                'insert' => __('filament/emails/composer.variables.insert'),
                'editFallback' => __('filament/emails/composer.variables.edit_fallback'),
                'fallback' => __('filament/emails/composer.variables.fallback'),
                'save' => __('filament/emails/composer.variables.save'),
                'unknown' => __('filament/emails/composer.variables.unknown'),
                'noResults' => __('filament/emails/composer.variables.no_results'),
            ],
        ]))];
    }

    /** @return array<Extension> */
    public function getTipTapPhpExtensions(): array
    {
        return [new EmailVariableNode];
    }

    /** @return array<string> */
    public function getTipTapJsExtensions(): array
    {
        return [Vite::asset('resources/js/filament/rich-content-plugins/email-composer.js')];
    }

    /** @return array<RichEditorTool> */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('attachFiles')
                ->label(__('filament/emails/composer.actions.insert_image'))
                ->icon('lucide-image')
                ->action(),
        ];
    }

    /** @return array<Action> */
    public function getEditorActions(): array
    {
        return [];
    }
}
