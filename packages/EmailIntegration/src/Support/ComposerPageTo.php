<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

final readonly class ComposerPageTo
{
    private function __construct(public ?string $email) {}

    public static function remember(?string $email): void
    {
        app()->instance(self::class, new self($email));
    }

    public static function email(): ?string
    {
        return app()->bound(self::class) ? resolve(self::class)->email : null;
    }
}
