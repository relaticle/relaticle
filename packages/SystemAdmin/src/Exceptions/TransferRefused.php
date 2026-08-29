<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Exceptions;

use RuntimeException;

/**
 * Marks a transfer precondition failure the operator can act on (wrong
 * workspace pair, missing subscription, unmapped price), as distinct from an
 * infrastructure failure (query error, missing record) that must propagate
 * to error tracking instead of being shown as a business rule.
 */
final class TransferRefused extends RuntimeException {}
