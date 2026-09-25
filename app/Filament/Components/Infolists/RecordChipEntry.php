<?php

declare(strict_types=1);

namespace App\Filament\Components\Infolists;

use App\Filament\Components\RecordChip;
use App\Filament\Concerns\HasRecordChips;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;

final class RecordChipEntry extends TextEntry
{
    use HasRecordChips;

    private string $chipSize = 'md';

    public function chipSize(string $size): static
    {
        $this->chipSize = $size;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->html();
        $this->formatStateUsing(function (): HtmlString {
            $url = $this->evaluate($this->url);
            $url = is_string($url) && filled($url) ? $url : null;
            $openUrlInNewTab = $this->shouldOpenUrlInNewTab();

            return new HtmlString(implode('', array_map(
                fn (RecordChip $chip): string => ($url === null
                    ? $chip
                    : $chip->url($url, $openUrlInNewTab)
                )->toHtml(),
                $this->recordChips($this->getRecord(), $this->getName(), $this->chipSize),
            )));
        });
    }

    public function getUrl(mixed $state = null): ?string
    {
        return null;
    }
}
