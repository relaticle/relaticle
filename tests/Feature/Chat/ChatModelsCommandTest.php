<?php

declare(strict_types=1);

use Relaticle\Chat\Commands\ChatModelsCommand;

mutates(ChatModelsCommand::class);

it('lists the model registry via artisan', function (): void {
    $this->artisan('chat:models')
        ->expectsOutputToContain('claude-sonnet')
        ->expectsOutputToContain('ollama')
        ->assertExitCode(0);
});
