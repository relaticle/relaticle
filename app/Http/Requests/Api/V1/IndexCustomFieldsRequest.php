<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexCustomFieldsRequest extends FormRequest
{
    private const int MAX_PER_PAGE = 100;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['sometimes', 'string', Rule::in(CrmEntity::morphAliases())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
