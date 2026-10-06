<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\ListQuery;
use App\Queries\FilterTree;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

final class IndexRequest extends FormRequest
{
    public const string FIRST_CURSOR = 'true';

    public const int MAX_BODY_KILOBYTES = 256;

    private const array NAME_LISTS = ['include', 'sort'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.ListQuery::MAX_PAGE],
            'include' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string'],
            'fields' => ['sometimes', $this->fieldNames(...)],
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
            function (Validator $validator): void {
                if ($this->has('page') && $this->has('cursor')) {
                    $validator->errors()->add('page', __('validation.filter.page_with_cursor'));
                }
            },
            function (Validator $validator): void {
                if ($this->has('cursor') && ! $this->hasReadableCursor()) {
                    $validator->errors()->add('cursor', __('validation.filter.cursor'));
                }
            },
        ];
    }

    public function toListQuery(): ListQuery
    {
        return new ListQuery(
            filter: $this->input('filter'),
            sort: $this->validated('sort'),
            include: $this->validated('include'),
            fields: $this->validated('fields'),
            perPage: $this->safe()->integer('per_page', 15),
            cursor: $this->safe()->has('cursor'),
        );
    }

    protected function prepareForValidation(): void
    {
        abort_if(
            strlen($this->getContent()) > self::MAX_BODY_KILOBYTES * 1024,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            __('validation.filter.body_too_large', ['max' => self::MAX_BODY_KILOBYTES]),
        );

        foreach (array_keys($this->rules()) as $key) {
            if (in_array($this->input($key), [null, '', []], true)) {
                $this->getInputSource()->remove($key);
            }
        }

        foreach (self::NAME_LISTS as $key) {
            $names = $this->input($key);

            if (is_array($names) && array_is_list($names) && array_all($names, static fn (mixed $name): bool => is_string($name))) {
                $this->merge([$key => implode(',', $names)]);
            }
        }

        if ($this->input('cursor') === true) {
            $this->merge(['cursor' => self::FIRST_CURSOR]);
        }
    }

    protected function passedValidation(): void
    {
        if ($this->isMethod('POST')) {
            FilterTree::rejectUnknownArguments($this->json()->all(), ['filter', ...array_keys($this->rules())]);
        }
    }

    private function fieldNames(string $attribute, mixed $value, Closure $fail): void
    {
        $isNameList = static fn (mixed $names, int|string $recordType): bool => is_string($names)
            || (! is_numeric($recordType) && is_array($names) && array_all($names, static fn (mixed $name): bool => is_string($name)));

        if (! array_all(Arr::wrap($value), $isNameList)) {
            $fail(__('validation.filter.field_names'));
        }
    }

    private function hasReadableCursor(): bool
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) && ($cursor === self::FIRST_CURSOR || Cursor::fromEncoded($cursor) instanceof Cursor);
    }

    private function hasJsonObjectBody(): bool
    {
        $content = trim($this->getContent());

        if ($content === '') {
            return $this->request->count() === 0;
        }

        if (! $this->isJson()) {
            return false;
        }

        // json() casts its decode to an array, so an empty result cannot tell {} from truncated json.
        return match ($content[0]) {
            '{' => $this->json()->count() > 0 || json_validate($content),
            '[' => $this->json()->count() === 0 && json_validate($content),
            default => false,
        };
    }
}
