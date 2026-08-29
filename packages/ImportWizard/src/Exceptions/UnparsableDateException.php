<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Exceptions;

use Relaticle\ImportWizard\Enums\DateFormat;
use RuntimeException;

/**
 * Thrown when a mapped date column holds a value the chosen format cannot read.
 *
 * The message is written for the person reading the failed-rows table, not for a log:
 * it names the column, quotes what was actually in the cell, and states the format that
 * was expected, because those three together are what let someone fix their CSV.
 */
final class UnparsableDateException extends RuntimeException
{
    public function __construct(
        public readonly string $columnName,
        public readonly string $value,
        public readonly DateFormat $expectedFormat,
    ) {
        parent::__construct(sprintf(
            '%s: "%s" is not a valid date for the selected format (%s).',
            $columnName,
            $value,
            $expectedFormat->getLabel(),
        ));
    }
}
