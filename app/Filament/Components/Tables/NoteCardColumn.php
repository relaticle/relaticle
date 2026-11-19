<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Enums\CustomFields\NoteField;
use App\Filament\Components\RecordChip;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\User;
use App\Support\PlainText;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\Column;

final class NoteCardColumn extends Column
{
    protected string $view = 'filament.tables.columns.note-card-column';

    /**
     * @return array<int, RecordChip>
     */
    public function getLinkedRecordChips(): array
    {
        $note = $this->note();

        return [
            ...RecordChip::forRecords($note->people),
            ...RecordChip::forRecords($note->companies),
            ...RecordChip::forRecords($note->opportunities),
        ];
    }

    public function getExcerpt(): string
    {
        $body = $this->note()->customFieldValues
            ->first(fn (CustomFieldValue $value): bool => $value->customField->code === NoteField::BODY->value)
            ?->getValue();

        return is_string($body) ? PlainText::fromHtml($body) : '';
    }

    public function getCreatorChip(): ?RecordChip
    {
        $creator = $this->note()->creator;

        return $creator instanceof User && ! $this->note()->isSystemCreated()
            ? RecordChip::forRecord($creator)
            : null;
    }

    public function getCreatedLabel(): string
    {
        $createdAt = $this->note()->created_at?->setTimezone(FilamentTimezone::get());

        return match (true) {
            $createdAt === null => '',
            $createdAt->isToday() => __('filament/resources/note.cards.today'),
            $createdAt->isYesterday() => __('filament/resources/note.cards.yesterday'),
            default => $createdAt->isoFormat('LL'),
        };
    }

    private function note(): Note
    {
        /** @var Note */
        return $this->getRecord();
    }
}
