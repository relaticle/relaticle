<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\Markdown\DecodeHtmlEntitiesPostprocessor;
use App\Support\PlainText;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Relaticle\Chat\Enums\ResponseFormat;
use Relaticle\Chat\Support\Markdown\TaskListConverter;
use Relaticle\CustomFields\Models\CustomFieldValue;

final readonly class LongTextForModel
{
    public const int CONCISE_LIMIT = 300;

    private const string ANY = '/^/';

    private const string ONE = '/^1$/';

    /** @var array<string, array<string, string>> tag => attribute => allowed value pattern */
    private const array MARKDOWN_ATTRIBUTES = [
        'p' => [], 'br' => [], 'hr' => [], 'blockquote' => [], 'pre' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'code' => ['class' => '/^language-[\w-]+$/'],
        'a' => ['href' => self::ANY, 'target' => self::ANY, 'rel' => self::ANY],
        'img' => ['src' => self::ANY, 'alt' => self::ANY, 'title' => self::ANY, 'data-id' => self::ANY],
        'ul' => ['data-type' => '/^taskList$/'],
        'ol' => ['start' => '/^\d+$/'],
        'li' => ['data-type' => '/^taskItem$/', 'data-checked' => '/^(true|false)$/'],
        'label' => [], 'div' => [], 'span' => [],
        'input' => ['type' => '/^checkbox$/', 'checked' => self::ANY],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [],
        'th' => ['colspan' => self::ONE, 'rowspan' => self::ONE],
        'td' => ['colspan' => self::ONE, 'rowspan' => self::ONE],
    ];

    /**
     * @param  array<string, mixed>  $values  custom field values by code
     * @return array{values: array<string, mixed>, truncated: list<string>}
     */
    public static function forListRow(Model $record, array $values, ResponseFormat $format): array
    {
        $truncated = [];

        foreach (self::freeTextFields($record) as $code => $type) {
            if (! is_string($values[$code] ?? null)) {
                continue;
            }

            $shortened = self::shortened($values[$code], $type, $format);
            $values[$code] = $shortened['text'];

            if ($shortened['truncated']) {
                $truncated[] = $code;
            }
        }

        return ['values' => $values, 'truncated' => $truncated];
    }

    /** @return array{text: string, truncated: bool} */
    private static function shortened(string $value, CustomFieldType $type, ResponseFormat $format): array
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
        if ($type !== CustomFieldType::RICH_EDITOR) {
            return $value;
        }

        return self::markdownHoldsAllOf($value) ? self::markdown($value) : $value;
    }

    // A tag or attribute missing here comes back as stored HTML, so an edit through
    // chat never drops a mark Markdown has no syntax for (underline, alignment, colour).
    private static function markdownHoldsAllOf(string $html): bool
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->body?->querySelectorAll('*') ?? [] as $element) {
            $allowed = self::MARKDOWN_ATTRIBUTES[strtolower($element->localName)] ?? null;

            if ($allowed === null) {
                return false;
            }

            foreach ($element->attributes as $attribute) {
                $pattern = $allowed[$attribute->name] ?? null;

                if ($pattern === null || preg_match($pattern, $attribute->value) !== 1) {
                    return false;
                }
            }
        }

        return true;
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
