<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\EmailSignature;

final readonly class UpdateSignatureAction
{
    /**
     * @param  array{name?: string, content_html?: string, is_default?: bool}  $data
     */
    public function execute(EmailSignature $signature, array $data): EmailSignature
    {
        if (($data['is_default'] ?? false) === true) {
            EmailSignature::query()->where('connected_account_id', $signature->connected_account_id)
                ->where('id', '!=', $signature->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $signature->update($data);

        return $signature->refresh();
    }
}
