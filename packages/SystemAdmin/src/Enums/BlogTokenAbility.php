<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Enums;

/**
 * The only abilities ink's BlogTool::tokenAbility() checks (verified against
 * vendor/relaticle/ink/src/Mcp/Tools/*.php). This is the single source of
 * truth for both the token-creation UI and the server-side allowlist that
 * abilities are intersected against before a token is minted — the two must
 * never drift.
 */
enum BlogTokenAbility: string
{
    case PostsRead = 'posts:read';
    case PostsCreate = 'posts:create';
    case PostsUpdate = 'posts:update';
    case PostsDelete = 'posts:delete';
    case CategoriesRead = 'categories:read';
    case CategoriesCreate = 'categories:create';
    case CategoriesUpdate = 'categories:update';
    case CategoriesDelete = 'categories:delete';

    public function getLabel(): string
    {
        return match ($this) {
            self::PostsRead => 'Read posts',
            self::PostsCreate => 'Create posts',
            self::PostsUpdate => 'Update posts',
            self::PostsDelete => 'Delete & restore posts',
            self::CategoriesRead => 'Read categories',
            self::CategoriesCreate => 'Create categories',
            self::CategoriesUpdate => 'Update categories',
            self::CategoriesDelete => 'Delete & restore categories',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $ability): array => [$ability->value => $ability->getLabel()],
        )->all();
    }
}
