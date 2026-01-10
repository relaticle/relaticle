<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Support\DataTypeInferencer;

describe('DataTypeInferencer', function (): void {
    beforeEach(function (): void {
        $this->inferencer = new DataTypeInferencer;
    });

    it('detects data types', function (array $values, string $expectedType, string $expectedField): void {
        $result = $this->inferencer->inferType($values);

        expect($result)
            ->type->toBe($expectedType)
            ->confidence->toBeGreaterThanOrEqual(0.7)
            ->suggestedFields->toContain($expectedField);
    })->with([
        'emails' => [['john@example.com', 'jane@test.org', 'bob@company.net', 'alice@domain.co'], 'email', 'email'],
        'phones (mixed formats)' => [['+1-555-123-4567', '(555) 987-6543', '555.111.2222', '1234567890'], 'phone', 'phone'],
        'phones (US with country code)' => [['+1-920-954-2451', '+1-555-123-4567', '+1-800-555-1234', '+1 (555) 123-4567'], 'phone', 'phone'],
        'URLs (simple)' => [['https://example.com', 'http://test.org/page', 'https://domain.co/path', 'https://site.net'], 'url', 'website'],
        'URLs (complex)' => [['https://www.example.com/path?query=1', 'http://subdomain.test.org', 'https://api.service.io/v1/endpoint', 'http://localhost:8080'], 'url', 'website'],
        'domains' => [['example.com', 'test.org', 'company.net', 'domain.co'], 'domain', 'domain'],
        'dates (ISO format)' => [['2024-01-15', '2023-12-25', '2025-06-01', '2024-03-20'], 'date', 'date'],
        'dates (US format)' => [['01/15/2024', '12/25/2023', '6/1/25', '03/20/2024'], 'date', 'date'],
        'datetimes (ISO with time)' => [['2024-01-15T10:30:00', '2024-01-15 14:45', '2024-01-15T09:00:00Z', '2024-01-15 23:59:59'], 'date', 'date'],
    ]);

    it('returns null for undetectable types', function (array $values): void {
        $result = $this->inferencer->inferType($values);

        expect($result)
            ->type->toBeNull()
            ->confidence->toBe(0.0);
    })->with([
        'mixed/unknown' => [['hello', '12345', 'test@example', 'random']],
        'below min sample size' => [['john@example.com', 'jane@test.org']],
        'below confidence threshold (50%)' => [['john@example.com', 'jane@test.org', 'not-an-email', 'random text']],
    ]);

    it('ignores empty values in confidence calculation', function (): void {
        $values = ['john@example.com', '', 'jane@test.org', '', 'bob@test.net', 'alice@domain.com'];

        expect($this->inferencer->inferType($values))
            ->type->toBe('email')
            ->confidence->toEqual(1.0);
    });

    it('passes with exactly 70% confidence threshold', function (): void {
        $values = [
            'a@test.com', 'b@test.com', 'c@test.com', 'd@test.com',
            'e@test.com', 'f@test.com', 'g@test.com',
            'not-email-1', 'not-email-2', 'not-email-3',
        ];

        expect($this->inferencer->inferType($values))
            ->type->toBe('email')
            ->confidence->toBe(0.7);
    });
});

describe('DataTypeInferencer::detectDateFormat', function (): void {
    beforeEach(function (): void {
        $this->inferencer = new DataTypeInferencer;
    });

    it('detects ISO date format with high confidence', function (): void {
        $values = ['2024-01-15', '2024-12-25', '2025-06-01', '2024-03-20'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->detectedFormat->toBe(DateFormat::ISO)
            ->confidence->toBe(1.0)
            ->isoCount->toBe(4);
    });

    it('detects European format from unambiguous values', function (): void {
        // Values like 31/01/2024 can ONLY be DD/MM
        $values = ['31/01/2024', '25/12/2023', '15/06/2024', '28/02/2024'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->detectedFormat->toBe(DateFormat::EUROPEAN)
            ->europeanOnlyCount->toBeGreaterThan(0)
            ->americanOnlyCount->toBe(0);
    });

    it('detects American format from unambiguous values', function (): void {
        // Values like 01/31/2024 can ONLY be MM/DD
        $values = ['01/31/2024', '12/25/2023', '06/15/2024', '02/28/2024'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->detectedFormat->toBe(DateFormat::AMERICAN)
            ->americanOnlyCount->toBeGreaterThan(0)
            ->europeanOnlyCount->toBe(0);
    });

    it('identifies ambiguous dates correctly', function (): void {
        // All values have both day and month <= 12
        $values = ['01/02/2024', '03/04/2024', '05/06/2024', '07/08/2024'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->ambiguousCount->toBe(4)
            ->europeanOnlyCount->toBe(0)
            ->americanOnlyCount->toBe(0)
            ->confidence->toBeLessThan(0.5);
    });

    it('handles mixed format evidence', function (): void {
        // Mix of unambiguous and ambiguous values
        $values = ['31/01/2024', '01/02/2024', '25/12/2023', '03/04/2024'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->detectedFormat->toBe(DateFormat::EUROPEAN)
            ->europeanOnlyCount->toBe(2)
            ->ambiguousCount->toBe(2);
    });

    it('returns low confidence for conflicting formats', function (): void {
        // Contains both European-only AND American-only values (unlikely but test edge case)
        $values = ['31/01/2024', '01/31/2024'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result->confidence)->toBeLessThanOrEqual(0.5);
    });

    it('handles empty values gracefully', function (): void {
        $values = ['', '', ''];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->totalAnalyzed->toBe(0)
            ->confidence->toBe(0.0);
    });

    it('identifies invalid dates', function (): void {
        $values = ['not-a-date', 'invalid', '99/99/9999'];

        $result = $this->inferencer->detectDateFormat($values);

        expect($result)
            ->invalidCount->toBeGreaterThan(0);
    });
});
