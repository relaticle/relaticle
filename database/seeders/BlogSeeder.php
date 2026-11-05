<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Personas\PersonaCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Relaticle\Ink\Enums\PostStatus;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

final class BlogSeeder extends Seeder
{
    private const array CATEGORIES = [
        'engineering' => 'Engineering',
        'self-hosting' => 'Self-hosting',
        'ai-and-mcp' => 'AI and MCP',
        'product' => 'Product',
    ];

    private const array COVERS = [
        'ai' => 'open-graph-ai.jpg',
        'self-hosted' => 'open-graph-self-hosted.jpg',
        'default' => 'open-graph.jpg',
    ];

    /**
     * @var list<array{
     *     slug: string,
     *     title: string,
     *     excerpt: string,
     *     category: string,
     *     tags: list<string>,
     *     cover: ?string,
     *     days_ago: int,
     *     status: string
     * }>
     */
    private const array POSTS = [
        ['slug' => 'self-hosting-relaticle-with-docker-compose', 'title' => 'Self-hosting Relaticle with Docker Compose in ten minutes', 'excerpt' => 'One compose file, one env file, and a CRM on your own server. Here is every step, including backups.', 'category' => 'self-hosting', 'tags' => ['Docker', 'Postgres'], 'cover' => 'self-hosted', 'days_ago' => 3, 'status' => 'published'],
        ['slug' => 'connecting-claude-to-your-crm-over-mcp', 'title' => 'Connecting Claude to your CRM over MCP', 'excerpt' => 'The MCP server lets Claude and ChatGPT read and update companies, people and opportunities with the same permissions you have.', 'category' => 'ai-and-mcp', 'tags' => ['MCP', 'Laravel'], 'cover' => 'ai', 'days_ago' => 9, 'status' => 'published'],
        ['slug' => 'why-every-write-goes-through-an-action-class', 'title' => 'Why every write in Relaticle goes through an action class', 'excerpt' => 'Controllers, MCP tools, Livewire components and chat tools all call the same action. That one rule removed a class of drift bugs.', 'category' => 'engineering', 'tags' => ['Laravel', 'Open source'], 'cover' => null, 'days_ago' => 14, 'status' => 'published'],
        ['slug' => 'meet-rela-the-built-in-ai-assistant', 'title' => 'Meet Rela, the built-in AI assistant', 'excerpt' => 'Rela proposes every change as a card you approve. Nothing touches your records until you click.', 'category' => 'product', 'tags' => ['AI'], 'cover' => 'ai', 'days_ago' => 21, 'status' => 'published'],
        ['slug' => 'workspace-scope-that-fails-closed', 'title' => 'A workspace scope that fails closed', 'excerpt' => 'With no workspace bound, our global scope returns no rows instead of every row. Here is how we bind it in requests, jobs and commands.', 'category' => 'engineering', 'tags' => ['Laravel', 'Postgres'], 'cover' => 'default', 'days_ago' => 28, 'status' => 'published'],
        ['slug' => 'custom-fields-without-migrations', 'title' => 'Custom fields without a migration per field', 'excerpt' => 'Every company, person and opportunity accepts fields your team defines at runtime. This is the storage model behind it.', 'category' => 'engineering', 'tags' => ['Filament', 'Postgres'], 'cover' => null, 'days_ago' => 35, 'status' => 'published'],
        ['slug' => 'upgrading-a-self-hosted-install', 'title' => 'Upgrading a self-hosted install safely', 'excerpt' => 'Pull, restart, and let migrations run. What we promise about upgrades, and what we test before every release.', 'category' => 'self-hosting', 'tags' => ['Docker'], 'cover' => 'self-hosted', 'days_ago' => 42, 'status' => 'published'],
        ['slug' => 'batch-proposals-in-one-chat-turn', 'title' => 'Batch proposals: many writes, one approval', 'excerpt' => 'Ask Rela to add five people to a company and log a note, and you approve one plan instead of six cards.', 'category' => 'ai-and-mcp', 'tags' => ['AI', 'Livewire'], 'cover' => null, 'days_ago' => 49, 'status' => 'published'],
        ['slug' => 'testing-trophy-in-a-laravel-monolith', 'title' => 'The testing trophy in a Laravel modular monolith', 'excerpt' => 'No unit suite, a thin browser layer, and workflow tests through real entry points. Why we shaped it this way.', 'category' => 'engineering', 'tags' => ['Laravel', 'Testing'], 'cover' => 'default', 'days_ago' => 56, 'status' => 'published'],
        ['slug' => 'importing-a-csv-of-ten-thousand-companies', 'title' => 'Importing a CSV of ten thousand companies', 'excerpt' => 'Column matching, duplicate detection and a preview before anything is written. A tour of the import wizard.', 'category' => 'product', 'tags' => ['Livewire'], 'cover' => null, 'days_ago' => 70, 'status' => 'published'],
        ['slug' => 'postgres-only-on-purpose', 'title' => 'Postgres only, on purpose', 'excerpt' => 'Supporting one database let us use JSONB, partial indexes and real transactions in migrations without a compatibility layer.', 'category' => 'engineering', 'tags' => ['Postgres'], 'cover' => null, 'days_ago' => 84, 'status' => 'published'],
        ['slug' => 'agpl-and-what-it-means-for-your-install', 'title' => 'AGPL and what it means for your install', 'excerpt' => 'You can run, change and host Relaticle for your team. The licence asks one thing in return when you offer it to others.', 'category' => 'self-hosting', 'tags' => ['Open source'], 'cover' => 'self-hosted', 'days_ago' => 98, 'status' => 'published'],
        ['slug' => 'pipeline-boards-that-keep-up-with-sales', 'title' => 'Pipeline boards that keep up with your sales team', 'excerpt' => 'Drag an opportunity across stages and every view updates. The board is built on Filament and a sortable list.', 'category' => 'product', 'tags' => ['Filament', 'Livewire'], 'cover' => 'default', 'days_ago' => 120, 'status' => 'published'],
        ['slug' => 'when-customfieldvalidationservice-names-break-layouts', 'title' => 'What happens when CustomFieldValidationService::assertTenantOwnershipForEveryField() appears in a headline', 'excerpt' => 'A long unbreakable identifier in a title should wrap, not paint over the table of contents.', 'category' => 'engineering', 'tags' => ['Testing'], 'cover' => null, 'days_ago' => 150, 'status' => 'published'],
        ['slug' => 'draft-email-sync-deep-dive', 'title' => 'Draft: inside Gmail and Microsoft mailbox sync', 'excerpt' => 'This draft must never appear on the blog index, a category page or the feed.', 'category' => 'engineering', 'tags' => ['Laravel'], 'cover' => null, 'days_ago' => 0, 'status' => 'draft'],
        ['slug' => 'scheduled-rela-release-notes', 'title' => 'Scheduled: what is new in Rela this month', 'excerpt' => 'This post is scheduled for next week and must stay hidden until then.', 'category' => 'product', 'tags' => ['AI'], 'cover' => 'ai', 'days_ago' => -7, 'status' => 'scheduled'],
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->info('Skipping blog seeding as the environment is not local.');

            return;
        }

