<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource;

final class ListAgentConversations extends ListRecords
{
    protected static string $resource = AgentConversationResource::class;
}
