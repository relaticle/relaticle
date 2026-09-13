<?php

declare(strict_types=1);

use App\Providers\CrmServiceProvider;

/**
 * @param  array<array-key, mixed>  $translations
 * @return array<string, mixed>
 */
function flattenTranslations(array $translations, string $prefix = ''): array
{
    $flat = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $flat += is_array($value) ? flattenTranslations($value, $path) : [$path => $value];
    }

    return $flat;
}

/**
 * Every English file we must mirror in Spanish: the app's own (lang/en) plus the
 * packages that ship without Spanish and are overridden from lang/vendor.
 *
 * @return array<string, array{string, string}>
 */
function spanishTranslationPairs(): array
{
    $pairs = [];

    foreach (File::allFiles(lang_path('en')) as $file) {
        $relative = $file->getRelativePathname();
        $pairs[$relative] = [$file->getPathname(), lang_path("es/{$relative}")];
    }

    $pairs['vendor/custom-fields'] = [
        base_path('vendor/relaticle/custom-fields/resources/lang/en/custom-fields.php'),
        lang_path('vendor/custom-fields/es/custom-fields.php'),
    ];
    $pairs['vendor/activity-log'] = [
        base_path('vendor/relaticle/activity-log/resources/lang/en/messages.php'),
        lang_path('vendor/activity-log/es/messages.php'),
    ];

    return $pairs;
}

it('has a Spanish translation for every English key', function (): void {
    $missing = [];

    foreach (spanishTranslationPairs() as $name => [$english, $spanish]) {
        if (! file_exists($spanish)) {
            $missing[] = "{$name} (file)";

            continue;
        }

        $keys = array_keys(array_diff_key(
            flattenTranslations(require $english),
            flattenTranslations(require $spanish),
        ));

        array_push($missing, ...array_map(fn (string $key): string => "{$name}: {$key}", $keys));
    }

    expect($missing)->toBe([], 'Upstream added texts without a Spanish translation. Translate them and run `php artisan locale:diff es --update-snapshot`.');
});

it('never mentions Relaticle in the Spanish texts', function (): void {
    $mentions = collect(spanishTranslationPairs())
        ->map(fn (array $pair): string => $pair[1])
        ->filter(fn (string $spanish): bool => file_exists($spanish))
        ->flatMap(fn (string $spanish): array => array_filter(
            flattenTranslations(require $spanish),
            fn (mixed $value): bool => is_string($value) && str_contains($value, 'Relaticle'),
        ))
        ->all();

    expect($mentions)->toBe([]);
});

it('translates the app, the overridden packages and the JSON strings', function (): void {
    app()->setLocale('es');

    expect(__('auth.login.welcome'))->toBe('Bienvenido a Crabdev CRM')
        ->and(__('filament/resources/person.navigation_label'))->toBe('Contactos')
        ->and(__('custom-fields::custom-fields.heading.title'))->toBe('Campos personalizados')
        ->and(__('activity-log::messages.title'))->toBe('Historial de actividad')
        ->and(__('Sign out'))->toBe('Cerrar sesión')
        ->and(__('crm.signup_closed'))->toStartWith('El acceso es solo por invitación');
});

it('shows the login page in Spanish', function (): void {
    app()->getProvider(CrmServiceProvider::class)->applyBranding();
    app()->setLocale('es');

    $this->get(url()->getAppUrl('login'))
        ->assertOk()
        ->assertSee('Bienvenido a Crabdev CRM');
});
