<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\UploadSource;
use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Services\Favicon\SsrfGuard;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Client\ConnectionException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StoreAgentUpload
{
    private const int MAX_BASE64_BYTES = 5 * 1024 * 1024;

    public function __construct(private StorePendingUpload $store) {}

    /**
     * @param  array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}  $input
     */
    public function execute(User $user, Team $team, array $input): Media
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'agent-upload');

        try {
            [$name, $source] = $this->materialise($input, $temp);

            $media = $this->store->execute($user, $team, $temp, $name, $source);

            if ($source === UploadSource::SignedPut) {
                TemporaryUploads::disk()->delete(TemporaryUploads::path((string) ($input['upload_id'] ?? '')));
            }

            return $media;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @param  array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}  $input
     * @return array{0: string, 1: UploadSource}
     */
    private function materialise(array $input, string $temp): array
    {
        if (filled($input['source_url'] ?? null)) {
            return [$this->fetch((string) $input['source_url'], $temp), UploadSource::Url];
        }

        if (filled($input['base64'] ?? null)) {
            return [$this->decode((string) $input['base64'], (string) ($input['filename'] ?? 'upload'), $temp), UploadSource::Base64];
        }

        return [$this->takeTemporary((string) ($input['upload_id'] ?? ''), $temp), UploadSource::SignedPut];
    }

    private function fetch(string $url, string $temp): string
    {
        try {
            $response = SsrfGuard::pinnedClient($url)->get($url);
        } catch (SsrfGuardException) {
            throw UploadException::urlNotAllowed();
        } catch (ConnectionException) {
            throw UploadException::unreachable();
        }

        throw_unless($response->successful(), UploadException::unreachable());

        $body = $response->body();

        throw_if(strlen($body) > UploadAllowlist::maxBytes(), UploadException::tooLarge());

        file_put_contents($temp, $body);

        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name === '' ? 'download' : $name;
    }

    private function decode(string $base64, string $filename, string $temp): string
    {
        $bytes = base64_decode($base64, strict: true);

        throw_if($bytes === false, UploadException::invalidBase64());
        throw_if(strlen($bytes) > self::MAX_BASE64_BYTES, UploadException::tooLarge());

        file_put_contents($temp, $bytes);

        return $filename;
    }

    private function takeTemporary(string $upload, string $temp): string
    {
        throw_unless(TemporaryUploads::isValidName($upload), UploadException::notFound());

        $disk = TemporaryUploads::disk();
        $path = TemporaryUploads::path($upload);

        throw_unless($disk->exists($path), UploadException::notFound());

        $stream = $disk->readStream($path);

        throw_if($stream === null, UploadException::notFound());

        file_put_contents($temp, $stream);

        return $upload;
    }
}
