<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Relaticle\ImportWizard\Infrastructure\ImportCache;

describe('ImportCache', function (): void {
    beforeEach(function (): void {
        $this->cache = new ImportCache;
        $this->sessionId = 'test-session-123';
        $this->csvColumn = 'Company Name';
        $this->fieldName = 'company_name';

        Cache::flush();
    });

    describe('unique values', function (): void {
        it('stores and retrieves unique values', function (): void {
            $values = ['Acme Corp' => 5, 'Beta Inc' => 3, 'Gamma LLC' => 2];

            $this->cache->putUniqueValues($this->sessionId, $this->csvColumn, $values);

            expect($this->cache->getUniqueValues($this->sessionId, $this->csvColumn))
                ->toBe($values);
        });

        it('returns empty array when no values cached', function (): void {
            expect($this->cache->getUniqueValues($this->sessionId, $this->csvColumn))
                ->toBe([]);
        });

        it('checks if values exist', function (): void {
            expect($this->cache->hasUniqueValues($this->sessionId, $this->csvColumn))
                ->toBeFalse();

            $this->cache->putUniqueValues($this->sessionId, $this->csvColumn, ['Test' => 1]);

            expect($this->cache->hasUniqueValues($this->sessionId, $this->csvColumn))
                ->toBeTrue();
        });

        it('forgets unique values', function (): void {
            $this->cache->putUniqueValues($this->sessionId, $this->csvColumn, ['Test' => 1]);

            $this->cache->forgetUniqueValues($this->sessionId, $this->csvColumn);

            expect($this->cache->hasUniqueValues($this->sessionId, $this->csvColumn))
                ->toBeFalse();
        });

        it('generates correct cache key', function (): void {
            expect($this->cache->uniqueValuesKey($this->sessionId, $this->csvColumn))
                ->toBe("import:{$this->sessionId}:values:{$this->csvColumn}");
        });
    });

    describe('analysis data', function (): void {
        it('stores and retrieves analysis data', function (): void {
            $analysis = [
                'fieldType' => 'date',
                'detectedDateFormat' => 'ISO',
                'issues' => [],
            ];

            $this->cache->putAnalysis($this->sessionId, $this->csvColumn, $analysis);

            expect($this->cache->getAnalysis($this->sessionId, $this->csvColumn))
                ->toBe($analysis);
        });

        it('returns null when no analysis cached', function (): void {
            expect($this->cache->getAnalysis($this->sessionId, $this->csvColumn))
                ->toBeNull();
        });

        it('forgets analysis data', function (): void {
            $this->cache->putAnalysis($this->sessionId, $this->csvColumn, ['test' => true]);

            $this->cache->forgetAnalysis($this->sessionId, $this->csvColumn);

            expect($this->cache->getAnalysis($this->sessionId, $this->csvColumn))
                ->toBeNull();
        });

        it('generates correct cache key', function (): void {
            expect($this->cache->analysisKey($this->sessionId, $this->csvColumn))
                ->toBe("import:{$this->sessionId}:analysis:{$this->csvColumn}");
        });
    });

    describe('corrections', function (): void {
        it('stores and retrieves corrections', function (): void {
            $corrections = ['old1' => 'new1', 'old2' => 'new2'];

            $this->cache->putCorrections($this->sessionId, $this->fieldName, $corrections);

            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe($corrections);
        });

        it('returns empty array when no corrections cached', function (): void {
            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe([]);
        });

        it('sets a single correction', function (): void {
            $this->cache->setCorrection($this->sessionId, $this->fieldName, 'old', 'new');

            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe(['old' => 'new']);

            $this->cache->setCorrection($this->sessionId, $this->fieldName, 'old2', 'new2');

            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe(['old' => 'new', 'old2' => 'new2']);
        });

        it('removes a single correction', function (): void {
            $this->cache->putCorrections($this->sessionId, $this->fieldName, [
                'old1' => 'new1',
                'old2' => 'new2',
            ]);

            $this->cache->removeCorrection($this->sessionId, $this->fieldName, 'old1');

            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe(['old2' => 'new2']);
        });

        it('forgets corrections when empty array provided', function (): void {
            $this->cache->putCorrections($this->sessionId, $this->fieldName, ['test' => 'value']);
            $this->cache->putCorrections($this->sessionId, $this->fieldName, []);

            expect($this->cache->getCorrections($this->sessionId, $this->fieldName))
                ->toBe([]);
        });

        it('handles skip value as empty string', function (): void {
            $this->cache->setCorrection($this->sessionId, $this->fieldName, 'skip_me', '');

            $corrections = $this->cache->getCorrections($this->sessionId, $this->fieldName);

            expect($corrections['skip_me'])->toBe('');
        });

        it('generates correct cache key', function (): void {
            expect($this->cache->correctionsKey($this->sessionId, $this->fieldName))
                ->toBe("import:{$this->sessionId}:corrections:{$this->fieldName}");
        });
    });

    describe('clearSession', function (): void {
        it('clears all cached data for a session', function (): void {
            $csvColumns = ['col1', 'col2'];
            $fieldNames = ['field1', 'field2'];

            foreach ($csvColumns as $col) {
                $this->cache->putUniqueValues($this->sessionId, $col, ['test' => 1]);
                $this->cache->putAnalysis($this->sessionId, $col, ['test' => true]);
            }
            foreach ($fieldNames as $field) {
                $this->cache->putCorrections($this->sessionId, $field, ['old' => 'new']);
            }

            $this->cache->clearSession($this->sessionId, $csvColumns, $fieldNames);

            foreach ($csvColumns as $col) {
                expect($this->cache->hasUniqueValues($this->sessionId, $col))->toBeFalse();
                expect($this->cache->getAnalysis($this->sessionId, $col))->toBeNull();
            }
            foreach ($fieldNames as $field) {
                expect($this->cache->getCorrections($this->sessionId, $field))->toBe([]);
            }
        });
    });
});
