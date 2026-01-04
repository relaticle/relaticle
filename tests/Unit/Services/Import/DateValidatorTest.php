<?php

declare(strict_types=1);

use Carbon\Carbon;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Services\DateValidator;

describe('DateValidator', function (): void {
    beforeEach(function (): void {
        $this->validator = new DateValidator;
    });

    describe('validate', function (): void {
        it('validates ISO dates correctly', function (): void {
            $result = $this->validator->validate('2024-01-15', DateFormat::ISO);

            expect($result)
                ->valid->toBeTrue()
                ->parsed->toBeInstanceOf(Carbon::class)
                ->issue->toBeNull()
                ->isAmbiguous->toBeFalse();
        });

        it('validates European dates correctly', function (): void {
            $result = $this->validator->validate('15/01/2024', DateFormat::EUROPEAN);

            expect($result)
                ->valid->toBeTrue()
                ->parsed->toBeInstanceOf(Carbon::class)
                ->issue->toBeNull();
        });

        it('validates American dates correctly', function (): void {
            $result = $this->validator->validate('01/15/2024', DateFormat::AMERICAN);

            expect($result)
                ->valid->toBeTrue()
                ->parsed->toBeInstanceOf(Carbon::class)
                ->issue->toBeNull();
        });

        it('returns invalid for unparseable dates', function (): void {
            $result = $this->validator->validate('not-a-date', DateFormat::ISO);

            expect($result)
                ->valid->toBeFalse()
                ->parsed->toBeNull()
                ->issue->not->toBeNull()
                ->issue->severity->toBe('error');
        });

        it('identifies ambiguous dates with warning', function (): void {
            // 01/02/2024 is ambiguous - could be Jan 2 or Feb 1
            $result = $this->validator->validate('01/02/2024', DateFormat::EUROPEAN);

            expect($result)
                ->valid->toBeTrue()
                ->isAmbiguous->toBeTrue()
                ->issue->not->toBeNull()
                ->issue->severity->toBe('warning')
                ->issue->issueType->toBe('ambiguous');
        });

        it('treats empty string as valid', function (): void {
            $result = $this->validator->validate('', DateFormat::ISO);

            expect($result)
                ->valid->toBeTrue()
                ->parsed->toBeNull()
                ->issue->toBeNull()
                ->isAmbiguous->toBeFalse();
        });

        it('trims whitespace', function (): void {
            $result = $this->validator->validate('  2024-01-15  ', DateFormat::ISO);

            expect($result)
                ->valid->toBeTrue()
                ->parsed->toBeInstanceOf(Carbon::class);
        });
    });

    describe('validateColumn', function (): void {
        it('validates a column of dates', function (): void {
            $uniqueValues = [
                '2024-01-15' => 5,
                '2024-02-20' => 3,
                '2024-03-25' => 2,
            ];

            $result = $this->validator->validateColumn($uniqueValues, DateFormat::ISO);

            expect($result)
                ->issues->toBeArray()
                ->issues->toHaveCount(0)
                ->validCount->toBe(10)
                ->invalidCount->toBe(0)
                ->ambiguousCount->toBe(0);
        });

        it('counts invalid dates', function (): void {
            $uniqueValues = [
                '2024-01-15' => 5,
                'not-a-date' => 3,
                'invalid' => 2,
            ];

            $result = $this->validator->validateColumn($uniqueValues, DateFormat::ISO);

            expect($result)
                ->issues->toHaveCount(2)
                ->validCount->toBe(5)
                ->invalidCount->toBe(5);
        });

        it('counts ambiguous dates', function (): void {
            $uniqueValues = [
                '01/02/2024' => 5,  // Ambiguous
                '31/01/2024' => 3,  // Unambiguous European
            ];

            $result = $this->validator->validateColumn($uniqueValues, DateFormat::EUROPEAN);

            expect($result)
                ->issues->toHaveCount(1)  // Only ambiguous one has a warning
                ->validCount->toBe(8)
                ->ambiguousCount->toBe(5);
        });
    });

    describe('formatForPreview', function (): void {
        it('formats dates for display', function (): void {
            $date = Carbon::create(2024, 1, 15);

            expect($this->validator->formatForPreview($date))
                ->toBe('Jan 15, 2024');
        });

        it('returns empty string for null', function (): void {
            expect($this->validator->formatForPreview(null))->toBe('');
        });
    });
});
