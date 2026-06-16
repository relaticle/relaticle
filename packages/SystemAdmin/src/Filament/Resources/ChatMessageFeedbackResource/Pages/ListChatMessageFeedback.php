<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource;
use Relaticle\SystemAdmin\Filament\Widgets\ChatFeedbackStatsWidget;

final class ListChatMessageFeedback extends ListRecords
{
    protected static string $resource = ChatMessageFeedbackResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ChatFeedbackStatsWidget::class,
        ];
    }
}
