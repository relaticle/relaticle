<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Contracts;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailDeltaResult;

interface MailServiceInterface
{
    public function fetchDelta(string $cursor): MailDeltaResult;

    public function fetchMessage(string $providerMessageId): FetchedEmailData;

    /**
     * Run the first-time backfill and return the cursor to store on the account.
     *
     * @return array{message_ids: Collection<int, string>, cursor: string}
     */
    public function initialBackfill(int $daysBack): array;

    /**
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     body_text?: string,
     *     to: array<int, array{email: string, name: ?string}>,
     *     cc?: array<int, array{email: string, name: ?string}>,
     *     bcc?: array<int, array{email: string, name: ?string}>,
     *     from_name?: string,
     *     in_reply_to?: string,
     *     thread_id?: string,
     * } $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    public function sendMessage(array $data): array;
}
