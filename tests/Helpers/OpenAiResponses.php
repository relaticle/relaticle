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
    /** @param array<string, mixed> $structured */
    public static function fakeStructured(array $structured): void
    {
        Http::fake([
            'api.openai.com/*' => static fn (Request $request): PromiseInterface => array_key_exists('temperature', $request->data())
                ? Http::response(['error' => ['message' => "Unsupported parameter: 'temperature' is not supported with this model.", 'type' => 'invalid_request_error', 'param' => 'temperature', 'code' => 'unsupported_parameter']], 400)
                : Http::response([
                    'id' => 'resp_fake',
                    'model' => 'gpt-5.6-luna',
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
}
