<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Infrastructure;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Centralized file storage operations for the ImportWizard.
 *
 * Handles:
 * - Persisting uploaded files to session-scoped directories
 * - Creating enriched/corrected copies for import execution
 * - Session cleanup when imports complete or expire
 */
final class ImportStorage
{
    private const string DISK = 'local';

    private const string BASE_PATH = 'temp-imports';

    private const string ORIGINAL_FILENAME = 'original.csv';

    private const string CORRECTED_FILENAME = 'corrected.csv';

    private const string PERMANENT_PREFIX = 'imports';

    /**
     * Persist a Livewire temporary upload to a session directory.
     *
     * @return array{sessionId: string, path: string}|null
     */
    public function persistUpload(TemporaryUploadedFile $file): ?array
    {
        try {
            $sessionId = Str::uuid()->toString();
            $folder = $this->sessionFolder($sessionId);
            $storagePath = "{$folder}/".self::ORIGINAL_FILENAME;

            $sourcePath = $file->getRealPath();
            $content = file_get_contents($sourcePath);
            throw_if($content === false, RuntimeException::class, 'Failed to read file content');

            $this->disk()->makeDirectory($folder);
            $this->disk()->put($storagePath, $content);

            return [
                'sessionId' => $sessionId,
                'path' => $this->disk()->path($storagePath),
            ];
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get the absolute path to the original CSV file.
     */
    public function getOriginalPath(string $sessionId): string
    {
        $storagePath = $this->sessionFolder($sessionId).'/'.self::ORIGINAL_FILENAME;

        return $this->disk()->path($storagePath);
    }

    /**
     * Check if the original file exists.
     */
    public function hasOriginalFile(string $sessionId): bool
    {
        $storagePath = $this->sessionFolder($sessionId).'/'.self::ORIGINAL_FILENAME;

        return $this->disk()->exists($storagePath);
    }

    /**
     * Save a corrected CSV file with user corrections applied.
     *
     * @return string Absolute path to the corrected file
     */
    public function saveCorrectedFile(string $sessionId, string $content): string
    {
        $folder = $this->sessionFolder($sessionId);
        $storagePath = "{$folder}/".self::CORRECTED_FILENAME;

        $this->disk()->put($storagePath, $content);

        return $this->disk()->path($storagePath);
    }

    /**
     * Get the absolute path to the corrected CSV file.
     */
    public function getCorrectedPath(string $sessionId): string
    {
        $storagePath = $this->sessionFolder($sessionId).'/'.self::CORRECTED_FILENAME;

        return $this->disk()->path($storagePath);
    }

    /**
     * Check if a corrected file exists.
     */
    public function hasCorrectedFile(string $sessionId): bool
    {
        $storagePath = $this->sessionFolder($sessionId).'/'.self::CORRECTED_FILENAME;

        return $this->disk()->exists($storagePath);
    }

    /**
     * Copy the import file to a permanent location for background processing.
     *
     * @return string Permanent storage path (relative, for queue serialization)
     */
    public function copyToPermanent(string $sessionId, string $filename): string
    {
        $permanentPath = self::PERMANENT_PREFIX."/{$filename}";

        $sourcePath = $this->hasCorrectedFile($sessionId)
            ? $this->sessionFolder($sessionId).'/'.self::CORRECTED_FILENAME
            : $this->sessionFolder($sessionId).'/'.self::ORIGINAL_FILENAME;

        $this->disk()->copy($sourcePath, $permanentPath);

        return $permanentPath;
    }

    /**
     * Get absolute path from a permanent storage path.
     */
    public function getPermanentAbsolutePath(string $storagePath): string
    {
        return $this->disk()->path($storagePath);
    }

    /**
     * Delete a permanent file after import completion.
     */
    public function deletePermanentFile(string $storagePath): void
    {
        if ($this->disk()->exists($storagePath)) {
            $this->disk()->delete($storagePath);
        }
    }

    /**
     * Clean up all temporary files for a session.
     */
    public function cleanup(string $sessionId): void
    {
        $folder = $this->sessionFolder($sessionId);

        if ($this->disk()->exists($folder)) {
            $this->disk()->deleteDirectory($folder);
        }
    }

    /**
     * Check if a session folder exists.
     */
    public function sessionExists(string $sessionId): bool
    {
        return $this->disk()->exists($this->sessionFolder($sessionId));
    }

    /**
     * Get the session folder path.
     */
    private function sessionFolder(string $sessionId): string
    {
        return self::BASE_PATH."/{$sessionId}";
    }

    /**
     * Get the storage disk.
     */
    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
