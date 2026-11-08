<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\PlainText;
use Illuminate\Support\Str;

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
        $plain = PlainText::fromHtml($html);

        return [
            'text' => Str::limit($plain, self::CONCISE_LIMIT, ''),
            'truncated' => mb_strlen($plain) > self::CONCISE_LIMIT,
        ];
    }

    public static function markdown(string $html): string
    {
        return trim(resolve('markdown-response.driver.league')->convert($html));
    }
}
