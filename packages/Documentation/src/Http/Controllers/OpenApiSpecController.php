<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the Scribe-generated spec at the root URLs agents probe by
 * convention (/openapi.json, /openapi.yaml). The file exists only after
 * scribe:generate has run, so a missing spec is a 404 rather than a 500.
 */
final readonly class OpenApiSpecController
{
    public function json(): Response
    {
        $spec = Yaml::parse($this->spec());

        return response(json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200, [
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
        abort_unless(Storage::disk('local')->exists('scribe/openapi.yaml'), 404);

        return (string) Storage::disk('local')->get('scribe/openapi.yaml');
    }
}
