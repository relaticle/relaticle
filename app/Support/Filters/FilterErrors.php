<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Validation\ValidationException;

final readonly class FilterErrors
{
    public static function at(string $path, string $message): ValidationException
    {
        return ValidationException::withMessages([$path => [$message]]);
    }

    public static function prefix(ValidationException $exception, string|int $segment): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages[$key === '' ? (string) $segment : "{$segment}.{$key}"] = $errors;
        }

        return ValidationException::withMessages($messages);
    }
}
