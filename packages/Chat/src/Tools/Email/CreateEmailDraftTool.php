<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

final class CreateEmailDraftTool extends BaseEmailDraftTool
{
    public function description(): string
    {
        return 'Propose saving an email draft in one of the user\'s mailboxes. Returns a proposal the user approves; nothing is sent.'
            .' Once approved, the user reviews the draft and sends it from Drafts.'
            .' Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.';
    }
}
