---
name: sluggable-development
description: "Use when making a field on an Eloquent model sluggable, generating the migration for a slug column, switching to self-healing URLs, or resolving a model from its slug in a route. Trigger when the request mentions slugs, permalinks, the spatie/laravel-sluggable package, the HasSlug or HasTranslatableSlug trait, the #[Sluggable] attribute, SlugOptions, findBySlug, self-healing URLs, or stale slug redirects in a Laravel project. Covers choosing between the attribute and the trait, generating unique slugs, configuring separator/length/language/scope/uniqueness, preventing slug overwrites, translatable slugs via spatie/laravel-translatable, route model binding through the slug column, self-healing route keys, customizing the 308 redirect through the SelfHealing facade, and overriding the default actions via config/sluggable.php. Do not use for one-off URL-safe strings, signed URLs, or Laravel's built-in Str::slug helper when the result is not stored on a model."
license: MIT
metadata:
  author: spatie
---

# Sluggable development

Use this skill when adding slug generation to an Eloquent model with `spatie/laravel-sluggable`. The package writes a slug into a configurable column whenever the model is created or updated, keeps slugs unique, and can turn the resulting route key into a self-healing URL that tolerates slug changes.

## Picking a configuration style

- **`#[Sluggable]` attribute**: place it on the class. A wildcard Eloquent event listener in the package's service provider picks it up. Use this for most models.
- **`HasSlug` trait + `getSlugOptions()`**: use when you need any of these features (callables for `generateSlugsFrom`, closures for `extraScope` or `skipGenerateWhen`, custom suffix generators, translatable slugs, `findBySlug()`, or self-healing URLs).

If both are present on a model, the trait wins and the attribute is ignored.

## Making a field sluggable with the attribute

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\Attributes\Sluggable;

#[Sluggable(from: 'title', to: 'slug')]
class Post extends Model
{
}
```

Creating a model now writes the slug into the configured column.

```php
$post = Post::create(['title' => 'Hello World']);
$post->slug; // "hello-world"
```

Supported attribute arguments:

| Argument | Default | Purpose |
| --- | --- | --- |
| `from` | `'name'` | Source column, or array of columns joined with the separator. |
| `to` | `'slug'` | Destination column. |
| `separator` | `'-'` | Separator passed to `Str::slug`. |
| `language` | `'en'` | Language map used by `Str::slug`. |
| `maxLength` | `250` | Truncates the slug before uniqueness resolution. |
| `unique` | `true` | Appends a numeric suffix on collisions. |
| `onCreate` | `true` | Generate when the model is created. |
| `onUpdate` | `true` | Regenerate when the model is updated. |
| `preventOverwrite` | `false` | Do not regenerate when the destination column is already filled. |
| `selfHealing` | `false` | Turn the route key into `slug-id`. Requires the `HasSlug` trait on the same class. |
| `selfHealingSeparator` | `'-'` | Separator placed between slug and identifier. |

## Making a field sluggable with the trait

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Post extends Model
{
    use HasSlug;

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug');
    }
}
```

Chainable options that are not expressible as attribute arguments:

```php
SlugOptions::create()
    ->generateSlugsFrom(fn (Post $post) => "{$post->author->name} {$post->title}")
    ->saveSlugsTo('slug')
    ->extraScope(fn ($query) => $query->where('tenant_id', $this->tenant_id))
    ->skipGenerateWhen(fn () => $this->state === 'draft')
    ->usingSuffixGenerator(fn (string $slug, int $iteration) => bin2hex(random_bytes(4)))
    ->startSlugSuffixFrom(2)
    ->useSuffixOnFirstOccurrence()
    ->allowDuplicateSlugs();
```

## Generating the migration

The model's table needs a column that matches the destination configured through `to` (attribute) or `saveSlugsTo()` (trait).

```bash
php artisan make:migration add_slug_to_posts_table
```

