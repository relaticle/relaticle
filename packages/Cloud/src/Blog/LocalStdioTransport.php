<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog;

use Closure;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Server\Transport\StdioTransport;

final class LocalStdioTransport extends StdioTransport
{
    private bool $clientConnected = true;

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function __construct(
        private readonly Closure $beforeMessage,
        private $input,
        private $output,
    ) {
        parent::__construct();
    }

    public function onReceive(Closure $handler): void
    {
        parent::onReceive(function (string $line) use ($handler): void {
            ($this->beforeMessage)();
            $handler($line);
        });
    }

    public function send(string $message): void
    {
        if (! $this->clientConnected) {
            return;
        }

        // A client that hung up turns this write into an EPIPE notice; that is the session ending, not an error.
        $this->clientConnected = @fwrite($this->output, $message.PHP_EOL) !== false;
    }

    public function run(): void
    {
        stream_set_blocking($this->input, false);

        $buffer = '';

        while ($this->clientConnected && ! feof($this->input)) {
            $chunk = fgets($this->input);

            if ($chunk === false) {
                Sleep::usleep(10000);

                continue;
            }

            $buffer .= $chunk;

            if (str_ends_with($buffer, "\n")) {
                $this->handle($buffer);
                $buffer = '';
            }
        }

        if ($this->clientConnected) {
            $this->handle($buffer);
        }
    }

    private function handle(string $line): void
    {
        if (trim($line) !== '' && is_callable($this->handler)) {
            ($this->handler)($line);
        }
    }
}
