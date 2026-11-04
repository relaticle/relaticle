<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics;

use Illuminate\Http\Request;

final readonly class Attribution
{
    private const array SOURCES = [
        'google', 'bing', 'duckduckgo', 'github', 'producthunt', 'linkedin',
        'youtube', 'reddit', 'facebook', 'twitter', 'chatgpt', 'claude',
        'gemini', 'perplexity', 'newsletter', 'discord',
    ];

    private const array REFERRERS = [
        'google.com' => 'google', 'bing.com' => 'bing', 'duckduckgo.com' => 'duckduckgo',
        'github.com' => 'github', 'producthunt.com' => 'producthunt', 'linkedin.com' => 'linkedin',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'reddit.com' => 'reddit',
        'facebook.com' => 'facebook', 'twitter.com' => 'twitter', 'x.com' => 'twitter',
        'chatgpt.com' => 'chatgpt', 'chat.openai.com' => 'chatgpt', 'claude.ai' => 'claude',
        'gemini.google.com' => 'gemini', 'perplexity.ai' => 'perplexity', 'discord.com' => 'discord',
    ];

    public static function optedOut(Request $request): bool
    {
        return $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1';
    }

    /** @return array{source: string, medium: ?string, campaign: ?string, landing: string, captured_at: int} */
    public static function capture(Request $request): array
    {
        $source = $request->query('utm_source');
        $medium = $request->query('utm_medium');
        $campaign = $request->query('utm_campaign');

        return [
            'source' => is_string($source) && in_array($source, self::SOURCES, true)
                ? $source : self::referrerSource($request),
            'medium' => is_string($medium) && in_array($medium, ['organic', 'referral', 'social', 'email', 'cpc', 'paid'], true)
                ? $medium : null,
            'campaign' => is_string($campaign) && strlen($campaign) <= 64
                && in_array($campaign, config('analytics.campaigns', []), true) ? $campaign : null,
            'landing' => self::landing($request),
            'captured_at' => now()->getTimestamp(),
        ];
    }

    private static function referrerSource(Request $request): string
    {
        $host = parse_url((string) $request->header('referer'), PHP_URL_HOST);

        if (! is_string($host)) {
            return 'unknown';
        }

        $host = preg_replace('/^www\./', '', strtolower($host));

        return self::REFERRERS[$host] ?? 'unknown';
    }

    private static function landing(Request $request): string
    {
        return match (true) {
            $request->is('/') => 'home',
            $request->is('pricing') => 'pricing',
            $request->is('docs', 'docs/*') => 'docs',
            $request->is('help', 'help/*') => 'help',
            $request->is('blog', 'blog/*') => 'blog',
            $request->is('compare/*', 'alternatives/*') => 'comparison',
            $request->is('ai', 'ai-native-crm', 'crm-for-*') => 'assistant',
            $request->routeIs('login', 'filament.app.auth.login') => 'login',
            default => 'other',
        };
    }
}
