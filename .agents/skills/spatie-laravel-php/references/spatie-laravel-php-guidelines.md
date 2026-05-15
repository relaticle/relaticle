# Spatie Laravel & PHP Guidelines (Reference)

## Table of Contents

- [Core Laravel Principle](#core-laravel-principle)
- [PHP Standards](#php-standards)
- [Class Structure](#class-structure)
- [Type Declarations and Docblocks](#type-declarations-and-docblocks)
- [Control Flow](#control-flow)
- [Laravel Conventions](#laravel-conventions)
- [Strings and Formatting](#strings-and-formatting)
- [Enums](#enums)
- [Comments](#comments)
- [Whitespace](#whitespace)
- [Validation](#validation)
- [Blade Templates](#blade-templates)
- [Authorization](#authorization)
- [Translations](#translations)
- [API Routing](#api-routing)
- [Testing](#testing)
- [Quick Reference](#quick-reference)

## Core Laravel Principle

**Follow Laravel conventions first.** If Laravel has a documented way to do something, use it. Only deviate when you have a clear justification.

## PHP Standards

- Follow PSR-1, PSR-2, and PSR-12
- Use camelCase for non-public-facing strings
- Use short nullable notation: `?string` not `string|null`
- Always specify `void` return types when methods return nothing
- Don't use `final` or `readonly` by default

## Class Structure

- Use typed properties, not docblocks
- Use constructor property promotion when all properties can be promoted
- Use one trait per line

```php
class MyClass {
    use TraitA;
    use TraitB;

    public function __construct(
        protected string $firstArgument,
        protected string $secondArgument,
    ) {}
}
```

## Type Declarations and Docblocks

- Use typed properties over docblocks
- Specify return types including `void`
- Use short nullable syntax: `?Type` not `Type|null`
- Document iterables with generics:

```php
/** @return Collection<int, User> */
public function getUsers(): Collection
```

### Docblock Rules

- Don't use docblocks for fully type-hinted methods (unless description needed)
- Always import classnames in docblocks; never use fully qualified names:

```php
use Spatie\Url\Url;
/** @return Url */
```

- Use one-line docblocks when possible: `/** @var string */`
- Most common type should be first in multi-type docblocks:

```php
/** @var Collection|SomeWeirdVendor\Collection */
```

- If one parameter needs docblock, add docblocks for all parameters
- For iterables, always specify key and value types:

```php
/**
 * @param array<int, MyObject> $myArray
 * @param int $typedArgument
 */
function someFunction(array $myArray, int $typedArgument) {}
```

- Use array shape notation for fixed keys, put each key on its own line:

```php
/** @return array{
   first: SomeClass,
   second: SomeClass,
} */
```

## Control Flow

- Happy path last: handle error conditions first, success case last
- Avoid `else`: use early returns instead of nested conditions
- Separate conditions: prefer multiple if statements over compound conditions
- Always use curly brackets even for single statements
- Ternary operators: each part on its own line unless very short

```php
// Happy path last
if (! $user) {
    return null;
}

if (! $user->isActive()) {
    return null;
}

// Process active user...

// Short ternary
$name = $isFoo ? 'foo' : 'bar';

// Multi-line ternary
$result = $object instanceof Model
    ? $object->name
    : 'A default value';

// Ternary instead of else
$condition
    ? $this->doSomething()
    : $this->doSomethingElse();
```

## Laravel Conventions

### Routes

- URLs: kebab-case (`/open-source`)
- Route names: camelCase (`->name('openSource')`)
- Parameters: camelCase (`{userId}`)
- Use tuple notation: `[Controller::class, 'method']`
- HTTP verb first for readability
- No leading slash unless URL is empty

```php
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('open-source', [OpenSourceController::class, 'index'])->name('openSource');
Route::post('users', [UsersController::class, 'store'])->name('users.store');
```

### Controllers

- Use plural resource names (`PostsController`)
- Stick to CRUD methods (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`)
- Extract new controllers for non-CRUD actions

```php
// Instead of PostsController@favorite, extract:
class FavoritePostsController
{
    public function store(Post $post)
    {
        request()->user()->favorites()->attach($post);

        return response(null, 200);
    }

    public function destroy(Post $post)
    {
        request()->user()->favorites()->detach($post);

        return response(null, 200);
    }
}
```

### Views

- View files use camelCase: `openSource.blade.php`

### Configuration

- Files: kebab-case (`pdf-generator.php`)
- Keys: snake_case (`chrome_path`)
- Add service configs to `config/services.php`, don't create new files
- Use `config()` helper, avoid `env()` outside config files

```php
// config/services.php
return [
    'github' => [
        'username' => env('GITHUB_USERNAME'),
        'token' => env('GITHUB_TOKEN'),
    ],
];
```

### Artisan Commands

- Names: kebab-case (`delete-old-records`)
- Always provide feedback (`$this->comment('All ok!')`)
- Show progress for loops, summary at end
- Put output before processing an item (easier debugging):

```php
$items->each(function (Item $item) {
    $this->info("Processing item id `{$item->id}`...");
    $this->processItem($item);
});

$this->comment("Processed {$items->count()} items.");
```

## Strings and Formatting

- Use string interpolation over concatenation:

```php
$greeting = "Hi, I am {$name}.";
```

## Enums

- Use PascalCase for enum values:

```php
enum Suit {
    case Clubs;
    case Diamonds;
    case Hearts;
    case Spades;
}
```

- Class constants also use PascalCase:

```php
class Session {
    public const SessionTokenHeader = 'X-Session-Token';
}
```

## Comments

- Avoid comments; write expressive code instead
- When needed, use proper formatting:

```php
// Single line with space after //

/*
 * Multi-line blocks start with single *
 */
```

- Refactor comments into descriptive function names

## Whitespace

- Add blank lines between statements for readability
- Exception: sequences of equivalent single-line operations
- No extra empty lines between `{}` brackets
- Let code breathe; avoid cramped formatting

```php
public function getPage($url)
{
    $page = $this->pages()->where('slug', $url)->first();

    if (! $page) {
        return null;
    }

    if ($page['private'] && ! Auth::check()) {
        return null;
    }

    return $page;
}
```

## Validation

- Use array notation for multiple rules (easier for custom rule classes):

```php
public function rules()
{
    return [
        'email' => ['required', 'email'],
    ];
}
```

- Custom validation rules use snake_case:

```php
Validator::extend('organisation_type', function ($attribute, $value) {
    return OrganisationType::isValid($value);
});
```

## Blade Templates

- Indent with 4 spaces
- No spaces after control structures:

```blade
@if($condition)
    Something
@endif
```

## Authorization

- Policies use camelCase: `Gate::define('editPost', ...)`
- Use CRUD words, but `view` instead of `show`

```php
@can('editPost', $post)
    <a href="{{ route('posts.edit', $post) }}">Edit</a>
@endcan
```

## Translations

- Use `__()` function over `@lang`:

```blade
<h2>{{ __('newsletter.form.title') }}</h2>
```

## API Routing

- Use plural resource names: `/errors`
- Use kebab-case: `/error-occurrences`
- Limit deep nesting for simplicity:

```
/error-occurrences/1
/errors/1/occurrences
```

## Testing

- Keep test classes in same file when possible
- Use descriptive test method names
- Follow the arrange-act-assert pattern

## Quick Reference

### Naming Conventions

- Classes: PascalCase (`UserController`, `OrderStatus`)
- Methods and variables: camelCase (`getUserName`, `$firstName`)
- Routes: kebab-case (`/open-source`, `/user-profile`)
- Config files: kebab-case (`pdf-generator.php`)
- Config keys: snake_case (`chrome_path`)
- Artisan commands: kebab-case (`php artisan delete-old-records`)

### File Structure

- Controllers: plural resource name + `Controller` (`PostsController`)
- Views: camelCase (`openSource.blade.php`)
- Jobs: action-based (`CreateUser`, `SendEmailNotification`)
- Events: tense-based (`UserRegistering`, `UserRegistered`)
- Listeners: action + `Listener` suffix (`SendInvitationMailListener`)
- Commands: action + `Command` suffix (`PublishScheduledPostsCommand`)
- Mailables: purpose + `Mail` suffix (`AccountActivatedMail`)
- Resources and Transformers: plural + `Resource` or `Transformer` (`UsersResource`)
- Enums: descriptive name, no prefix (`OrderStatus`, `BookingType`)

### Migrations

- Do not write down methods in migrations, only up methods

### Code Quality Reminders

- Use typed properties over docblocks
- Prefer early returns over nested if/else
- Use constructor property promotion when all properties can be promoted
- Avoid `else` statements when possible
- Use string interpolation over concatenation
- Always use curly braces for control structures

---

Source: https://spatie.be/guidelines