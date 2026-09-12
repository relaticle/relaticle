<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Media\UploadAllowlist;
use RuntimeException;

final class UploadException extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new self(__('uploads.errors.too_large', ['max' => (int) round(UploadAllowlist::maxBytes() / 1048576)]));
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
}
