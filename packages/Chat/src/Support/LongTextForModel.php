<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\Markdown\DecodeHtmlEntitiesPostprocessor;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
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
        if ($format === ResponseFormat::Detailed) {
            return ['text' => self::full($value, $type), 'truncated' => false];
        }

        $text = $type === CustomFieldType::RICH_EDITOR ? PlainText::fromHtml($value) : $value;

        return [
            'text' => mb_substr($text, 0, self::CONCISE_LIMIT),
            'truncated' => mb_strlen($text) > self::CONCISE_LIMIT,
        ];
    }

    public static function full(string $value, CustomFieldType $type): string
    {
        return $type === CustomFieldType::RICH_EDITOR ? self::markdown($value) : $value;
    }

    private static function markdown(string $html): string
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

        $fields = [];

        /** @var iterable<int, CustomFieldValue> $values */
        $values = $record->getRelation('customFieldValues');

        foreach ($values as $value) {
            $field = $value->getRelations()['customField'] ?? null;
            $type = $field instanceof CustomField ? CustomFieldType::tryFrom($field->type) : null;

            if ($field instanceof CustomField && $type?->isFreeText() === true) {
                $fields[$field->code] = $type;
            }
        }

        return $fields;
    }
}
