<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Relaticle\Cloud\Blog\BlogMcpStreams;
use Relaticle\Cloud\Blog\LocalStdioTransport;
use Relaticle\Ink\Mcp\BlogServer;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

#[Description('Serve the blog MCP server over stdio as the super administrator')]
#[Signature('blog:mcp')]
final class BlogMcpCommand extends Command
{
    public function handle(Container $container, BlogMcpStreams $streams): int
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

        $administratorId = $administrators->first()->getKey();
        $guard = Auth::guard(config('ink.mcp.guard'));
        $actAsAdministrator = function () use ($administratorId, $guard): void {
            $administrator = SystemAdministrator::query()->whereKey($administratorId)->first();

            if ($administrator === null) {
                $guard->forgetUser();

                return;
            }

            $guard->setUser($administrator->withAccessToken(new PersonalAccessToken(['abilities' => ['*']])));
        };

        $actAsAdministrator();

        $transport = new LocalStdioTransport($actAsAdministrator, $streams->input, $streams->output);
        $container->make(BlogServer::class, ['transport' => $transport])->start();
        $transport->run();

        return self::SUCCESS;
    }
}
