<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Jobs\SendEmailJob;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class SendEmailAction
{
    /**
     * Dispatch a SendEmailJob for a single recipient.
     *
     * @param  array{
     *     connected_account_id: string,
     *     subject: string,
     *     body_html: string,
     *     to: array<array{email: string, name: ?string}>,
     *     cc?: array<array{email: string, name: ?string}>,
     *     bcc?: array<array{email: string, name: ?string}>,
     *     in_reply_to_email_id?: ?string,
     *     creation_source: EmailCreationSource,
     *     privacy_tier: EmailPrivacyTier,
     *     batch_id?: ?string,
     * }  $data
     * @param  class-string|null  $linkToType  Fully-qualified model class
     * @param  string|null  $linkToId  Model ULID
     */
    public function execute(
        array $data,
        ?string $linkToType = null,
        ?string $linkToId = null,
    ): void {
        dispatch(new SendEmailJob(emailData: $data, accountId: $data['connected_account_id'], batchId: $data['batch_id'] ?? null, linkToType: $linkToType, linkToId: $linkToId))->onQueue('emails');
    }
}
