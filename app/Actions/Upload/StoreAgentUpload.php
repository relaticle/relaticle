<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\UploadSource;
use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Http\SsrfGuard;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StoreAgentUpload
{
    public function __construct(private StorePendingUpload $store) {}

    /**
     * @param  array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}  $input
     */
    public function execute(User $user, Workspace $workspace, array $input): Media
    {
        abort_unless($user->belongsToWorkspace($workspace), 403);

        if (filled($input['upload_id'] ?? null)) {
            $upload = (string) $input['upload_id'];
            throw_unless(TemporaryUploads::belongsToWorkspace($upload, (string) $workspace->getKey()), UploadException::notFound());
            $media = Cache::lock("mcp-upload:{$upload}", 60)
                ->get(fn (): Media => $this->storeUpload($user, $workspace, $input));

            throw_unless($media instanceof Media, UploadException::class, __('uploads.errors.busy'));

            return $media;
        }

        return $this->storeUpload($user, $workspace, $input);
    }

    /** @param array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string} $input */
    private function storeUpload(User $user, Workspace $workspace, array $input): Media
    {
        $temp = sys_get_temp_dir().'/agent-upload-'.Str::ulid();
        touch($temp);
        chmod($temp, 0600);

        try {
            [$name, $source] = $this->materialise($input, (string) $workspace->getKey(), $temp);

            $media = $this->store->execute($user, $workspace, $temp, $name, $source);

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
    private function materialise(array $input, string $workspaceId, string $temp): array
    {
        if (filled($input['source_url'] ?? null)) {
            return [$this->fetch((string) $input['source_url'], $temp), UploadSource::Url];
        }

        if (filled($input['base64'] ?? null)) {
            return [$this->decode((string) $input['base64'], (string) ($input['filename'] ?? 'upload'), $temp), UploadSource::Base64];
        }

        if (filled($input['upload_id'] ?? null)) {
            $upload = $this->takeTemporary((string) $input['upload_id'], $workspaceId, $temp);
            $name = filled($input['filename'] ?? null)
                ? (string) $input['filename']
                : TemporaryUploads::displayName($upload);

            return [$name, UploadSource::SignedPut];
        }

        throw UploadException::noSource();
    }

    private function fetch(string $url, string $temp): string
    {
        try {
            $response = SsrfGuard::pinnedClient($url)->sink($temp)->get($url);
        } catch (SsrfGuardException) {
            throw UploadException::urlNotAllowed();
        } catch (ConnectionException) {
            throw UploadException::unreachable();
        }

        throw_unless($response->successful(), UploadException::unreachable());

        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name === '' ? 'download' : $name;
    }

    private function decode(string $base64, string $filename, string $temp): string
    {
        $bytes = base64_decode($base64, strict: true);

        throw_if($bytes === false, UploadException::invalidBase64());
        throw_if(strlen($bytes) > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));

        file_put_contents($temp, $bytes);

        return $filename;
    }

    private function takeTemporary(string $upload, string $workspaceId, string $temp): string
    {
        throw_unless(TemporaryUploads::belongsToWorkspace($upload, $workspaceId), UploadException::notFound());

        $disk = TemporaryUploads::disk();
        $path = TemporaryUploads::path($upload);

        throw_unless($disk->exists($path), UploadException::notFound());

        throw_if((int) $disk->size($path) > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));

        $stream = $disk->readStream($path);

        throw_if($stream === null, UploadException::notFound());

        try {
            file_put_contents($temp, $stream);
        } finally {
            fclose($stream);
        }

        return $upload;
    }
}
