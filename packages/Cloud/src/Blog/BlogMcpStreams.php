<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog;

final readonly class BlogMcpStreams
{
    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function __construct(public mixed $input, public mixed $output) {}
}