        $authorId = $this->author()->getKey();
        $categories = $this->categories();
        $covers = $this->covers();

        foreach (self::POSTS as $row) {
            $this->seedPost($row, $authorId, $categories, $covers);
        }

        $this->command?->info('Seeded '.count(self::POSTS).' blog posts at '.url(config('ink.prefix')).'.');
    }

    private function author(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'author@'.PersonaCatalog::DOMAIN],
            ['name' => 'Relaticle Engineering', 'password' => bcrypt(PersonaCatalog::PASSWORD)],
        );
    }

    /**
     * @return array<string, int>
     */
    private function categories(): array
    {
        $ids = [];

        foreach (self::CATEGORIES as $slug => $name) {
            $ids[$slug] = Category::query()->updateOrCreate(['slug' => $slug], ['name' => $name])->getKey();
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function covers(): array
    {
        $disk = Storage::disk(config('ink.uploads.disk', 'public'));
        $paths = [];

        foreach (self::COVERS as $key => $file) {
            $path = config('ink.uploads.directory', 'ink')."/seed-{$file}";

            if (! $disk->exists($path)) {
                $disk->put($path, File::get(public_path("images/{$file}")));
            }

            $paths[$key] = $path;
        }

        return $paths;
    }

    /**
     * @param  array{slug: string, title: string, excerpt: string, category: string, tags: list<string>, cover: ?string, days_ago: int, status: string}  $row
     * @param  array<string, int>  $categories
     * @param  array<string, string>  $covers
     */
    private function seedPost(array $row, int|string $authorId, array $categories, array $covers): void
    {
        $post = Post::withTrashed()->updateOrCreate(['slug' => $row['slug']], [
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'content' => $this->body($row['excerpt']),
            'featured_image' => $row['cover'] === null ? null : $covers[$row['cover']],
            'category_id' => $categories[$row['category']],
            'author_id' => $authorId,
            'status' => $row['status'] === 'draft' ? PostStatus::Draft : PostStatus::Published,
            'published_at' => $row['status'] === 'draft' ? null : now()->subDays($row['days_ago']),
        ]);

        if ($post->trashed()) {
            $post->restore();
        }

        $post->tags()->sync(array_map(
            fn (string $name): int|string => Tag::query()->firstOrCreate(['name' => $name])->getKey(),
            $row['tags'],
        ));
    }

    private function body(string $lede): string
    {
        return <<<MARKDOWN
            {$lede}

            ## The problem we started with

            Three surfaces wrote the same record: the REST API, the MCP server and Rela. Each one validated its input on its own, and over a few months they stopped agreeing.

            - The API accepted an owner the MCP server rejected.
            - Rela saved a custom field the API silently dropped.
            - Nobody noticed until a customer compared two exports.

            ## What we tried first

            We added a test per surface. It caught regressions on the surface it covered and missed the drift between them.

            > A rule that cannot name the artifact failing when you break it is decoration.

            ### Why the tests were not enough

            Each test asserted what its own surface accepted. None of them asserted that every surface accepted the same thing.

            ## How it works now

            Every write goes through one action class, and every surface calls it:

            ```php
            final readonly class CreateOpportunity
            {
                public function execute(User \$user, array \$data): Opportunity
                {
                    abort_unless(\$user->can('create', Opportunity::class), 403);

                    return Opportunity::query()->create(\$data);
                }
            }
            ```

            A parity test then lists the writable fields of each surface and fails when one of them falls behind.

            ## What we measured

            | Axis | Owner class | Drift found |
            | --- | --- | --- |
            | Filter operators | Yes | 0 |
            | Writable fields | No | 2 |
            | Relation includes | No | 1 |

            ## What comes next

            We are moving the remaining per-surface copies behind owner classes. Read the [architecture notes](https://github.com/relaticle/relaticle) for the full list.
            MARKDOWN;
    }
}
