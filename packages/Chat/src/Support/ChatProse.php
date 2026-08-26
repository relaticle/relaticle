<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * The prose class list every rendered assistant message uses. The transcript and
 * the dashboard both render the same Markdown through MarkdownRenderer, so the
 * styling has exactly one home.
 */
final readonly class ChatProse
{
    public const string MESSAGE = 'prose prose-sm dark:prose-invert w-full max-w-none [overflow-wrap:anywhere] px-1 py-1 text-gray-900 dark:text-gray-100 [&>*:first-child]:mt-0 [&>*:last-child]:mb-0 prose-headings:text-gray-900 dark:prose-headings:text-white prose-table:my-2 prose-table:border-collapse prose-thead:border-b prose-thead:border-gray-300 dark:prose-thead:border-gray-600 prose-th:px-2 prose-th:py-2 prose-th:text-left prose-td:border-t prose-td:border-gray-100 prose-td:px-2 prose-td:py-2 dark:prose-td:border-gray-700 prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[length:var(--text-micro)] prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-gray-900 prose-pre:rounded-lg prose-pre:bg-gray-900 prose-pre:text-gray-100 first:prose-headings:mt-0';
}
