<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\Blog\CategoryPolicy;
use App\Policies\Blog\PostPolicy;
use App\Policies\Blog\TagPolicy;
use Filament\Facades\Filament;
use Relaticle\Ink\Filament\Resources\CategoryResource\Pages\ListCategories;
use Relaticle\Ink\Filament\Resources\PostResource\Pages\ListPosts;
use Relaticle\Ink\Filament\Resources\TagResource\Pages\ListTags;

mutates(PostPolicy::class, CategoryPolicy::class, TagPolicy::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('renders the blog admin :name index page under strict authorization', function (string $page): void {
    livewire($page)->assertOk();
})->with([
    'posts' => ListPosts::class,
    'categories' => ListCategories::class,
    'tags' => ListTags::class,
]);
