<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Filament\Pages\ChatConversation;
use App\Models\Workspace;
use Relaticle\ImportWizard\Models\Import;

final readonly class ContinueWithSetupChat
{
    public function __invoke(Import $import): ?string
    {
        $workspace = $import->workspace;

        if (! $workspace instanceof Workspace
            || $workspace->onboarding_data_source?->isFile() !== true
            || (string) $workspace->user_id !== (string) auth()->id()) {
            return null;
        }

        $conversationId = $workspace->setupConversation()->value('id');

        return is_string($conversationId)
            ? ChatConversation::getUrl(['conversationId' => $conversationId, 'tenant' => $workspace])
            : null;
    }
}
