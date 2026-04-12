<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

final readonly class CreateSignatureAction
{
    /**
     * @param  array{name: string, content_html: string, is_default: bool}  $data
     */
    public function execute(ConnectedAccount $account, array $data): EmailSignature
    {
        if ($data['is_default']) {
            EmailSignature::query()->where('connected_account_id', $account->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return EmailSignature::query()->create([
            'connected_account_id' => $account->getKey(),
            'user_id' => $account->user_id,
            'name' => $data['name'],
            'content_html' => $data['content_html'],
            'is_default' => $data['is_default'],
        ]);
    }
}
