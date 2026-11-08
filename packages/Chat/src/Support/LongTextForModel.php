<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Workspace;
use App\Support\Markdown\DecodeHtmlEntitiesPostprocessor;
use App\Support\PlainText;
use Illuminate\Support\Collection;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Relaticle\Chat\Support\Markdown\TaskListConverter;

final readonly class LongTextForModel
{
    public const int CONCISE_LIMIT = 300;

    public static function isLongText(CustomField $field): bool
    {
        return in_array($field->type, [CustomFieldType::RICH_EDITOR->value, CustomFieldType::TEXTAREA->value], true);
    }

    /** @return array{text: string, truncated: bool} */
    public static function concise(string $html): array
    {
        return self::conciseText(PlainText::fromHtml($html));
    }

    /** @return array{text: string, truncated: bool} */
    public static function conciseText(string $text): array
    {
        return [
            'text' => mb_substr($text, 0, self::CONCISE_LIMIT),
            'truncated' => mb_strlen($text) > self::CONCISE_LIMIT,
        ];
    }

    public static function markdown(string $html): string
    {
        $converter = new HtmlConverter([
            ...(array) config('markdown-response.driver_options.league.options', []),
            'header_style' => 'atx',
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter);
        $converter->getEnvironment()->addConverter(new TaskListConverter);

        return trim(resolve(DecodeHtmlEntitiesPostprocessor::class)($converter->convert($html)));
    }

    /** @return Collection<int, CustomField> */
    public static function richTextFields(Workspace $workspace, string $entityType): Collection
    {
        return CustomField::query()
            ->where('tenant_id', $workspace->getKey())
            ->where('entity_type', $entityType)
            ->where('type', CustomFieldType::RICH_EDITOR->value)
            ->active()
            ->get();
    }
}
