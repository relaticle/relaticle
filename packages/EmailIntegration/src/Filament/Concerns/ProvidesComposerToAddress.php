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
        ComposerPageTo::remember($this->getEmail());
    }

    public function getEmail(): ?string
    {
        return resolve(ComposeRecordRecipientResolver::class)->toAddressesFor($this->getRecord())[0] ?? null;
    }
}
