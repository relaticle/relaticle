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
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Models\Email;

#[Title('Unlink Emails')]
#[Description('Take emails off a company, person or opportunity. The unlink covers every copy of the conversation the user can see, and no later sync puts it back on that record. The email itself is never deleted. Every pair is checked before anything is unlinked.')]
final class UnlinkEmailsTool extends Tool
{
    use LinksEmailsToRecords;

    public function handle(Request $request, UnlinkEmailFromRecord $unlink): Response|ResponseFactory
    {
        return $this->applyToEveryLink($request, false, function (User $user, Email $email, Company|People|Opportunity $record) use ($unlink): void {
            $unlink->execute($user, $email, $record);
        });
    }
}
