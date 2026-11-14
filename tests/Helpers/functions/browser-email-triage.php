<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

function triageBrowserInbound(ConnectedAccount $mailbox, string $key, mixed $sentAt): void
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => $sentAt,
    ]);

    EmailParticipant::factory()->from()->create(['email_id' => $email->id, 'email_address' => 'brian@airbnb.test', 'name' => 'Brian']);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key, broadcast: false);
}
