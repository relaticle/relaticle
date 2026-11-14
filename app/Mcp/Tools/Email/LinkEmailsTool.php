<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Mcp\Tools\Concerns\LinksEmailsToRecords;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;

#[Title('Link Emails')]
#[Description('Put emails on a company, person or opportunity, so each conversation shows on that record. The link covers the copies of the conversation the user holds or was given in full, and stays through every later sync. Every pair is checked before anything is linked, and a refused pair links nothing. Each item reports changed per copy: true when it pinned at least one of the user\'s copies to the record, false when every copy was already pinned.')]
final class LinkEmailsTool extends Tool
{
    use LinksEmailsToRecords;

    public function handle(Request $request, LinkEmailToRecord $link): Response|ResponseFactory
    {
        return $this->applyToEveryLink($request, true, $link);
    }

    protected function destructiveHint(): bool
    {
        return false;
    }
}
