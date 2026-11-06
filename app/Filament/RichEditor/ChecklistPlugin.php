<?php

declare(strict_types=1);

namespace App\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Vite;
use Tiptap\Core\Extension;
use Tiptap\Nodes\TaskList;

final class ChecklistPlugin implements RichContentPlugin
{
    public static function make(): self
    {
        return resolve(self::class);
    }

    /** @return array<Extension> */
    public function getTipTapPhpExtensions(): array
    {
        return [new TaskList, new TaskItem];
    }

    /** @return array<string> */
    public function getTipTapJsExtensions(): array
    {
        return [Vite::asset('resources/js/filament/rich-content-plugins/checklist.js')];
    }

    /** @return array<RichEditorTool> */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('taskList')
                ->label(__('filament/rich-editor.slash_menu.items.taskList.label'))
                ->jsHandler('$getEditor()?.chain().focus().toggleTaskList().run()')
                ->toggle()
                ->icon(Heroicon::OutlinedCheckCircle),
        ];
    }

    /** @return array<Action> */
    public function getEditorActions(): array
    {
        return [];
    }
}
