<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Infrastructure\ImportStorage;

describe('ImportStorage', function (): void {
    beforeEach(function (): void {
        $this->storage = new ImportStorage;

        Storage::fake('local');
    });

    describe('getOriginalPath', function (): void {
        it('returns correct path for original file', function (): void {
            $sessionId = 'test-session-123';

            $path = $this->storage->getOriginalPath($sessionId);

            expect($path)->toEndWith("temp-imports/{$sessionId}/original.csv");
        });
    });

    describe('hasOriginalFile', function (): void {
        it('returns false when file does not exist', function (): void {
            expect($this->storage->hasOriginalFile('nonexistent'))->toBeFalse();
        });

        it('returns true when file exists', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");
            Storage::disk('local')->put("temp-imports/{$sessionId}/original.csv", 'content');

            expect($this->storage->hasOriginalFile($sessionId))->toBeTrue();
        });
    });

    describe('saveCorrectedFile', function (): void {
        it('saves corrected content to correct path', function (): void {
            $sessionId = 'test-session';
            $content = "col1,col2\nval1,val2";

            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");

            $path = $this->storage->saveCorrectedFile($sessionId, $content);

            expect($path)->toEndWith("temp-imports/{$sessionId}/corrected.csv");

            Storage::disk('local')->assertExists("temp-imports/{$sessionId}/corrected.csv");
        });
    });

    describe('hasCorrectedFile', function (): void {
        it('returns false when file does not exist', function (): void {
            expect($this->storage->hasCorrectedFile('nonexistent'))->toBeFalse();
        });

        it('returns true when file exists', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");
            Storage::disk('local')->put("temp-imports/{$sessionId}/corrected.csv", 'content');

            expect($this->storage->hasCorrectedFile($sessionId))->toBeTrue();
        });
    });

    describe('copyToPermanent', function (): void {
        it('copies original file when no corrected file exists', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");
            Storage::disk('local')->put("temp-imports/{$sessionId}/original.csv", 'original content');

            $permanentPath = $this->storage->copyToPermanent($sessionId, 'import-123.csv');

            expect($permanentPath)->toBe('imports/import-123.csv');

            Storage::disk('local')->assertExists('imports/import-123.csv');
            expect(Storage::disk('local')->get('imports/import-123.csv'))->toBe('original content');
        });

        it('copies corrected file when it exists', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");
            Storage::disk('local')->put("temp-imports/{$sessionId}/original.csv", 'original content');
            Storage::disk('local')->put("temp-imports/{$sessionId}/corrected.csv", 'corrected content');

            $permanentPath = $this->storage->copyToPermanent($sessionId, 'import-123.csv');

            expect(Storage::disk('local')->get('imports/import-123.csv'))->toBe('corrected content');
        });
    });

    describe('deletePermanentFile', function (): void {
        it('deletes permanent file', function (): void {
            Storage::disk('local')->put('imports/test.csv', 'content');

            $this->storage->deletePermanentFile('imports/test.csv');

            Storage::disk('local')->assertMissing('imports/test.csv');
        });

        it('does not error when file does not exist', function (): void {
            $this->storage->deletePermanentFile('imports/nonexistent.csv');

            expect(true)->toBeTrue();
        });
    });

    describe('cleanup', function (): void {
        it('removes entire session directory', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");
            Storage::disk('local')->put("temp-imports/{$sessionId}/original.csv", 'content');
            Storage::disk('local')->put("temp-imports/{$sessionId}/corrected.csv", 'content');

            $this->storage->cleanup($sessionId);

            Storage::disk('local')->assertMissing("temp-imports/{$sessionId}");
        });

        it('does not error when session does not exist', function (): void {
            $this->storage->cleanup('nonexistent');

            expect(true)->toBeTrue();
        });
    });

    describe('sessionExists', function (): void {
        it('returns false for nonexistent session', function (): void {
            expect($this->storage->sessionExists('nonexistent'))->toBeFalse();
        });

        it('returns true for existing session', function (): void {
            $sessionId = 'test-session';
            Storage::disk('local')->makeDirectory("temp-imports/{$sessionId}");

            expect($this->storage->sessionExists($sessionId))->toBeTrue();
        });
    });
});
