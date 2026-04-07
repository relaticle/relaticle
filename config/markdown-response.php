<?php

declare(strict_types=1);

use Spatie\MarkdownResponse\Actions\DetectsMarkdownRequest;
use Spatie\MarkdownResponse\Actions\GeneratesCacheKey;
use Spatie\MarkdownResponse\Postprocessors\CollapseBlankLinesPostprocessor;
use Spatie\MarkdownResponse\Postprocessors\RemoveHtmlTagsPostprocessor;
use Spatie\MarkdownResponse\Preprocessors\RemoveScriptsAndStylesPreprocessor;

return [

    /*
     * When disabled, the middleware will not convert any responses to markdown.
     */
    'enabled' => env('MARKDOWN_RESPONSE_ENABLED', true),

    /*
     * The driver used to convert HTML to markdown.
     * Supported: "league", "cloudflare"
     */
    'driver' => env('MARKDOWN_RESPONSE_DRIVER', 'league'),

    'detection' => [

        /*
         * The class responsible for detecting whether a request wants
         * a markdown response. You can extend the default class to
         * customize the detection logic.
         */
        'detector' => DetectsMarkdownRequest::class,

        /*
         * When enabled, requests with an `Accept: text/markdown` header
         * will receive a markdown response.
         */
        'detect_via_accept_header' => true,

        /*
         * When enabled, URLs ending in `.md` (e.g. `/about.md`) will
         * receive a markdown response. The `.md` suffix is stripped
         * before routing, so `/about.md` resolves to `/about`.
         */
        'detect_via_md_suffix' => true,

        /*
         * Requests from user agents containing any of these strings
         * will automatically receive a markdown response. Matching
         * is case-insensitive.
         */
        'detect_via_user_agents' => [
            'GPTBot',
            'ClaudeBot',
            'Claude-Web',
            'Anthropic',
            'ChatGPT-User',
            'PerplexityBot',
            'Bytespider',
            'Google-Extended',
        ],
    ],

    /*
     * Preprocessors are run on the HTML before it is converted to
     * markdown. Each class must implement the Preprocessor interface.
     */
    'preprocessors' => [
        RemoveScriptsAndStylesPreprocessor::class,
    ],

    /*
     * Postprocessors are run on the markdown after conversion.
     * Each class must implement the Postprocessor interface.
     */
    'postprocessors' => [
        RemoveHtmlTagsPostprocessor::class,
        CollapseBlankLinesPostprocessor::class,
    ],

    'cache' => [

        /*
         * When enabled, converted markdown responses will be cached
         * so subsequent requests skip the conversion entirely.
         */
        'enabled' => env('MARKDOWN_RESPONSE_CACHE_ENABLED', true),

        /*
         * The cache store to use. Set to null to use the default store.
         */
        'store' => env('MARKDOWN_RESPONSE_CACHE_STORE'),

        /*
         * How long converted markdown should be cached, in seconds.
         */
        'ttl' => (int) env('MARKDOWN_RESPONSE_CACHE_TTL', 3600),

        /*
         * The class responsible for generating cache keys from requests.
         * You can extend the default class to customize the key generation.
         */
        'key_generator' => GeneratesCacheKey::class,

        /*
         * These query parameters will be stripped when generating cache
         * keys, so the same page with different tracking parameters
         * shares a single cache entry.
         */
        'ignored_query_parameters' => [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'gclid',
            'fbclid',
        ],
    ],

    /*
     * These signals are sent as a `Content-Signal` response header to
     * inform AI agents what they are allowed to do with your content.
     * Set to an empty array to disable the header entirely.
     *
     * See: https://contentstandards.org
     */
    'content_signals' => [
        'ai-train' => 'disallow',
        'ai-input' => 'allow',
        'search' => 'allow',
    ],

    'driver_options' => [

        /*
         * The league driver uses league/html-to-markdown.
         * Options are passed directly to the HtmlConverter constructor.
         * See: https://github.com/thephpleague/html-to-markdown#options
         */
        'league' => [
            'options' => [
                'strip_tags' => true,
                'hard_break' => true,
            ],
        ],

        /*
         * The Cloudflare driver uses the Workers AI API to convert
         * HTML to markdown. Requires an account ID and API token.
         */
        'cloudflare' => [
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
        ],

    ],
];
