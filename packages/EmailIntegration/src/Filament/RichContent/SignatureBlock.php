<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RichContent;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Relaticle\EmailIntegration\Models\EmailSignature;

/**
 * Represents an email signature as a first-class RichEditor block.
 *
 * Storing the signature as a dedicated `customBlock` node (identified by
 * {@see self::getId()} and configured with a `signature_id`) lets the compose
 * forms deterministically find, replace, or remove the signature in the body —
 * instead of fragile string surgery around the surrounding HTML.
 */
final class SignatureBlock extends RichContentCustomBlock
{
    public const string ID = 'signature';

    public static function getId(): string
    {
        return self::ID;
    }

    public static function getLabel(): string
    {
        return __('filament/pages/email-inbox.compose_form.signature.label');
    }

    /**
     * Rendered inside the editor as a non-editable preview of the signature.
     *
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): ?string
    {
        return self::resolveContentHtml($config);
    }

    /**
     * Rendered into the final outbound email body.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return self::resolveContentHtml($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveContentHtml(array $config): ?string
    {
        $signatureId = $config['signature_id'] ?? null;

        if (blank($signatureId)) {
            return null;
        }

        return EmailSignature::query()
            ->whereKey($signatureId)
            ->value('content_html');
    }
}
