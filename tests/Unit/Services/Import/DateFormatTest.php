<?php

declare(strict_types=1);

use Carbon\Carbon;
use Relaticle\ImportWizard\Enums\DateFormat;

describe('DateFormat', function (): void {
    describe('parse', function (): void {
        it('parses ISO format correctly', function (): void {
            $result = DateFormat::ISO->parse('2024-01-15');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15);
        });

        it('parses European format correctly', function (): void {
            $result = DateFormat::EUROPEAN->parse('15/01/2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15);
        });

        it('parses American format correctly', function (): void {
            $result = DateFormat::AMERICAN->parse('01/15/2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15);
        });

        it('handles two-digit years in European format', function (): void {
            $result = DateFormat::EUROPEAN->parse('15/01/24');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15);
        });

        it('handles two-digit years in American format', function (): void {
            $result = DateFormat::AMERICAN->parse('01/15/24');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15);
        });

        it('returns null for invalid dates', function (): void {
            expect(DateFormat::ISO->parse('not-a-date'))->toBeNull();
            expect(DateFormat::EUROPEAN->parse('invalid'))->toBeNull();
            expect(DateFormat::AMERICAN->parse(''))->toBeNull();
        });

        it('returns null for empty string', function (): void {
            expect(DateFormat::ISO->parse(''))->toBeNull();
            expect(DateFormat::EUROPEAN->parse('  '))->toBeNull();
        });
    });

    describe('getLabel', function (): void {
        it('returns human-readable labels', function (): void {
            expect(DateFormat::ISO->getLabel())->toContain('ISO');
            expect(DateFormat::EUROPEAN->getLabel())->toContain('European');
            expect(DateFormat::AMERICAN->getLabel())->toContain('American');
        });
    });

    describe('getLabelWithExamples', function (): void {
        it('returns label with bullet-separated examples', function (): void {
            expect(DateFormat::ISO->getLabelWithExamples())
                ->toBe('ISO standard (2024-05-15)');

            expect(DateFormat::EUROPEAN->getLabelWithExamples())
                ->toBe('European (15-05-2024 • 15/05/2024 • 15 May 2024)');

            expect(DateFormat::AMERICAN->getLabelWithExamples())
                ->toBe('American (05-15-2024 • 05/15/2024 • May 15th 2024)');
        });
    });

    describe('matches', function (): void {
        it('matches ISO format', function (): void {
            expect(DateFormat::ISO->matches('2024-01-15'))->toBeTrue();
            expect(DateFormat::ISO->matches('01/15/2024'))->toBeFalse();
        });

        it('matches European/American slash format', function (): void {
            expect(DateFormat::EUROPEAN->matches('15/01/2024'))->toBeTrue();
            expect(DateFormat::AMERICAN->matches('01/15/2024'))->toBeTrue();
            expect(DateFormat::EUROPEAN->matches('2024-01-15'))->toBeFalse();
        });

        it('matches European/American dash format', function (): void {
            expect(DateFormat::EUROPEAN->matches('15-01-2024'))->toBeTrue();
            expect(DateFormat::AMERICAN->matches('01-15-2024'))->toBeTrue();
        });
    });

    describe('isAmbiguous', function (): void {
        it('identifies ambiguous dates', function (): void {
            // Both positions <= 12
            expect(DateFormat::isAmbiguous('01/02/2024'))->toBeTrue();
            expect(DateFormat::isAmbiguous('05/06/2024'))->toBeTrue();
            expect(DateFormat::isAmbiguous('12/12/2024'))->toBeTrue();
        });

        it('identifies unambiguous dates', function (): void {
            // First position > 12 (must be DD/MM)
            expect(DateFormat::isAmbiguous('31/01/2024'))->toBeFalse();
            expect(DateFormat::isAmbiguous('25/12/2024'))->toBeFalse();

            // Second position > 12 (must be MM/DD)
            expect(DateFormat::isAmbiguous('01/31/2024'))->toBeFalse();
            expect(DateFormat::isAmbiguous('12/25/2024'))->toBeFalse();
        });

        it('returns false for non-matching formats', function (): void {
            expect(DateFormat::isAmbiguous('2024-01-15'))->toBeFalse();
            expect(DateFormat::isAmbiguous('not-a-date'))->toBeFalse();
        });

        it('identifies ambiguous dates with dashes', function (): void {
            expect(DateFormat::isAmbiguous('01-02-2024'))->toBeTrue();
            expect(DateFormat::isAmbiguous('05-06-2024'))->toBeTrue();
        });

        it('identifies unambiguous dates with dashes', function (): void {
            expect(DateFormat::isAmbiguous('31-01-2024'))->toBeFalse();
            expect(DateFormat::isAmbiguous('01-31-2024'))->toBeFalse();
        });
    });

    describe('isUnambiguousFor', function (): void {
        it('ISO is always unambiguous', function (): void {
            expect(DateFormat::ISO->isUnambiguousFor('2024-01-15'))->toBeTrue();
        });

        it('European is unambiguous when day > 12', function (): void {
            expect(DateFormat::EUROPEAN->isUnambiguousFor('31/01/2024'))->toBeTrue();
            expect(DateFormat::EUROPEAN->isUnambiguousFor('01/02/2024'))->toBeFalse();
        });

        it('American is unambiguous when second position > 12', function (): void {
            expect(DateFormat::AMERICAN->isUnambiguousFor('01/31/2024'))->toBeTrue();
            expect(DateFormat::AMERICAN->isUnambiguousFor('01/02/2024'))->toBeFalse();
        });

        it('European is unambiguous when day > 12 with dashes', function (): void {
            expect(DateFormat::EUROPEAN->isUnambiguousFor('31-01-2024'))->toBeTrue();
        });

        it('American is unambiguous when second position > 12 with dashes', function (): void {
            expect(DateFormat::AMERICAN->isUnambiguousFor('01-31-2024'))->toBeTrue();
        });
    });

    describe('format', function (): void {
        it('formats Carbon dates correctly', function (): void {
            $date = Carbon::create(2024, 1, 15);

            expect(DateFormat::ISO->format($date))->toBe('2024-01-15');
            expect(DateFormat::EUROPEAN->format($date))->toBe('15/01/2024');
            expect(DateFormat::AMERICAN->format($date))->toBe('01/15/2024');
        });
    });

    describe('getExamples', function (): void {
        it('provides parseable first example', function (): void {
            foreach (DateFormat::cases() as $format) {
                $example = $format->getExamples()[0];
                $parsed = $format->parse($example);

                expect($parsed)->toBeInstanceOf(Carbon::class);
            }
        });

        it('returns multiple examples for European format', function (): void {
            $examples = DateFormat::EUROPEAN->getExamples();

            expect($examples)->toBeArray()
                ->toContain('15-05-2024')
                ->toContain('15/05/2024')
                ->toContain('15 May 2024');
        });

        it('returns multiple examples for American format', function (): void {
            $examples = DateFormat::AMERICAN->getExamples();

            expect($examples)->toBeArray()
                ->toContain('05-15-2024')
                ->toContain('05/15/2024')
                ->toContain('May 15th 2024');
        });

        it('returns single example for ISO format', function (): void {
            $examples = DateFormat::ISO->getExamples();

            expect($examples)->toBeArray()
                ->toHaveCount(1)
                ->toContain('2024-05-15');
        });
    });

    describe('getPhpFormats', function (): void {
        it('returns slash format first for formatting consistency', function (): void {
            expect(DateFormat::EUROPEAN->getPhpFormats()[0])->toBe('d/m/Y');
            expect(DateFormat::AMERICAN->getPhpFormats()[0])->toBe('m/d/Y');
        });

        it('returns multiple PHP format strings for European', function (): void {
            $formats = DateFormat::EUROPEAN->getPhpFormats();

            expect($formats)->toBeArray()
                ->toContain('d-m-Y')
                ->toContain('d/m/Y')
                ->toContain('j F Y');
        });

        it('returns multiple PHP format strings for American', function (): void {
            $formats = DateFormat::AMERICAN->getPhpFormats();

            expect($formats)->toBeArray()
                ->toContain('m-d-Y')
                ->toContain('m/d/Y')
                ->toContain('F jS Y');
        });

        it('returns single PHP format string for ISO', function (): void {
            $formats = DateFormat::ISO->getPhpFormats();

            expect($formats)->toBeArray()
                ->toHaveCount(1)
                ->toContain('Y-m-d');
        });
    });

    describe('multi-pattern parsing', function (): void {
        it('parses European dash format', function (): void {
            $result = DateFormat::EUROPEAN->parse('15-05-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15);
        });

        it('parses European natural language format', function (): void {
            $result = DateFormat::EUROPEAN->parse('15 May 2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15);
        });

        it('parses American dash format', function (): void {
            $result = DateFormat::AMERICAN->parse('05-15-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15);
        });

        it('parses American natural language format', function (): void {
            $result = DateFormat::AMERICAN->parse('May 15th 2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15);
        });

        it('parses all example patterns correctly', function (): void {
            foreach (DateFormat::cases() as $format) {
                foreach ($format->getExamples() as $example) {
                    $parsed = $format->parse($example);

                    expect($parsed)
                        ->toBeInstanceOf(Carbon::class, "Failed to parse '{$example}' with {$format->name} format");
                }
            }
        });
    });
});
