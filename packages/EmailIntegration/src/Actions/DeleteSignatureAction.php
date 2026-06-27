<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\EmailSignature;

final readonly class DeleteSignatureAction
{
    public function execute(EmailSignature $signature): bool
    {
        return (bool) $signature->delete();
    }
}
