<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\RichContentAttachments;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;

final class RichEditorComponent extends RichEditor
{
    public function asDocument(): static
    {
        return $this
            ->placeholder(null)
            ->extraAttributes(['class' => 'fi-fo-rich-editor-seamless'], merge: true);
    }

    public function callAfterStateHydrated(): static
    {
        parent::callAfterStateHydrated();
        $state = $this->getState();

        if (is_string($state)) {
            $this->state(RichContentAttachments::forWorkspace((string) Filament::getTenant()?->getKey())->rewriteAttachmentUrls($state));
        }

        return $this;
    }
}
