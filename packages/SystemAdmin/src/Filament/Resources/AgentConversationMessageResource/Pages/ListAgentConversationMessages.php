<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource;

final class ListAgentConversationMessages extends ListRecords
{
    protected static string $resource = AgentConversationMessageResource::class;
}
