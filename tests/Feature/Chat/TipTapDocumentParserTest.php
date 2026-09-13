<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Services\TipTapDocumentParser;

mutates(TipTapDocumentParser::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
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

    $result = $parser->parse($document, $this->workspace);
    expect($result)->toMatchArray(['text' => 'Hello world', 'mentions' => []]);
});

it('returns empty text for an empty document', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $result = $parser->parse(['type' => 'doc', 'content' => []], $this->workspace);
    expect($result)->toMatchArray(['text' => '', 'mentions' => []]);
});

it('extracts mention nodes alongside text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);

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

    $result = $parser->parse($document, $this->workspace);

    expect($result['mentions'])->toHaveCount(1);
    expect($result['mentions'][0])->toMatchArray([
        'type' => 'company',
        'id' => $company->getKey(),
        'label' => 'Acme Corp',
    ]);
});

it('drops mentions whose entity belongs to a different workspace', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $otherWorkspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $foreignCompany = Company::factory()->for($otherWorkspace)->create();

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

    $result = $parser->parse($document, $this->workspace);

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

    $result = $parser->parse($document, $this->workspace);

    expect($result['mentions'])->toBe([]);
});

it('builds a document from text without mentions', function (): void {
    $parser = app(TipTapDocumentParser::class);

    $document = $parser->buildFromText('Hello world', [], $this->workspace);

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

    expect($parser->buildFromText('', [], $this->workspace))->toBe([
        'type' => 'doc',
        'content' => [],
    ]);
});

it('embeds mention nodes when labels appear in the text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);

    $document = $parser->buildFromText(
        'Found Acme in the system',
        [['type' => 'company', 'id' => $company->getKey(), 'label' => 'Acme']],
        $this->workspace,
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
    $workspaceA = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);
    $workspaceAB = Company::factory()->for($this->workspace)->create(['name' => 'Acme Holdings']);

    $document = $parser->buildFromText(
        'See Acme Holdings, sister of Acme',
        [
            ['type' => 'company', 'id' => $workspaceA->getKey(), 'label' => 'Acme'],
            ['type' => 'company', 'id' => $workspaceAB->getKey(), 'label' => 'Acme Holdings'],
        ],
        $this->workspace,
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

    expect($parser->parse($document, $this->workspace)['text'])->toBe("It's & <ok>");
});

it('renders mention labels inline in extracted text', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);

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

    expect($parser->parse($document, $this->workspace)['text'])->toBe('Tell me about Acme Corp please');
});

it('does not match mention labels inside larger words', function (): void {
    $parser = app(TipTapDocumentParser::class);
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);

    $document = $parser->buildFromText(
        'Acmeyards has Acme inside it',
        [['type' => 'company', 'id' => $company->getKey(), 'label' => 'Acme']],
        $this->workspace,
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

    $workspace = Workspace::factory()->create();

    resolve(TipTapDocumentParser::class)->parse($doc, $workspace);
})->throws(ValidationException::class, 'too deep');

it('throws when document exceeds max node count', function (): void {
    $children = [];
    for ($i = 0; $i < 5001; $i++) {
        $children[] = ['type' => 'text', 'text' => 'x'];
    }
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $children]]];

    $workspace = Workspace::factory()->create();

    resolve(TipTapDocumentParser::class)->parse($doc, $workspace);
})->throws(ValidationException::class, 'too large');
