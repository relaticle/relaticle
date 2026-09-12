<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class UploadException extends RuntimeException
{
    public static function tooLarge(int $maxBytes): self
    {
        return new self(__('uploads.errors.too_large', ['max' => (int) round($maxBytes / 1048576)]));
    }

    public static function mimeNotAllowed(string $mime): self
    {
        return new self(__('uploads.errors.mime_not_allowed', ['mime' => $mime]));
    }

    public static function unreachable(): self
    {
        return new self(__('uploads.errors.unreachable'));
    }

    public static function urlNotAllowed(): self
    {
        return new self(__('uploads.errors.url_not_allowed'));
    }

    public static function notFound(): self
    {
        return new self(__('uploads.errors.not_found'));
    }

    public static function rateLimited(): self
    {
        return new self(__('uploads.errors.rate_limited'));
    }

    public static function invalidBase64(): self
    {
        return new self(__('uploads.errors.invalid_base64'));
    }

    public static function noSource(): self
    {
        return new self(__('uploads.errors.no_source'));
    }
}
