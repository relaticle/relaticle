<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexCustomFieldsRequest extends FormRequest
{
    private const array ENTITY_TYPES = ['company', 'people', 'opportunity', 'task', 'note'];

    private const int MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['sometimes', 'string', Rule::in(self::ENTITY_TYPES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function maxPerPage(): int
    {
        return self::MAX_PER_PAGE;
    }
}
