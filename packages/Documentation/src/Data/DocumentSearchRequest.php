<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

final class DocumentSearchRequest extends Data
{
    public function __construct(
        #[StringType]
        #[Min(3)]
        public string $query,

        #[StringType]
        public ?string $type = null,
    ) {}

    /**
     * @return array<string, array<int, string|In>>
     */
    public static function rules(): array
    {
        $minLength = config('documentation.search.min_length', 3);
        $documentTypes = array_keys(config('documentation.documents', []));

        return [
            'query' => ['required', 'string', 'min:'.$minLength],
            'type' => ['nullable', 'string', Rule::in($documentTypes)],
        ];
    }
}
