<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\TransientToken;
use League\Flysystem\UnableToCheckFileExistence;
use Relaticle\Cloud\Blog\BlogCover;
use Relaticle\Cloud\Blog\Jobs\GenerateBlogCover;
use Relaticle\Cloud\Console\Commands\BackfillBlogCoversCommand;
use Relaticle\Ink\Filament\Resources\PostResource\Pages\EditPost;
use Relaticle\Ink\Mcp\BlogServer;
use Relaticle\Ink\Mcp\Tools\UpdatePostTool;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BlogCover::class, GenerateBlogCover::class, BackfillBlogCoversCommand::class);

beforeEach(function (): void {
    Http::fake();
    $this->disk = Storage::fake('public');
    $this->post = Post::factory()->published()->create(['category_id' => Category::factory()]);
});

it('serves measured WebPs after an editor uploads an article cover', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $this->actingAs($admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    livewire(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->fillForm(['featured_image' => UploadedFile::fake()->image('cover.jpg', 580, 290)])
        ->call('save')
        ->assertHasNoFormErrors();

    $source = $this->post->refresh()->featured_image;
    $this->disk->assertExists($source);
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image" content="'.url($this->disk->url($source)).'"', false)
        ->assertSee('property="og:image:width" content="580"', false)
        ->assertSee('property="og:image:height" content="290"', false)
        ->assertSee('<source type="image/webp"', false)
        ->assertSee('580.webp 580w')
        ->assertDontSee('768.webp 768w');
    $this->get('/blog')->assertOk()->assertSee('320.webp 320w');

    $path = collect($this->disk->allFiles())->first(fn (string $path): bool => str_ends_with($path, '/320.webp'));
    expect(getimagesizefromstring($this->disk->get($path)))->toMatchArray([320, 160, IMAGETYPE_WEBP]);
});

it('serves only the latest MCP cover when jobs finish out of order and skips removed covers', function (): void {
    Queue::fake([GenerateBlogCover::class]);
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $admin->withAccessToken(new TransientToken);
    $server = BlogServer::actingAs($admin, config('ink.mcp.guard'));

    foreach (['one' => 330, 'two' => 480] as $name => $width) {
        $file = UploadedFile::fake()->image("{$name}.png", $width, 180);
        $this->disk->put("ink/{$name}.png", $file->getContent());
        $server->tool(UpdatePostTool::class, ['id' => $this->post->id, 'featured_image' => "ink/{$name}.png"])->assertOk();
    }

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('/storage/ink/two.png')
        ->assertDontSee('property="og:image:width"', false)
        ->assertDontSee('property="og:image:height"', false)
        ->assertDontSee('srcset=');

    $jobs = Queue::pushed(GenerateBlogCover::class);
    expect($jobs)->toHaveCount(2);
    foreach ($jobs->reverse() as $job) {
        $job->handle();
    }
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image:width" content="480"', false)
        ->assertSee('property="og:image:height" content="180"', false)
        ->assertSee('480.webp 480w')
        ->assertDontSee('330.webp 330w');

    $server->tool(UpdatePostTool::class, ['id' => $this->post->id, 'featured_image' => null])->assertOk();
    $this->disk->delete('ink/two.png');
    $jobs->last()->handle();
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('/images/open-graph.jpg?v=5')
        ->assertSee('property="og:image:width" content="2400"', false)
        ->assertSee('property="og:image:height" content="1260"', false)
        ->assertDontSee('srcset=');
});

it('backfills existing covers on their configured disk and resumes after a missing original returns', function (): void {
    config(['ink.uploads.disk' => 'covers']);
    $disk = Storage::fake('covers', ['url' => 'https://cdn.example.test/assets']);
    $this->post->updateQuietly(['featured_image' => 'ink/existing.png']);
    $this->artisan('blog:backfill-covers')->assertSuccessful();
    $this->artisan('blog:backfill-covers --force')->assertFailed();
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('https://cdn.example.test/assets/ink/existing.png')
        ->assertDontSee('property="og:image:width"', false);

    $disk->put('ink/existing.png', UploadedFile::fake()->image('cover.png', 630, 240)->getContent());
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    $variant = collect($disk->allFiles())->first(fn (string $path): bool => str_ends_with($path, '/320.webp'));
    touch($disk->path($variant), 12345);
    Cache::flush();
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    expect($disk->lastModified($variant))->toBe(12345);
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image:width" content="630"', false)
        ->assertSee('320.webp 320w');

    $disk->delete($variant);
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    $disk->assertExists($variant);

    $disk->put('ink/existing.png', UploadedFile::fake()->image('new.png', 220, 130)->getContent());
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image:height" content="130"', false)
        ->assertSee('220.webp 220w')
        ->assertDontSee('320.webp 320w');
});

it('keeps an incomplete set out of the article until the worker can finish every width', function (): void {
    $source = 'ink/interrupted.png';
    $bytes = UploadedFile::fake()->image('cover.png', 500, 300)->getContent();
    $this->disk->put($source, $bytes);
    $this->post->updateQuietly(['featured_image' => $source]);
    $blocked = (new BlogCover($source))->variantPath(hash('sha256', $bytes), 320);
    $this->disk->makeDirectory($blocked);

    $this->artisan('blog:backfill-covers --force')->assertFailed();
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('/storage/ink/interrupted.png')
        ->assertDontSee('property="og:image:width"', false)
        ->assertDontSee('srcset=');
    expect($this->disk->get($source))->toBe($bytes);

    $this->disk->deleteDirectory($blocked);
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('320.webp 320w')
        ->assertSee('500.webp 500w');
});

it('keeps the original animation and its dimensions for :extension covers', function (string $extension): void {
    $source = "ink/animated.{$extension}";
    $this->disk->put($source, file_get_contents(__DIR__."/../../Fixtures/covers/animated.{$extension}"));
    $this->post->update(['featured_image' => $source]);

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('src="'.url($this->disk->url($source)).'"', false)
        ->assertSee('property="og:image:width" content="96"', false)
        ->assertSee('property="og:image:height" content="48"', false)
        ->assertDontSee('srcset=');
})->with(['gif', 'png', 'webp']);

it('measures and encodes a rotated cover in its displayed orientation', function (): void {
    $this->disk->put('ink/portrait.jpg', file_get_contents(__DIR__.'/../../Fixtures/covers/rotated.jpg'));
    $this->post->update(['featured_image' => 'ink/portrait.jpg']);

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image:width" content="48"', false)
        ->assertSee('property="og:image:height" content="96"', false)
        ->assertSee('48.webp 48w');
    $variant = collect($this->disk->allFiles())->first(fn (string $path): bool => str_ends_with($path, '/48.webp'));
    expect(getimagesizefromstring($this->disk->get($variant)))->toMatchArray([48, 96]);
});

it('keeps the article readable when storage cannot look up the cover metadata', function (): void {
    config(['ink.uploads.disk' => 'unavailable']);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->allows('url')->with('ink/offline.png')->andReturn('https://cdn.example.test/ink/offline.png');
    $disk->allows('fileExists')->andThrow(UnableToCheckFileExistence::forLocation('blog-covers'));
    Storage::set('unavailable', $disk);
    $this->post->updateQuietly(['featured_image' => 'ink/offline.png']);

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('https://cdn.example.test/ink/offline.png')
        ->assertDontSee('property="og:image:width"', false)
        ->assertDontSee('srcset=');
});

it('serves covers above the worker pixel budget at their original measured size', function (): void {
    $this->disk->put('ink/large.png', file_get_contents(__DIR__.'/../../Fixtures/covers/large.png'));
    $this->post->update(['featured_image' => 'ink/large.png']);

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('src="'.url($this->disk->url('ink/large.png')).'"', false)
        ->assertSee('property="og:image:width" content="4100"', false)
        ->assertSee('property="og:image:height" content="2300"', false)
        ->assertDontSee('srcset=');
});

it('publishes only heights the WebP format can encode', function (): void {
    $source = 'ink/tall.png';
    $this->disk->put($source, UploadedFile::fake()->image('tall.png', 400, 18000)->getContent());
    $this->post->update(['featured_image' => $source]);

    $this->get($this->post->getUrl())->assertOk()
        ->assertSee('property="og:image:height" content="18000"', false)
        ->assertSee('320.webp 320w')
        ->assertDontSee('400.webp 400w');
});

it('queues each distinct source once when the deployment coordinator retries', function (): void {
    Queue::fake([GenerateBlogCover::class]);
    $this->post->updateQuietly(['featured_image' => 'ink/shared.png']);
    $translation = Post::factory()->create();
    $translation->updateQuietly(['featured_image' => 'ink/shared.png']);
    $independent = Post::factory()->create();
    $independent->updateQuietly(['featured_image' => 'ink/another.png']);

    $this->artisan('blog:backfill-covers --force --queue')->assertSuccessful();
    $this->artisan('blog:backfill-covers --force --queue')->assertSuccessful();

    Queue::assertPushed(GenerateBlogCover::class, 2);
    foreach (['ink/shared.png', 'ink/another.png'] as $source) {
        Queue::assertPushedOn('imports', GenerateBlogCover::class, fn (GenerateBlogCover $job): bool => $job->source === $source);
    }
});

it('queues the next committed edit after an attempted cover change rolls back', function (): void {
    Queue::fake([GenerateBlogCover::class]);
    $source = 'ink/retry.png';
    DB::beginTransaction();
    $this->post->update(['featured_image' => $source]);
    Queue::assertNotPushed(GenerateBlogCover::class);
    DB::rollBack();

    $this->post->refresh()->update(['featured_image' => $source]);
    Queue::assertPushed(GenerateBlogCover::class, 1);
    Queue::assertPushedOn('imports', GenerateBlogCover::class, fn (GenerateBlogCover $job): bool => $job->source === $source);
});

it('keeps a queued cover unique while an operator runs a synchronous backfill', function (): void {
    config(['queue.default' => 'database']);
    $source = 'ink/shared.png';
    $this->disk->put($source, UploadedFile::fake()->image('cover.png', 320, 160)->getContent());
    $this->post->updateQuietly(['featured_image' => $source]);

    $this->artisan('blog:backfill-covers --force --queue')->assertSuccessful();
    $this->artisan('blog:backfill-covers --force')->assertSuccessful();
    $this->artisan('blog:backfill-covers --force --queue')->assertSuccessful();

    expect(DB::table('jobs')->where('queue', 'imports')->count())->toBe(1);
});
