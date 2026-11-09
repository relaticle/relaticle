<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\Markdown\DecodeHtmlEntitiesPostprocessor;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Relaticle\Chat\Enums\ResponseFormat;
use Relaticle\Chat\Support\Markdown\TaskListConverter;
use Relaticle\CustomFields\Models\CustomFieldValue;

final readonly class LongTextForModel
{
    public const int CONCISE_LIMIT = 300;

    /** @return array{text: string, truncated: bool} */
    public static function forList(string $value, CustomFieldType $type, ResponseFormat $format): array
    {
        $isRichText = $type === CustomFieldType::RICH_EDITOR;

        if ($format === ResponseFormat::Detailed) {
            return ['text' => $isRichText ? self::markdown($value) : $value, 'truncated' => false];
        }

        $text = $isRichText ? PlainText::fromHtml($value) : $value;

        return [
            'text' => mb_substr($text, 0, self::CONCISE_LIMIT),
            'truncated' => mb_strlen($text) > self::CONCISE_LIMIT,
        ];
    }

    public static function markdown(string $html): string
    {
        $converter = new HtmlConverter(['strip_tags' => true, 'hard_break' => false, 'header_style' => 'atx']);
        $converter->getEnvironment()->addConverter(new TableConverter);
        $converter->getEnvironment()->addConverter(new TaskListConverter);

        return trim(resolve(DecodeHtmlEntitiesPostprocessor::class)($converter->convert($html)));
    }

    /** @return array<string, CustomFieldType> */
    public static function freeTextFields(Model $record): array
    {
        if (! $record->relationLoaded('customFieldValues')) {
            return [];
        }

        /** @var Collection<int, CustomFieldValue> $values */
        $values = $record->getRelation('customFieldValues');

        return $values
            ->map(static fn (CustomFieldValue $value): ?CustomField => $value->getRelations()['customField'] ?? null)
            ->filter(static fn (?CustomField $field): bool => $field !== null && CustomFieldType::tryFrom($field->type)?->isFreeText() === true)
            ->mapWithKeys(static fn (CustomField $field): array => [$field->code => CustomFieldType::from($field->type)])
            ->all();
    }
}
