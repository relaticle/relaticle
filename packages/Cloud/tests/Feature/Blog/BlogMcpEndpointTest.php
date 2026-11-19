<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Relaticle\Cloud\Blog\BlogMcpStreams;
use Relaticle\Cloud\Commands\BlogMcpCommand;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class FragmentedStream
{
    public static string $payload = '';

    public mixed $context = null;

    private int $position = 0;

    private bool $emptyNext = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $this->emptyNext = ! $this->emptyNext;

        if (! $this->emptyNext) {
            return '';
        }

        $chunk = substr(self::$payload, $this->position, 4096);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$payload);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

mutates(BlogMcpCommand::class);

/**
 * @param  list<array<string, mixed>>  $messages
 * @return array<int, array<string, mixed>>
 */
function runBlogMcp(array $messages, bool $fragmented = false): array
{
    $payload = implode("\n", array_map(json_encode(...), $messages))."\n";

    if ($fragmented) {
        FragmentedStream::$payload = $payload;
        stream_wrapper_register('fragmented', FragmentedStream::class);
        $input = fopen('fragmented://input', 'r');
    } else {
        $input = fopen('php://memory', 'w+');
        fwrite($input, $payload);
        rewind($input);
    }

    $output = fopen('php://memory', 'w+');
    app()->instance(BlogMcpStreams::class, new BlogMcpStreams($input, $output));

    test()->artisan('blog:mcp')->assertExitCode(0);

    if ($fragmented) {
        stream_wrapper_unregister('fragmented');
    }

    rewind($output);
    $responses = [];
    foreach (array_filter(explode("\n", (string) stream_get_contents($output))) as $line) {
        $decoded = json_decode($line, true);
        $responses[$decoded['id']] = $decoded;
    }

    return $responses;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function blogToolCall(int $id, string $tool, array $arguments): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]];
}

function blogSessionStart(): array
{
    return [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ];
}

it('no longer serves the blog mcp over http', function (): void {
    $this->postJson('/mcp/blog', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertNotFound();
});

it('refuses to start without exactly one super administrator', function (int $count): void {
    SystemAdministrator::factory()->count($count)->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);

    $this->artisan('blog:mcp')->assertExitCode(1);
})->with([
    'none' => 0,
    'two' => 2,
]);

it('serves the blog tools over stdio as the super administrator', function (): void {
    SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator, 'email' => 'founder@relaticle.com']);
    User::factory()->create(['email' => 'founder@relaticle.com']);
    $category = Category::factory()->create();

    $responses = runBlogMcp([
        ...blogSessionStart(),
        blogToolCall(3, 'create-post-tool', ['title' => 'Over Stdio', 'content' => 'Body.', 'excerpt' => 'Short.', 'category_id' => $category->id, 'status' => 'draft']),
    ]);

    expect(collect($responses[2]['result']['tools'])->pluck('name'))->toContain('create-post-tool', 'delete-post-tool')
        ->and($responses[3]['result']['isError'])->toBeFalse()
        ->and(Post::where('title', 'Over Stdio')->exists())->toBeTrue();
});

it('checks the super administrator again on every request', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator, 'email' => 'founder@relaticle.com']);
    User::factory()->create(['email' => 'founder@relaticle.com']);
    $category = Category::factory()->create();
    $doomed = Post::factory()->create();
    Event::listen('eloquent.created: '.Post::class, fn () => $admin->update(['role' => SystemAdministratorRole::Administrator]));

    $responses = runBlogMcp([
        ...blogSessionStart(),
        blogToolCall(3, 'create-post-tool', ['title' => 'Last Post', 'content' => 'Body.', 'excerpt' => 'Short.', 'category_id' => $category->id]),
        blogToolCall(4, 'delete-post-tool', ['id' => $doomed->getKey()]),
    ]);

    expect($responses[3]['result']['isError'])->toBeFalse()
        ->and($responses[4]['result']['isError'])->toBeTrue()
        ->and($responses[4]['result']['content'][0]['text'])->toBe('This action is unauthorized.')
        ->and($doomed->refresh()->deleted_at)->toBeNull();
});

it('reads one whole message from an input that arrives in fragments', function (): void {
    Storage::fake(config('ink.uploads.disk', 'public'));
    SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator, 'email' => 'founder@relaticle.com']);
    $canvas = imagecreatetruecolor(240, 240);
    foreach (range(0, 239) as $y) {
        foreach (range(0, 239) as $x) {
            imagesetpixel($canvas, $x, $y, random_int(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($canvas, null, 0);
    $image = base64_encode((string) ob_get_clean());

    $responses = runBlogMcp([
        ...blogSessionStart(),
        blogToolCall(3, 'upload-image-tool', ['data' => $image, 'filename' => 'cover.png']),
    ], fragmented: true);

    expect(strlen($image))->toBeGreaterThan(200_000)
        ->and($responses[3]['result']['isError'])->toBeFalse()
        ->and(Storage::disk(config('ink.uploads.disk', 'public'))->allFiles())->toHaveCount(1);
});
