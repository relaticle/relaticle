<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;
use Laravel\Sanctum\TransientToken;
use Relaticle\Ink\Mcp\BlogServer;
use Relaticle\Ink\Mcp\Tools\CreatePostTool;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AppServiceProvider::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create([
        'role' => SystemAdministratorRole::SuperAdministrator,
        'email' => 'founder@relaticle.com',
    ]);
    $this->admin->withAccessToken(new TransientToken);
    $this->category = Category::create(['name' => 'Engineering']);
});

it('creates a post authored by the matching user account', function (): void {
    $author = User::factory()->create(['email' => 'founder@relaticle.com']);

    BlogServer::actingAs($this->admin)
        ->tool(CreatePostTool::class, [
            'title' => 'Agent-Native CRM',
            'content' => 'Body text.',
            'excerpt' => 'What agent-native means.',
            'category_id' => $this->category->id,
            'status' => 'draft',
        ])
        ->assertOk();

    expect(Post::where('title', 'Agent-Native CRM')->first()?->author?->is($author))->toBeTrue();
});

it('fails loudly when no user account matches the sysadmin email', function (): void {
    BlogServer::actingAs($this->admin)
        ->tool(CreatePostTool::class, [
            'title' => 'Orphan Post',
            'content' => 'Body.',
            'excerpt' => 'No author.',
            'category_id' => $this->category->id,
        ])
        ->assertHasErrors(['No author could be resolved']);

    expect(Post::where('title', 'Orphan Post')->exists())->toBeFalse();
});
