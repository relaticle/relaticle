<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

final class IndexRequest extends FormRequest
{
    private const array OPTIONAL_PAGINATION = ['per_page', 'cursor', 'page'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isMethod('POST') && ! $this->hasJsonObjectBody()) {
                    $validator->errors()->add('body', __('validation.filter.body_not_object'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (self::OPTIONAL_PAGINATION as $key) {
            if ($this->input($key) === null || $this->input($key) === '') {
                $this->getInputSource()->remove($key);
            }
        }
    }

    private function hasJsonObjectBody(): bool
    {
        $content = trim($this->getContent());

        if ($content === '') {
            return true;
        }

        if (! $this->isJson()) {
            return false;
        }

        $decoded = json_decode($content);

        return $decoded instanceof stdClass || $decoded === [];
    }
}
