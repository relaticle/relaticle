<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Database\Eloquent\Model;

final readonly class ComposerPageTo
{
    /**
     * @param  class-string<Model>|null  $recordType
     */
    private function __construct(
        public ?string $email,
        public ?string $recordType = null,
        public ?string $recordId = null,
    ) {}

    public static function remember(?string $email, ?Model $record = null): void
    {
        app()->instance(self::class, new self(
            $email,
            $record instanceof Model ? $record::class : null,
            $record instanceof Model ? (string) $record->getKey() : null,
        ));
    }

    public static function email(): ?string
    {
        return self::current()?->email;
    }

    /**
     * @return class-string<Model>|null
     */
    public static function recordType(): ?string
    {
        return self::current()?->recordType;
    }

    public static function recordId(): ?string
    {
        return self::current()?->recordId;
    }

    private static function current(): ?self
    {
        return app()->bound(self::class) ? resolve(self::class) : null;
    }
}
