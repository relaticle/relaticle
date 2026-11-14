<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

final class OptionsChangedException extends ValidationException {}
