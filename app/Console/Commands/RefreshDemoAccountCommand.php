<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoAccountSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

#[Description('Re-seed demo@relaticle.com used by Claude/ChatGPT directory reviewers.')]
#[Signature('app:refresh-demo-account')]
final class RefreshDemoAccountCommand extends Command
{
    public function handle(Application $app, DemoAccountSeeder $seeder): int
    {
        if ($app->isProduction()) {
            $this->error('Refusing to run in production: this command wipes demo team data.');

            return self::FAILURE;
        }

        $this->info('Refreshing demo account...');

        $seeder->setCommand($this);
        $seeder->run();

        $this->info('Demo account ready: '.DemoAccountSeeder::EMAIL);

        return self::SUCCESS;
    }
}
