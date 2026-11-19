<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

#[Description('Serve the blog MCP server over stdio as the super administrator')]
#[Signature('blog:mcp')]
final class BlogMcpCommand extends Command
{
    public const string HANDLE = 'blog';

    public function handle(): int
    {
        $administrators = SystemAdministrator::query()
            ->where('role', SystemAdministratorRole::SuperAdministrator)
            ->limit(2)
            ->get();

        if ($administrators->count() !== 1) {
            $this->getOutput()->getErrorStyle()->writeln(
                "blog:mcp needs exactly one super administrator, found {$administrators->count()}."
            );

            return self::FAILURE;
        }

        // Ink asks a HasApiTokens caller for a token ability. There is no token over stdio, and the Gate still decides.
        $administrator = $administrators->first();
        $administrator->withAccessToken(new PersonalAccessToken(['abilities' => ['*']]));

        config(['ink.mcp.guard' => 'sysadmin']);
        Auth::guard('sysadmin')->setUser($administrator);

        return $this->call('mcp:start', ['handle' => self::HANDLE]);
    }
}
