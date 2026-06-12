<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

mutates(TipTapDocumentParser::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

it('extracts plain text from a paragraph-only document', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['text'])->toBe('Hello world');
    expect($result['mentions'])->toBe([]);
});

it('returns empty text for an empty document', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $result = $parser->parse(['type' => 'doc', 'content' => []], $this->team);

    expect($result['text'])->toBe('');
    expect($result['mentions'])->toBe([]);
});

it('extracts mention nodes alongside text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Tell me about '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => 'Acme Corp',
                ]],
                ['type' => 'text', 'text' => ' please'],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toHaveCount(1);
    expect($result['mentions'][0])->toMatchArray([
        'type' => 'company',
        'id' => $company->getKey(),
        'label' => 'Acme Corp',
    ]);
});

it('drops mentions whose entity belongs to a different team', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCompany = Company::factory()->for($otherTeam)->create();

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $foreignCompany->getKey(),
                    'label' => 'Foreign',
                ]],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toBe([]);
});

it('drops mentions of unknown entity types', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => [
                    'type' => 'invoice',
                    'id' => '01k...',
                    'label' => 'INV-1',
                ]],
            ],
        ]],
    ];

    $result = $parser->parse($document, $this->team);

    expect($result['mentions'])->toBe([]);
});

it('builds a document from text without mentions', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = $parser->buildFromText('Hello world', [], $this->team);

    expect($document)->toBe([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
        ]],
    ]);
});

it('returns an empty document for empty text', function (): void {
    $parser = app(TipTapDocumentParser::class);

    expect($parser->buildFromText('', [], $this->team))->toBe([
        'type' => 'doc',
        'content' => [],
    ]);
});

it('embeds mention nodes when labels appear in the text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $document = $parser->buildFromText(
        'Found Acme in the system',
        [['type' => 'company', 'id' => $company->getKey(), 'label' => 'Acme']],
        $this->team,
    );

    expect($document['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Found '],
        ['type' => 'mention', 'attrs' => [
            'type' => 'company',
            'id' => $company->getKey(),
            'label' => 'Acme',
        ]],
        ['type' => 'text', 'text' => ' in the system'],
    ]);
});

it('matches longer labels before shorter overlapping ones', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $teamA = Company::factory()->for($this->team)->create(['name' => 'Acme']);
    $teamAB = Company::factory()->for($this->team)->create(['name' => 'Acme Holdings']);

    $document = $parser->buildFromText(
        'See Acme Holdings, sister of Acme',
        [
            ['type' => 'company', 'id' => $teamA->getKey(), 'label' => 'Acme'],
            ['type' => 'company', 'id' => $teamAB->getKey(), 'label' => 'Acme Holdings'],
        ],
        $this->team,
    );

    $nodes = $document['content'][0]['content'];

    expect($nodes[1]['type'])->toBe('mention');
    expect($nodes[1]['attrs']['label'])->toBe('Acme Holdings');

    $last = end($nodes);
    expect($last['type'])->toBe('mention');
    expect($last['attrs']['label'])->toBe('Acme');
});

it('preserves apostrophes, ampersands, and angle brackets in extracted text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => "It's & <ok>"],
            ],
        ]],
    ];

    expect($parser->parse($document, $this->team)['text'])->toBe("It's & <ok>");
});

it('renders mention labels inline in extracted text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Tell me about '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => 'Acme Corp',
                ]],
                ['type' => 'text', 'text' => ' please'],
            ],
        ]],
    ];

    expect($parser->parse($document, $this->team)['text'])->toBe('Tell me about Acme Corp please');
});

it('does not match mention labels inside larger words', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $document = $parser->buildFromText(
        'Acmeyards has Acme inside it',
        [['type' => 'company', 'id' => $company->getKey(), 'label' => 'Acme']],
        $this->team,
    );

    $nodes = $document['content'][0]['content'];

    expect($nodes[0])->toBe(['type' => 'text', 'text' => 'Acmeyards has ']);
    expect($nodes[1]['type'])->toBe('mention');
    expect($nodes[1]['attrs']['label'])->toBe('Acme');
    expect(end($nodes))->toBe(['type' => 'text', 'text' => ' inside it']);
});

it('throws when document exceeds max node depth', function (): void {
    $deep = ['type' => 'text', 'text' => 'leaf'];
    for ($i = 0; $i < 65; $i++) {
        $deep = ['type' => 'paragraph', 'content' => [$deep]];
    }
    $doc = ['type' => 'doc', 'content' => [$deep]];

    $team = Team::factory()->create();

    resolve(TipTapDocumentParser::class)->parse($doc, $team);
})->throws(ValidationException::class, 'too deep');

it('throws when document exceeds max node count', function (): void {
    $children = [];
    for ($i = 0; $i < 5001; $i++) {
        $children[] = ['type' => 'text', 'text' => 'x'];
    }
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $children]]];

    $team = Team::factory()->create();

    resolve(TipTapDocumentParser::class)->parse($doc, $team);
})->throws(ValidationException::class, 'too large');
