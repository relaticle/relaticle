<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Contracts;

use App\Models\User;

/**
 * One entity schema, built once as an array.
 *
 * The resource JSON-encodes it for MCP resource reads and GetCrmSchemaTool returns
 * it structured. Going through the encoded string in both directions used to flatten
 * an empty `custom_fields` map from `{}` to `[]`, which the tool then patched back
 * per known key, a repair that silently missed any new key that can be empty.
 */
interface ProvidesEntitySchema
{
    /** @return array<string, mixed> */
    public function toSchema(User $user): array;
}
