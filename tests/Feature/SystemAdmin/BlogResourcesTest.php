<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Relaticle\Ink\Filament\Resources\CategoryResource;
use Relaticle\Ink\Filament\Resources\CategoryResource\Pages\ListCategories;
use Relaticle\Ink\Filament\Resources\PostResource;
use Relaticle\Ink\Filament\Resources\PostResource\Pages\ListPosts;
use Relaticle\Ink\Filament\Resources\TagResource;
use Relaticle\Ink\Filament\Resources\TagResource\Pages\ListTags;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Policies\CategoryPolicy;
use Relaticle\SystemAdmin\Policies\PostPolicy;
use Relaticle\SystemAdmin\Policies\TagPolicy;

mutates(PostPolicy::class, CategoryPolicy::class, TagPolicy::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the blog admin :name index page under strict authorization', function (string $page): void {
    livewire($page)->assertOk();
})->with([
    'posts' => ListPosts::class,
    'categories' => ListCategories::class,
    'tags' => ListTags::class,
]);

it('keeps the blog admin out of the customer panel', function (): void {
    $appResources = Filament::getPanel('app')->getResources();

    expect($appResources)->not->toContain(PostResource::class)
        ->and($appResources)->not->toContain(CategoryResource::class)
        ->and($appResources)->not->toContain(TagResource::class);
});

it('leaves filament tenant scoping enabled for the crm resources', function (): void {
    // The blog resources used to opt out of tenant scoping with scopeToTenant(false),
    // which writes a static declared once on Filament's BelongsToTenant trait and so
    // switched scoping off for every resource in the app panel, not just the blog.
    expect(CompanyResource::isScopedToTenant())->toBeTrue()
        ->and(PeopleResource::isScopedToTenant())->toBeTrue()
        ->and(TaskResource::isScopedToTenant())->toBeTrue();
});

it('keeps published posts when the account that authored them is deleted', function (): void {
    // Ink ships author_id as ON DELETE CASCADE. Users are hard-deleted (no SoftDeletes)
    // by app:purge-scheduled-deletions on a daily schedule, so an author closing their
    // account silently took the company's marketing content with it.
    $author = User::factory()->withPersonalTeam()->create();
    $post = Post::factory()->published()->create(['author_id' => $author->id]);

    app(DeletesUsers::class)->delete($author);

    expect(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($post->id)->value('author_id'))->toBeNull();
});

it('allows only one seo row per post', function (): void {
    $post = Post::factory()->published()->create();

    expect(fn () => $post->addSEO())->toThrow(QueryException::class);
});

it('cleans up the seo row when a post is force deleted but keeps it on a soft delete', function (): void {
    $post = Post::factory()->published()->create();

    $post->delete();

    expect(DB::table('seo')->where('model_id', $post->id)->exists())->toBeTrue();

    $post->forceDelete();

    expect(DB::table('seo')->where('model_id', $post->id)->exists())->toBeFalse();
});

it('denies blog authoring to a signed-in customer', function (): void {
    // A customer request runs on the app panel, where the policy guesser falls back to
    // Laravel's default discovery. No App-side blog policy exists any more, so the gate
    // denies rather than handing the customer the company's marketing content.
    Filament::setCurrentPanel('app');

    $customer = User::factory()->withPersonalTeam()->create();
    $post = Post::factory()->published()->create();

    expect($customer->can('viewAny', Post::class))->toBeFalse()
        ->and($customer->can('create', Post::class))->toBeFalse()
        ->and($customer->can('update', $post))->toBeFalse()
        ->and($customer->can('delete', $post))->toBeFalse();
});
