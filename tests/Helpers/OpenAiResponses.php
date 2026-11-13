<?php

declare(strict_types=1);

namespace Tests\Helpers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A faked OpenAI Responses API that answers the way the reasoning models do:
 * a request carrying a sampling parameter is rejected with the provider's 400.
 */
final class OpenAiResponses
{
    private const int REASONING_TOKENS = 46;

    private const float CHARACTERS_PER_TOKEN = 1.8;

    /** @param array<string, mixed> $structured */
    public static function fakeStructured(array $structured, string $model = 'gpt-5.6-luna'): void
    {
        Http::fake([
            'api.openai.com/*' => static fn (Request $request): PromiseInterface => array_key_exists('temperature', $request->data())
                ? Http::response(['error' => ['message' => "Unsupported parameter: 'temperature' is not supported with this model.", 'type' => 'invalid_request_error', 'param' => 'temperature', 'code' => 'unsupported_parameter']], 400)
                : Http::response([
                    'id' => 'resp_fake',
                    'model' => $model,
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [['type' => 'output_text', 'text' => json_encode($structured, JSON_THROW_ON_ERROR), 'annotations' => []]],
                    ]],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 6],
                ]),
        ]);
    }

    /** @param array<string, mixed> $structured */
    public static function fakeStructuredWithinBudget(array $structured): void
    {
        Http::fake([
            'api.openai.com/*' => static function (Request $request) use ($structured): PromiseInterface {
                $text = json_encode($structured, JSON_THROW_ON_ERROR);
                $visibleCharacters = (int) floor(max(0, $request->data()['max_output_tokens'] - self::REASONING_TOKENS) * self::CHARACTERS_PER_TOKEN);
                $status = mb_strlen($text) > $visibleCharacters ? 'incomplete' : 'completed';

                return Http::response([
                    'id' => 'resp_fake',
                    'model' => 'gpt-5.6-luna',
                    'status' => $status,
                    'output' => [[
                        'type' => 'message',
                        'status' => $status,
                        'content' => [['type' => 'output_text', 'text' => mb_substr($text, 0, $visibleCharacters), 'annotations' => []]],
                    ]],
                    'usage' => ['input_tokens' => 533, 'output_tokens' => self::REASONING_TOKENS + 20, 'output_tokens_details' => ['reasoning_tokens' => self::REASONING_TOKENS]],
                ]);
            },
        ]);
    }
}
