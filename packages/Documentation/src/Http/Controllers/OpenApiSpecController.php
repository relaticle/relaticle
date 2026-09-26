<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

final readonly class OpenApiSpecController
{
    private const string SPEC_PATH = 'scribe/openapi.yaml';

    private const string JSON_CACHE_KEY = 'documentation.openapi-json';

    public function json(): Response
    {
        return response($this->toJson($this->spec()), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function yaml(): Response
    {
        return response($this->spec(), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }

    private function spec(): string
    {
        $spec = Storage::disk('local')->get(self::SPEC_PATH);

        abort_if($spec === null, 404);

        return $spec;
    }

    private function toJson(string $yaml): string
    {
        $signature = hash('xxh128', $yaml);

        /** @var array{signature: string, json: string}|null $cached */
        $cached = Cache::get(self::JSON_CACHE_KEY);

        if (is_array($cached) && $cached['signature'] === $signature) {
            return $cached['json'];
        }

        $json = json_encode(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        Cache::forever(self::JSON_CACHE_KEY, ['signature' => $signature, 'json' => $json]);

        return $json;
    }
}
