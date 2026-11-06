<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Email;

use App\Mcp\Tools\Concerns\LinksEmailsToRecords;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Models\Email;

#[Title('Link Emails')]
#[Description('Put emails on a company, person or opportunity, so each conversation shows on that record. The link covers every copy of the conversation the user can see and stays through every later sync. Every pair is checked before anything is linked.')]
final class LinkEmailsTool extends Tool
{
    use LinksEmailsToRecords;

    public function handle(Request $request, LinkEmailToRecord $link): Response|ResponseFactory
    {
        return $this->applyToEveryLink($request, true, function (User $user, Email $email, Company|People|Opportunity $record) use ($link): void {
            $link->execute($user, $email, $record);
        });
    }

    protected function destructiveHint(): bool
    {
        return false;
    }
}
