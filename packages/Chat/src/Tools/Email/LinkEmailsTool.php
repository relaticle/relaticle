<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\LinkAssistantEmail;

final class LinkEmailsTool extends BaseEmailLinkTool
{
    public function description(): string
    {
        return 'Propose putting emails on a company, person or opportunity, so each conversation shows on that record. Returns a proposal the user approves; nothing is linked until they do. A manual link stays through every later sync.';
    }

    protected function actionClass(): string
    {
        return LinkAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailLink->value;
    }

    protected function recordsDescription(): string
    {
        return 'Each item puts one email on one record.';
    }

    protected function scopeDescription(): string
    {
        return 'The whole conversation, every copy you hold or were given in full. It stays on the record through later syncs.';
    }
}
