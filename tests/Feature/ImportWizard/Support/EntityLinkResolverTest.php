<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Support\EntityLinkResolver;

mutates(EntityLinkResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

it('resolves team member by email via pivot', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member->email]);

    expect($result[$member->email])->toBe($member->id);
});

it('resolves team owner by email', function (): void {
    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$this->user->email]);

    expect($result[$this->user->email])->toBe($this->user->id);
});

it('resolves team member by ID', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::id()])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::id();

    $result = $resolver->batchResolve($link, $matcher, [$member->id]);

    expect($result[$member->id])->toBe($member->id);
});

it('returns null for non-team-member email', function (): void {
    $stranger = User::factory()->create();

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$stranger->email]);

    expect($result[$stranger->email])->toBeNull();
});

it('resolves multiple team members in batch', function (): void {
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();
    $this->team->users()->attach($member1, ['role' => 'editor']);
    $this->team->users()->attach($member2, ['role' => 'editor']);

    $resolver = new EntityLinkResolver($this->team->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member1->email, $member2->email]);

    expect($result[$member1->email])->toBe($member1->id)
        ->and($result[$member2->email])->toBe($member2->id);
});
