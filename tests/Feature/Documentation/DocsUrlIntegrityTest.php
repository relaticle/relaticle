<?php

declare(strict_types=1);

/** @return array{title: string, description: string} */
function headOf(string $html): array
{
    preg_match('/<title>(.*?)<\/title>/s', $html, $title);
    preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

    return ['title' => trim($title[1] ?? ''), 'description' => $description[1] ?? ''];
}

it('would regenerate the api reference with the head it serves today', function (): void {
    $served = $this->get('/developers/api')->assertOk()->getContent();

    $regenerated = view('scribe::external.scalar', [
        'metadata' => ['openapi_spec_url' => route('scribe.openapi'), 'title' => config('scribe.title')],
        'htmlAttributes' => [],
    ])->render();

    expect(headOf($regenerated))->toBe(headOf($served));
});
