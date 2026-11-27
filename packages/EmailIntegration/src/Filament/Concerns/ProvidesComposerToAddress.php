<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Services\ComposeRecordRecipientResolver;
use Relaticle\EmailIntegration\Support\ComposerPageTo;

trait ProvidesComposerToAddress
{
    abstract public function getRecord(): Model;

    public function renderingProvidesComposerToAddress(): void
    {
        if ($this->isGoneRecord()) {
            return;
        }

        ComposerPageTo::remember($this->getEmail(), $this->getRecord());
    }

    public function getEmail(): ?string
    {
        if ($this->isGoneRecord()) {
            return null;
        }

        return resolve(ComposeRecordRecipientResolver::class)->toAddressesFor($this->getRecord())[0] ?? null;
    }

    private function isGoneRecord(): bool
    {
        $record = $this->getRecord();

        return (method_exists($record, 'trashed') && $record->trashed())
            || $record->getAttribute('merged_into_id') !== null;
    }
}
