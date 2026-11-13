<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class LocalCopy
{
    /**
     * @template TResult
     *
     * @param  resource|null  $stream
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public static function of(mixed $stream, Closure $callback): mixed
    {
        throw_unless(is_resource($stream), UploadException::notFound());

        $path = sys_get_temp_dir().'/local-copy-'.Str::ulid();

        try {
            touch($path);
            chmod($path, 0600);
            throw_if(file_put_contents($path, $stream) === false, RuntimeException::class, 'The file could not be copied to a local temp path.');

            return $callback($path);
        } finally {
            fclose($stream);
            File::delete($path);
        }
    }
}
