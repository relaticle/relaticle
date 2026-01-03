<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Services\DataTypeInferencer;

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
