<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\UnlinkAssistantEmail;

final class UnlinkEmailsTool extends BaseEmailLinkTool
{
    public function description(): string
    {
        return 'Propose taking emails off a company, person or opportunity. Returns a proposal the user approves; nothing changes until they do. Once unlinked, no later sync puts the conversation back on that record. The email itself is never deleted.';
    }

    protected function actionClass(): string
    {
        return UnlinkAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailUnlink->value;
    }

    protected function recordsDescription(): string
    {
        return 'Each item takes one email off one record.';
    }
}