Then in the migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
            $table->unique('slug');
        });
    }
};
```

For a new table, include the slug column in the `create` call instead. Use `->nullable()` when backfilling existing rows so the migration does not fail on the first run, then drop the nullability in a follow-up once every row has a slug.

For translatable slugs the column must hold JSON: use `$table->json('slug')` instead of `string`.

## Using the slug in routes

Standard implicit route model binding works once you point the route parameter at the slug column.

```php
Route::get('/posts/{post:slug}', fn (Post $post) => $post);
```

Override `getRouteKeyName()` on the model to drop the explicit `:slug`:

```php
public function getRouteKeyName(): string
{
    return 'slug';
}
```

The route definition then needs no `:slug` hint.

```php
Route::get('/posts/{post}', fn (Post $post) => $post);
```

## Finding a model by slug

The trait exposes `findBySlug()`:

```php
$post = Post::findBySlug('my-post');
Post::findBySlug('my-post', ['id', 'slug', 'title']);
Post::findBySlug('my-post', ['*'], fn ($query) => $query->where('published', true));
```

`findBySlug()` is only available on models that use the trait. Models configured solely through the attribute have to query the slug column directly.

## Self-healing URLs

A self-healing URL combines the slug with the primary key (`hello-world-5`). The slug portion can change freely without breaking existing links: stale slugs trigger a `308` redirect to the canonical URL, missing identifiers return `404`. The feature requires the trait because it overrides `getRouteKey()` and `resolveRouteBinding()`.

```php
public function getSlugOptions(): SlugOptions
{
    return SlugOptions::create()
        ->generateSlugsFrom('title')
        ->saveSlugsTo('slug')
        ->selfHealing();
}
```

The route key now includes the primary key, and stale slugs redirect.

```php
$post = Post::create(['title' => 'Hello World']);
$post->getRouteKey(); // "hello-world-5"

// GET /posts/hello-world-5   → 200 OK
// GET /posts/outdated-slug-5 → 308 redirect to /posts/hello-world-5
// GET /posts/hello-world-99  → 404 when id 99 does not exist
```

Setting `selfHealing: true` on the attribute without the trait throws `SelfHealingRequiresTrait` at resolve time.

Customize the separator when slugs can end with numbers preceded by a hyphen:

```php
->selfHealing(separator: '--'); // route key: "hello-world--5"
```

### Customizing the redirect

Register a closure through the `SelfHealing` facade in a service provider's `boot()`:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\Sluggable\Facades\SelfHealing;

SelfHealing::onStaleSelfHealingUrl(function (Model $model, string $staleRouteKey, Request $request) {
    return redirect()->route('posts.show', $model, status: 302);
});
```

The default behavior is a `308` redirect to the canonical URL.

## Translatable slugs

For per-locale slugs, combine `HasTranslatableSlug` with `spatie/laravel-translatable` and store the slug column as JSON.

```php
use Spatie\Sluggable\HasTranslatableSlug;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

class Article extends Model
{
    use HasTranslations;
    use HasTranslatableSlug;

    public $translatable = ['title', 'slug'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug');
    }
}
```

`HasTranslatableSlug::findBySlug()` searches both the current locale and the application fallback locale.

## Overriding the default actions

Three action classes do the low-level work. Swap any of them by publishing the config and pointing the relevant key at your own class (which must extend the default).

```bash
php artisan vendor:publish --tag=sluggable-config
```

Then point the relevant key in `config/sluggable.php` at your own class.

```php
return [
    'actions' => [
        'generate_slug' => App\Sluggable\MyGenerateSlugAction::class,
        'build_self_healing_route_key' => Spatie\Sluggable\Actions\BuildSelfHealingRouteKeyAction::class,
        'extract_identifier_from_self_healing_route_key' => Spatie\Sluggable\Actions\ExtractIdentifierFromSelfHealingRouteKeyAction::class,
    ],
];
```

## Verification

1. Create a model and assert the slug column is populated: `expect($post->slug)->toBe('expected-slug');`.
2. Update the source column and confirm the slug either updates (default) or stays (with `onUpdate: false` / `doNotGenerateSlugsOnUpdate()`).
3. Hit the route with a stale slug and assert a `308` redirect to the canonical URL when using self-healing.
4. Run `php artisan migrate` and confirm the slug column exists with the correct type (`string` or `json` for translatable).

## Common pitfalls

- Setting `selfHealing: true` on the attribute without `use HasSlug;` throws `SelfHealingRequiresTrait`. Add the trait.
- When adding the slug column to a populated table, make it `->nullable()` first and backfill before enforcing a unique index.
- Translatable slugs require the slug column to be JSON, not `string`.
- The attribute cannot carry closures. Features that need them (callables for sources, dynamic scopes, custom suffix generators, skip conditions) require the trait.
- Implicit route binding falls back to the primary key. Point the parameter at the slug column with `{post:slug}` or override `getRouteKeyName()`.
