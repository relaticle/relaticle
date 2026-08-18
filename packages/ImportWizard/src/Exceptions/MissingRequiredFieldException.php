<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Exceptions;

use RuntimeException;

/**
 * Thrown when a CSV row leaves a required field empty on create.
 *
 * Named for the reader of the failed-rows table: they need to know which column was
 * empty, not that a validation rule with some internal key did not pass.
 */
final class MissingRequiredFieldException extends RuntimeException
{
    public function __construct(public readonly string $fieldLabel)
    {
        parent::__construct(sprintf('%s is required and was empty.', $fieldLabel));
    }
}
