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
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;

#[Title('Unlink Emails')]
#[Description('Take emails off a company, person or opportunity. The unlink covers the copies of the conversation the user holds or was given in full, and no later sync puts it back on that record. The email itself is never deleted. Every pair is checked before anything is unlinked, and a refused pair unlinks nothing. Each item reports changed: false when the conversation was already off the record.')]
final class UnlinkEmailsTool extends Tool
{
    use LinksEmailsToRecords;

    public function handle(Request $request, UnlinkEmailFromRecord $unlink): Response|ResponseFactory
    {
        return $this->applyToEveryLink($request, false, $unlink);
    }
}
