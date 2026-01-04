<?php

declare(strict_types=1);

use Carbon\Carbon;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\TimestampFormat;

describe('TimestampFormat', function (): void {
    describe('parse', function (): void {
        it('parses ISO format correctly (time last)', function (): void {
            $result = TimestampFormat::ISO->parse('2024-01-15 16:30:00');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30)
                ->second->toBe(0);
        });

        it('parses ISO format without seconds', function (): void {
            $result = TimestampFormat::ISO->parse('2024-01-15 16:30');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('parses ISO T-separator format', function (): void {
            $result = TimestampFormat::ISO->parse('2024-01-15T16:30:00');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('parses European format correctly (time first)', function (): void {
            $result = TimestampFormat::EUROPEAN->parse('16:30 15-01-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('parses European format with seconds', function (): void {
            $result = TimestampFormat::EUROPEAN->parse('21:30:02 15-05-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15)
                ->hour->toBe(21)
                ->minute->toBe(30)
                ->second->toBe(2);
        });

        it('parses European format with slash separator', function (): void {
            $result = TimestampFormat::EUROPEAN->parse('16:30 15/01/2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('parses American format correctly (time first)', function (): void {
            $result = TimestampFormat::AMERICAN->parse('16:30 01-15-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('parses American format with seconds', function (): void {
            $result = TimestampFormat::AMERICAN->parse('21:30:02 05-15-2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(5)
                ->day->toBe(15)
                ->hour->toBe(21)
                ->minute->toBe(30)
                ->second->toBe(2);
        });

        it('parses American format with slash separator', function (): void {
            $result = TimestampFormat::AMERICAN->parse('16:30 01/15/2024');

            expect($result)
                ->toBeInstanceOf(Carbon::class)
                ->year->toBe(2024)
                ->month->toBe(1)
                ->day->toBe(15)
                ->hour->toBe(16)
                ->minute->toBe(30);
        });

        it('returns null for invalid timestamps', function (): void {
            expect(TimestampFormat::ISO->parse('not-a-timestamp'))->toBeNull();
            expect(TimestampFormat::EUROPEAN->parse('invalid'))->toBeNull();
            expect(TimestampFormat::AMERICAN->parse(''))->toBeNull();
        });

        it('returns null for empty string', function (): void {
            expect(TimestampFormat::ISO->parse(''))->toBeNull();
            expect(TimestampFormat::EUROPEAN->parse('  '))->toBeNull();
        });
    });

    describe('getPhpFormat', function (): void {
        it('returns correct PHP format strings', function (): void {
            expect(TimestampFormat::ISO->getPhpFormat())->toBe('Y-m-d H:i:s');
            expect(TimestampFormat::EUROPEAN->getPhpFormat())->toBe('H:i:s d/m/Y');
            expect(TimestampFormat::AMERICAN->getPhpFormat())->toBe('H:i:s m/d/Y');
        });
    });

    describe('getLabel', function (): void {
        it('returns human-readable labels', function (): void {
            expect(TimestampFormat::ISO->getLabel())->toContain('ISO');
            expect(TimestampFormat::EUROPEAN->getLabel())->toContain('European');
            expect(TimestampFormat::AMERICAN->getLabel())->toContain('American');
        });
    });

    describe('getLabelWithExamples', function (): void {
        it('returns label with bullet-separated examples', function (): void {
            expect(TimestampFormat::ISO->getLabelWithExamples())
                ->toBe('ISO standard (2024-05-15 16:00:00)');

            expect(TimestampFormat::EUROPEAN->getLabelWithExamples())
                ->toBe('European (16:00 15-05-2024 • 21:30:02 15 May 2024)');

            expect(TimestampFormat::AMERICAN->getLabelWithExamples())
                ->toBe('American (16:00 05-15-2024 • 21:30:02 May 15th 2024)');
        });
    });

    describe('getExamples', function (): void {
        it('returns examples for ISO format (time last)', function (): void {
            $examples = TimestampFormat::ISO->getExamples();

            expect($examples)->toBeArray()
                ->toHaveCount(1)
                ->toContain('2024-05-15 16:00:00');
        });

        it('returns examples for European format (time first)', function (): void {
            $examples = TimestampFormat::EUROPEAN->getExamples();

            expect($examples)->toBeArray()
                ->toContain('16:00 15-05-2024')
                ->toContain('21:30:02 15 May 2024');
        });

        it('returns examples for American format (time first)', function (): void {
            $examples = TimestampFormat::AMERICAN->getExamples();

            expect($examples)->toBeArray()
                ->toContain('16:00 05-15-2024')
                ->toContain('21:30:02 May 15th 2024');
        });
    });

    describe('getPhpFormats', function (): void {
        it('returns ISO formats with time last', function (): void {
            $formats = TimestampFormat::ISO->getPhpFormats();

            expect($formats)->toBeArray()
                ->toContain('Y-m-d H:i:s')
                ->toContain('Y-m-d H:i')
                ->toContain('Y-m-d\TH:i:s');
        });

        it('returns European formats with time first', function (): void {
            $formats = TimestampFormat::EUROPEAN->getPhpFormats();

            expect($formats)->toBeArray()
                ->toContain('H:i d-m-Y')
                ->toContain('H:i:s d-m-Y')
                ->toContain('H:i d/m/Y');
        });

        it('returns American formats with time first', function (): void {
            $formats = TimestampFormat::AMERICAN->getPhpFormats();

            expect($formats)->toBeArray()
                ->toContain('H:i m-d-Y')
                ->toContain('H:i:s m-d-Y')
                ->toContain('H:i m/d/Y');
        });
    });

    describe('format', function (): void {
        it('formats Carbon datetimes correctly', function (): void {
            $datetime = Carbon::create(2024, 1, 15, 16, 30, 45);

            expect(TimestampFormat::ISO->format($datetime))->toBe('2024-01-15 16:30:45');
            expect(TimestampFormat::EUROPEAN->format($datetime))->toBe('16:30:45 15/01/2024');
            expect(TimestampFormat::AMERICAN->format($datetime))->toBe('16:30:45 01/15/2024');
        });
    });

    describe('toDateFormat', function (): void {
        it('converts to corresponding DateFormat', function (): void {
            expect(TimestampFormat::ISO->toDateFormat())->toBe(DateFormat::ISO);
            expect(TimestampFormat::EUROPEAN->toDateFormat())->toBe(DateFormat::EUROPEAN);
            expect(TimestampFormat::AMERICAN->toDateFormat())->toBe(DateFormat::AMERICAN);
        });
    });

    describe('fromDateFormat', function (): void {
        it('creates from DateFormat', function (): void {
            expect(TimestampFormat::fromDateFormat(DateFormat::ISO))->toBe(TimestampFormat::ISO);
            expect(TimestampFormat::fromDateFormat(DateFormat::EUROPEAN))->toBe(TimestampFormat::EUROPEAN);
            expect(TimestampFormat::fromDateFormat(DateFormat::AMERICAN))->toBe(TimestampFormat::AMERICAN);
        });
    });
});
