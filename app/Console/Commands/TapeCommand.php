<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Run Horizon, Reverb, logs, the scheduler, and the default local processes')]
#[Signature('tape
        {--s|stream : Start in stream mode}
        {--t|tabs : Start in tabs mode}
        {--i|inline : Print output inline instead of rendering the TUI (the default when not a TTY)}
        {--timestamps : Display timestamps on each output line}
        {--no-restart : Disable auto-restart on crash}
        {--json : Emit newline-delimited JSON events. Implies --inline}
        {--buffer-size= : Set the max lines per command buffer}
        {--stream-buffer-size= : Set the max lines in the stream buffer}')]
final class TapeCommand extends Command
{
    public function handle(): int
    {
        $parameters = [];

        foreach (['stream', 'tabs', 'inline', 'timestamps', 'no-restart', 'json'] as $option) {
            if ($this->option($option) === true) {
                $parameters["--{$option}"] = true;
            }
        }

        foreach (['buffer-size', 'stream-buffer-size'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $parameters["--{$option}"] = $value;
            }
        }

        return $this->call('dev', $parameters);
    }
}
