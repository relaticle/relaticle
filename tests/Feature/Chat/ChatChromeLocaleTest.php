<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

mutates(ChatInterface::class, ProposalEntity::class);

beforeEach(function (): void {
    $this->langPath = sys_get_temp_dir().'/chat-chrome-locale-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->langPath);
    File::put($this->langPath.'/fr.json', json_encode([
        'Ask anything...' => 'Demandez ce que vous voulez...',
        'Searching companies…' => 'Recherche des entreprises',
        'Reading company details…' => 'Lecture de la fiche entreprise',
        'New chat' => 'Nouvelle conversation',
        'Your session expired. Please sign in again: your message is saved locally.' => 'Votre session a expire',
    ], JSON_UNESCAPED_UNICODE));
    Lang::addJsonPath($this->langPath);
    Lang::addLines([
        'enums/proposal-entity.create_title.company' => 'Créer une entreprise',
        'enums/proposal-entity.create_summary.company' => 'Créer l’entreprise « :title »',
        'enums/proposal-entity.plural.company' => 'entreprise|entreprises',
    ], 'fr');

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->langPath);
});

it('renders the chat strings in the user language', function (): void {
    Locales::apply('fr');

    $html = Livewire::test(ChatInterface::class)->html();

    expect($html)
        ->toContain('Demandez ce que vous voulez...')
        ->toContain('Recherche des entreprises')
        ->toContain('Lecture de la fiche entreprise')
        ->toContain('Nouvelle conversation')
        ->toContain('Votre session a expire');
});

it('composes proposal card sentences in the user language', function (): void {
    Locales::within('fr', function (): void {
        expect(ProposalEntity::Company->createTitle())->toBe('Créer une entreprise')
            ->and(ProposalEntity::Company->createSummary('Acme'))->toBe('Créer l’entreprise « Acme »');
    });
});

it('pluralises the confirm label in the user language', function (): void {
    Locales::within('fr', function (): void {
        expect(ProposalEntity::Company->confirmLabel(PendingActionOperation::Delete, 2))->toBe('Delete 2 entreprises');
    });
});

it('keeps the sentences sent to the model in English', function (): void {
    Locales::within('fr', function (): void {
        expect(ProposalEntity::Email->noun())->toBe('email')
            ->and(ProposalEntity::Company->verb(PendingActionOperation::Create))->toBe('create')
            ->and(ProposalEntity::Email->notDone(PendingActionOperation::Create))->toBe('NOT sent');
    });
});
