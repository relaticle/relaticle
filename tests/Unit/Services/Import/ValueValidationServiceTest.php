<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\TimestampFormat;
use Relaticle\ImportWizard\Infrastructure\ImportCache;
use Relaticle\ImportWizard\Services\ValueValidationService;
use Relaticle\ImportWizard\Support\DateValidator;
use Relaticle\ImportWizard\Support\TimestampValidator;

describe('ValueValidationService', function (): void {
    beforeEach(function (): void {
        $this->cache = new ImportCache;
        $this->service = new ValueValidationService(
            new DateValidator,
            new TimestampValidator,
            $this->cache,
        );

        Cache::flush();
    });

    describe('validateValue', function (): void {
        it('returns null for empty value', function (): void {
            expect($this->service->validateValue('', FieldDataType::DATE->value, DateFormat::ISO->value))
                ->toBeNull();
        });

        it('returns null for non-date field types', function (): void {
            expect($this->service->validateValue('test', FieldDataType::TEXT->value, null))
                ->toBeNull();
        });

        it('returns null when format is not provided', function (): void {
            expect($this->service->validateValue('2024-01-15', FieldDataType::DATE->value, null))
                ->toBeNull();
        });

        it('validates ISO dates correctly', function (): void {
            expect($this->service->validateValue('2024-01-15', FieldDataType::DATE->value, DateFormat::ISO->value))
                ->toBeNull();
        });

        it('returns error for invalid ISO date', function (): void {
            $result = $this->service->validateValue('not-a-date', FieldDataType::DATE->value, DateFormat::ISO->value);

            expect($result)
                ->toBeArray()
                ->toHaveKey('severity')
                ->and($result['severity'])->toBe('error');
        });

        it('validates European dates correctly', function (): void {
            expect($this->service->validateValue('15/01/2024', FieldDataType::DATE->value, DateFormat::EUROPEAN->value))
                ->toBeNull();
        });

        it('validates American dates correctly', function (): void {
            expect($this->service->validateValue('01/15/2024', FieldDataType::DATE->value, DateFormat::AMERICAN->value))
                ->toBeNull();
        });

        it('validates timestamps correctly', function (): void {
            expect($this->service->validateValue('2024-01-15 14:30:00', FieldDataType::DATE_TIME->value, TimestampFormat::ISO->value))
                ->toBeNull();
        });

        it('returns error for invalid timestamp', function (): void {
            $result = $this->service->validateValue('not-valid', FieldDataType::DATE_TIME->value, TimestampFormat::ISO->value);

            expect($result)
                ->toBeArray()
                ->toHaveKey('severity')
                ->and($result['severity'])->toBe('error');
        });
    });

    describe('validateColumn', function (): void {
        it('validates a column of dates', function (): void {
            $uniqueValues = [
                '2024-01-15' => 5,
                '2024-02-20' => 3,
            ];

            $result = $this->service->validateColumn(
                $uniqueValues,
                FieldDataType::DATE->value,
                DateFormat::ISO->value
            );

            expect($result)
                ->toHaveKey('issues')
                ->toHaveKey('errorCount')
                ->toHaveKey('warningCount')
                ->and($result['errorCount'])->toBe(0);
        });

        it('returns empty result for non-date fields', function (): void {
            $result = $this->service->validateColumn(
                ['test' => 5],
                FieldDataType::TEXT->value,
                null
            );

            expect($result)
                ->issues->toBe([])
                ->errorCount->toBe(0)
                ->warningCount->toBe(0);
        });

        it('counts errors correctly', function (): void {
            $uniqueValues = [
                '2024-01-15' => 5,
                'invalid' => 3,
            ];

            $result = $this->service->validateColumn(
                $uniqueValues,
                FieldDataType::DATE->value,
                DateFormat::ISO->value
            );

            expect($result['errorCount'])->toBe(1);
            expect($result['issues'])->toHaveCount(1);
        });
    });

    describe('parseDatePreview', function (): void {
        it('parses ISO dates', function (): void {
            $result = $this->service->parseDatePreview(
                '2024-01-15',
                FieldDataType::DATE->value,
                DateFormat::ISO->value
            );

            expect($result)->toBe('2024-01-15');
        });

        it('parses European dates', function (): void {
            $result = $this->service->parseDatePreview(
                '15/01/2024',
                FieldDataType::DATE->value,
                DateFormat::EUROPEAN->value
            );

            expect($result)->toBe('2024-01-15');
        });

        it('parses timestamps', function (): void {
            $result = $this->service->parseDatePreview(
                '2024-01-15 14:30:00',
                FieldDataType::DATE_TIME->value,
                TimestampFormat::ISO->value
            );

            expect($result)->toBe('2024-01-15 14:30:00');
        });

        it('returns null for empty value', function (): void {
            expect($this->service->parseDatePreview('', FieldDataType::DATE->value, DateFormat::ISO->value))
                ->toBeNull();
        });

        it('returns null for invalid value', function (): void {
            expect($this->service->parseDatePreview('not-a-date', FieldDataType::DATE->value, DateFormat::ISO->value))
                ->toBeNull();
        });
    });

    describe('parseDatePreviewHuman', function (): void {
        it('formats dates for human display', function (): void {
            $result = $this->service->parseDatePreviewHuman(
                '2024-01-15',
                FieldDataType::DATE->value,
                DateFormat::ISO->value
            );

            expect($result)->toBe('Jan 15, 2024');
        });

        it('formats timestamps for human display', function (): void {
            $result = $this->service->parseDatePreviewHuman(
                '2024-01-15 14:30:00',
                FieldDataType::DATE_TIME->value,
                TimestampFormat::ISO->value
            );

            expect($result)->toBe('Jan 15, 2024 2:30 PM');
        });
    });

    describe('validateCorrectedValue', function (): void {
        it('validates using cached analysis data', function (): void {
            $sessionId = 'test-session';
            $csvColumn = 'date_col';

            $this->cache->putAnalysis($sessionId, $csvColumn, [
                'fieldType' => FieldDataType::DATE->value,
                'detectedDateFormat' => DateFormat::ISO->value,
            ]);

            expect($this->service->validateCorrectedValue($sessionId, $csvColumn, '2024-01-15'))
                ->toBeNull();
        });

        it('returns null for empty value', function (): void {
            expect($this->service->validateCorrectedValue('session', 'column', ''))
                ->toBeNull();
        });

        it('returns null when no analysis data cached', function (): void {
            expect($this->service->validateCorrectedValue('session', 'column', 'value'))
                ->toBeNull();
        });
    });

    describe('field type helpers', function (): void {
        it('identifies date fields', function (): void {
            expect($this->service->isDateField(FieldDataType::DATE->value))->toBeTrue();
            expect($this->service->isDateField(FieldDataType::DATE_TIME->value))->toBeTrue();
            expect($this->service->isDateField(FieldDataType::TEXT->value))->toBeFalse();
            expect($this->service->isDateField(null))->toBeFalse();
        });

        it('identifies datetime fields', function (): void {
            expect($this->service->isDateTimeField(FieldDataType::DATE_TIME->value))->toBeTrue();
            expect($this->service->isDateTimeField(FieldDataType::DATE->value))->toBeFalse();
            expect($this->service->isDateTimeField(null))->toBeFalse();
        });
    });

    describe('getEffectiveFormat', function (): void {
        it('prefers selectedDateFormat over detectedDateFormat', function (): void {
            $analysisData = [
                'selectedDateFormat' => 'AMERICAN',
                'detectedDateFormat' => 'EUROPEAN',
            ];

            expect($this->service->getEffectiveFormat($analysisData))->toBe('AMERICAN');
        });

        it('falls back to detectedDateFormat', function (): void {
            $analysisData = [
                'detectedDateFormat' => 'EUROPEAN',
            ];

            expect($this->service->getEffectiveFormat($analysisData))->toBe('EUROPEAN');
        });

        it('returns null when no format available', function (): void {
            expect($this->service->getEffectiveFormat([]))->toBeNull();
        });
    });
});
