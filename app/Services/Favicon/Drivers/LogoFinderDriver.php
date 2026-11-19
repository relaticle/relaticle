<?php

declare(strict_types=1);

namespace App\Services\Favicon\Drivers;

use App\Data\LogoImage;
use App\Support\Http\SsrfGuard;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final readonly class LogoFinderDriver
{
    private const int TIMEOUT_SECONDS = 15;

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    public function isConfigured(): bool
    {
        return filled(config('services.logo_finder.url')) && filled(config('services.logo_finder.key'));
    }

    /**
     * @throws RequestException
     */
    public function fetch(string $domain): ?LogoImage
    {
        $response = SsrfGuard::guard(Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::CONNECT_TIMEOUT_SECONDS))
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Logo-Finder-Key' => (string) config('services.logo_finder.key')])
            ->get(rtrim((string) config('services.logo_finder.url'), '/').'/logo', ['domain' => $domain]);

        if ($response->status() === 204) {
            return null;
        }

        throw_unless($response->ok(), RequestException::class, $response);

        return new LogoImage($response->body());
    }
}
