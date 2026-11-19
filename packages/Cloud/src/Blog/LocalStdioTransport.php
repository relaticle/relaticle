<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog;

use Closure;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Server\Transport\StdioTransport;

final class LocalStdioTransport extends StdioTransport
{
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
        fwrite($this->output, $message.PHP_EOL);
    }

    public function run(): void
    {
        stream_set_blocking($this->input, false);

        $buffer = '';

        while (! feof($this->input)) {
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

        $this->handle($buffer);
    }

    private function handle(string $line): void
    {
        if (trim($line) !== '' && is_callable($this->handler)) {
            ($this->handler)($line);
        }
    }
}
