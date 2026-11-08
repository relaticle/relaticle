<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

trait RendersRecordPicker
{
    abstract protected function pickerMessagesFile(): string;

    /**
     * @return array<string, string>
     */
    public function getPickerMessages(): array
    {
        $keys = ['search', 'searching', 'failed', 'none', 'empty', 'linked', 'more', 'result', 'results', 'remove'];

        return array_combine($keys, array_map(
            fn (string $key): string => __("{$this->pickerMessagesFile()}.{$key}"),
            $keys,
        ));
    }

    protected function recordPickerHtml(): string
    {
        return $this->wrapEmbeddedHtml(
            $this->wrapInputHtml(
                view('filament.forms.components.linked-records-select', ['field' => $this])->render(),
            ),
        );
    }
}
