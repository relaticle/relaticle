<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Services\TeamMentionResolver;
use Filament\Facades\Filament;
use Relaticle\Comments\CommentsConfig;
use Relaticle\Comments\Livewire\Comments;
use Relaticle\Comments\Models\Comment;

mutates(TeamMentionResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('exposes the comments action on the :dataset view page', function (string $pageClass, string $modelClass): void {
    $record = $modelClass::factory()->recycle([$this->user, $this->team])->create();

    livewire($pageClass, ['record' => $record->getKey()])
        ->assertActionExists('comments');
})->with([
    'company' => [ViewCompany::class, Company::class],
    'person' => [ViewPeople::class, People::class],
    'opportunity' => [ViewOpportunity::class, Opportunity::class],
]);

it('persists a comment stamped with the current workspace', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    livewire(Comments::class, ['model' => $company])
        ->set('commentData.body', '<p>Kickoff call scheduled for Friday.</p>')
        ->call('addComment');

    $comment = Comment::withoutGlobalScopes()->sole();

    expect($comment->team_id)->toBe($this->team->getKey())
        ->and($comment->commentable_type)->toBe('company')
        ->and($comment->commentable_id)->toBe($company->getKey())
        ->and($comment->commenter_id)->toBe($this->user->getKey());
});

it('hides comments from other workspaces', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    livewire(Comments::class, ['model' => $company])
        ->set('commentData.body', '<p>Internal note for our workspace only.</p>')
        ->call('addComment');

    $intruder = User::factory()->withTeam()->create();
    $this->actingAs($intruder);
    Filament::setTenant($intruder->currentTeam);

    expect(Comment::query()->count())->toBe(0)
        ->and(Comment::withoutGlobalScopes()->count())->toBe(1);
});

it('limits mention lookups to current workspace members', function (): void {
    $teammate = User::factory()->create(['name' => 'Teammate Tessa']);
    $this->team->users()->attach($teammate, ['role' => 'editor']);
    User::factory()->withTeam()->create(['name' => 'Teammate Rival']);

    $resolver = resolve(TeamMentionResolver::class);

    expect($resolver->search('Teammate')->pluck('name')->all())->toBe(['Teammate Tessa'])
        ->and($resolver->resolveByNames(['Teammate Tessa'])->sole()->getKey())->toBe($teammate->getKey())
        ->and($resolver->resolveByNames(['Teammate Rival']))->toBeEmpty();
});

it('includes the workspace owner in mention lookups', function (): void {
    $resolver = resolve(TeamMentionResolver::class);

    expect($resolver->search($this->user->name)->sole()->getKey())->toBe($this->user->getKey());
});

it('scopes editor mention autocomplete to workspace members', function (): void {
    $teammate = User::factory()->create(['name' => 'Teammate Tessa']);
    $this->team->users()->attach($teammate, ['role' => 'editor']);
    User::factory()->withTeam()->create(['name' => 'Teammate Rival']);

    $results = CommentsConfig::makeMentionProvider()->getSearchResults('Teammate');

    expect($results)->toBe([$teammate->getKey() => 'Teammate Tessa']);
});
