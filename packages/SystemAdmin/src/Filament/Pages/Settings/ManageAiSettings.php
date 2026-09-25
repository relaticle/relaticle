<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages\Settings;

use App\Enums\Plan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Laravel\Ai\Enums\Lab;
use Relaticle\Chat\Enums\WriteGuard;
use Relaticle\Chat\Services\ModelProbe;
use Relaticle\Chat\Services\ProviderModelCatalog;
use Relaticle\Chat\Settings\ChatSettings;
use Relaticle\Chat\Support\CatalogEntry;
use Relaticle\Chat\Support\Measurement;
use UnitEnum;

/**
 * The model catalog, editable without a deploy.
 *
 * A vendor retiring a model or shipping a new one is not a code change, but it
 * used to need one. The safety this buys back is ModelProbe: nothing can be saved
 * until the provider has accepted a real request built the way a real turn builds
 * it. That gate is the whole point of the page. See RELATICLE-CRM-6D, where a
 * request the app happily built was rejected by the provider on every single turn.
 *
 * @property-read Schema $form
 */
final class ManageAiSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Model Catalog';

    protected static ?string $title = 'AI Model Catalog';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'ai-models';

    protected string $view = 'system-admin::filament.pages.manage-ai-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = resolve(ChatSettings::class);

        $this->form->fill([
            'models' => $settings->models,
            'anthropic_effort' => $settings->anthropic_effort,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Models')
                        ->description('What the model picker offers. Drag to set the Auto order. Capabilities are measured by a real request to the provider, never typed.')
                        ->schema([
                            Repeater::make('models')
                                ->hiddenLabel()
                                ->reorderableWithDragAndDrop()
                                // A table, not stacked cards: the catalog is read as a
                                // comparison (which provider serves it, which one Auto
                                // reaches first, which ones are on) and rows make that
                                // legible in a way one card per model does not.
                                ->table([
                                    TableColumn::make('Label')->markAsRequired(),
                                    TableColumn::make('Provider')->markAsRequired(),
                                    TableColumn::make('Model')->markAsRequired(),
                                    TableColumn::make('Auto'),
                                    TableColumn::make('On'),
                                    TableColumn::make('Verified'),
                                ])
                                ->schema([
                                    // The one string a user ever reads. It is filled from the
                                    // provider's own listing when the model is chosen, so this
                                    // is an override rather than a thing to invent.
                                    TextInput::make('label')
                                        ->required()
                                        ->extraAttributes(['style' => 'min-width: 9rem']),
                                    Select::make('provider')
                                        ->options(fn (Get $get): array => $this->providerOptions($get('provider')))
                                        ->required()
                                        ->live(),
                                    // Suggests from the provider's live list, but keeps whatever is
                                    // already stored: a Select that dropped the current value would
                                    // invalidate every entry the moment a provider is unreachable
                                    // or its key is absent on this install.
                                    Select::make('model')
                                        ->options(fn (Get $get): array => $this->modelOptions($get('provider'), $get('model')))
                                        ->searchable()
                                        ->required()
                                        // The tag is the entry's identity, and prices and
                                        // multipliers are keyed by it across the whole
                                        // catalog, disabled rows included. Two rows naming
                                        // one tag would silently let the later row price
                                        // the earlier one's transactions.
                                        ->distinct()
                                        // Required fields have nothing to clear, and the clear
                                        // button overflows the column into Auto.
                                        ->selectablePlaceholder(false)
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                            if (is_string($state) && $state !== '' && blank($get('label'))) {
                                                $set('label', $this->listedLabel($get('provider'), $state));
                                            }
                                        })
                                        ->helperText(fn (Get $get): ?string => $this->unlistedNote($get('provider'), $get('model')))
                                        // An inline style, not a Tailwind class: this file is
                                        // outside the sysadmin theme's JIT scan paths, so a
                                        // utility class here compiles to nothing. Without a floor
                                        // the table wraps model ids onto three lines.
                                        ->extraAttributes(['style' => 'min-width: 11rem']),
                                    // Hidden, not absent: a table repeater renders Hidden
                                    // components without giving them a column, and a field
                                    // missing from the schema is dropped from getState(),
                                    // so leaving these out would silently discard every
                                    // plan and price on the next save.
                                    //
                                    // A row whose modal was never opened must not hand an
                                    // expensive model to every free workspace.
                                    Hidden::make('min_plan')->default(Plan::Pro->value),
                                    // Without a default a row saved before its modal was
                                    // opened charges nothing: `(float) null` is 0.0.
                                    Hidden::make('credit_multiplier')->default(1.0),
                                    Hidden::make('input_per_mtok'),
                                    Hidden::make('output_per_mtok'),
                                    Toggle::make('auto')->inline(false),
                                    Toggle::make('enabled')->inline(false),
                                    Icon::make(fn (Get $get): Heroicon => $this->capabilityBadge($get)['icon'])
                                        ->color(fn (Get $get): string => $this->capabilityBadge($get)['color'])
                                        ->tooltip(fn (Get $get): string => $this->capabilityBadge($get)['tooltip']),
                                ])
                                // How a model is presented and priced is set once and then
                                // rarely touched, while the rest of the row is what an
                                // operator scans. It lives behind a gear so the table stays
                                // readable.
                                ->extraItemActions([
                                    Action::make('configure')
                                        ->icon('heroicon-o-cog-6-tooth')
                                        ->color('gray')
                                        ->modalHeading('Model configuration')
                                        ->modalDescription('Who may reach this model and what a turn on it costs.')
                                        ->modalWidth(Width::Medium)
                                        ->schema([
                                            Select::make('min_plan')
                                                ->label('Minimum plan')
                                                ->options(Plan::class)
                                                ->required()
                                                ->selectablePlaceholder(false),
                                            TextInput::make('credit_multiplier')
                                                ->label('Credit multiplier')
                                                ->numeric()
                                                ->required()
                                                ->minValue(0)
                                                ->helperText('Charged to the workspace per turn, before tool-call bonuses.'),
                                            TextInput::make('input_per_mtok')
                                                ->label('Input $ / Mtok')
                                                ->numeric()
                                                ->minValue(0)
                                                ->helperText('Vendor list price. Feeds the sysadmin spend widget only.'),
                                            TextInput::make('output_per_mtok')
                                                ->label('Output $ / Mtok')
                                                ->numeric()
                                                ->minValue(0),
                                        ])
                                        ->fillForm(fn (array $arguments, Repeater $component): array => collect($component->getItemState($arguments['item']))
                                            ->only(['min_plan', 'credit_multiplier', 'input_per_mtok', 'output_per_mtok'])
                                            ->all())
                                        ->action(function (array $arguments, array $data, Repeater $component): void {
                                            $state = $component->getState();
                                            $state[$arguments['item']] = [...$state[$arguments['item']], ...$data];

                                            $component->state($state);
                                        }),
                                ])
                                ->addActionLabel('Add a model'),
                        ]),
                    Section::make('Tuning')
                        ->description('Anthropic removed temperature and top_p on Opus 4.7 and every model since, so effort is the only quality-versus-cost dial those models expose.')
                        ->schema([
                            Select::make('anthropic_effort')
                                ->label('Anthropic reasoning effort')
                                ->options([
                                    'low' => 'Low',
                                    'medium' => 'Medium',
                                    'high' => 'High (provider default)',
                                    'xhigh' => 'Extra high',
                                    'max' => 'Max',
                                ])
                                ->required(),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Verify and save')->submit('save')->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshModelLists')
                ->label('Refresh model lists')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $catalog = resolve(ProviderModelCatalog::class);

                    foreach (array_keys($this->providerOptions()) as $provider) {
                        $catalog->forget($provider);
                    }

                    Notification::make()->title('Model lists refreshed')->success()->send();
                }),
        ];
    }

    /**
     * Every cloud model in the submitted catalog has to be accepted by its own
     * provider before any of it is written. A pass is cached by ModelProbe, so an
     * unchanged row costs nothing and only a genuinely new pairing spends a call.
     */
    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        /** @var list<array<string, mixed>> $submitted */
        $submitted = $data['models'] ?? [];

        [$models, $failure] = $this->verified($this->parseModels($submitted));

        if ($failure !== null) {
            Notification::make()
                ->title('The provider rejected this model')
                ->body($failure)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $settings = resolve(ChatSettings::class);
        $before = $settings->toConfig();
        $settings->models = $models;
        $settings->anthropic_effort = (string) ($data['anthropic_effort'] ?? 'high');
        $settings->save();

        config($settings->toConfig());

        $this->recordActivity($before, $settings);

        // Chat runs entirely in queued jobs, and a worker holds the config it
        // booted with. Without this the save looks applied in the panel and
        // changes nothing about what users actually get until the next deploy.
        Artisan::call('queue:restart');

        Notification::make()
            ->title('Saved')
            ->body('Workers are restarting; the next chat turn uses the new catalog.')
            ->success()
            ->send();
    }

    /**
     * Who changed which model dial, and to what.
     *
     * This is the one control in the panel that changes what every workspace's
     * assistant does, and it takes effect on the next turn rather than at the next
     * deploy, so git history will not answer "what changed at 09:00 on Tuesday".
     * Only the keys that actually moved are recorded; a save that touched nothing
     * writes nothing.
     *
     * @param  array<string, mixed>  $before
     */
    private function recordActivity(array $before, ChatSettings $settings): void
    {
        $after = $settings->toConfig();

        // Numbers are compared as floats because a whole float survives the settings
        // row's JSON round trip as an int: `1.0` going out and `1` coming back describe
        // the same catalog. Without this every no-op save logs a change, and the audit
        // trail is noise exactly when someone needs to read it.
        $changed = collect($after)
            ->reject(fn (mixed $value, string $key): bool => self::comparable($value) === self::comparable($before[$key] ?? null))
            ->keys()
            ->all();

        if ($changed === []) {
            return;
        }

        activity((string) config('activitylog.default_log_name'))
            ->causedBy(auth('sysadmin')->user())
            ->withProperties([
                'changed' => $changed,
                'old' => collect($before)->only($changed)->all(),
                'attributes' => collect($after)->only($changed)->all(),
            ])
            ->event('chat_settings_updated')
            ->log('chat_settings_updated');
    }

    private static function comparable(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::comparable(...), $value);
        }

        return is_int($value) || is_float($value) ? (float) $value : $value;
    }

    /**
     * Probes every (provider, model) pairing nothing has measured yet and writes
     * back what the provider reported.
     *
     * Capabilities are never taken from the form, they are taken from the stored
     * catalog or from a probe. The form is redrawn from whatever the operator last
     * saw and a re-added row carries none at all, so trusting it would store an
     * enabled model as tool-incapable and drop it out of every picker, the silent
     * failure this gate exists to stop (Sentry RELATICLE-CRM-6D).
     *
     * A pairing already carrying a measurement is skipped. Re-verifying the whole
     * catalog on every save would mean one provider with a stale key blocks edits to
     * unrelated entries, including the effort dial. What is already measured is the
     * status quo whether or not it still works; this gate stops an UNMEASURED model
     * being served, it is not a continuous audit. Switching a retired row back on is
     * therefore a probe, because nothing ever measured it.
     *
     * @param  list<CatalogEntry>  $entries
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function verified(array $entries): array
    {
        $probe = resolve(ModelProbe::class);
        $stored = $this->storedByPairing(resolve(ChatSettings::class)->models);
        $verified = [];

        foreach ($entries as $entry) {
            $pairing = $entry->pairing();

            $entry = $entry->withMeasurement($pairing === null ? null : ($stored[$pairing] ?? null));

            if (! $entry->needsProbe() || $pairing === null) {
                $verified[] = $entry->toArray();

                continue;
            }

            if (blank(config("ai.providers.{$entry->provider}.key"))) {
                return [[], "{$entry->model}: no API key is configured for {$entry->provider}."];
            }

            $report = $probe((string) $entry->provider, $entry->model);

            if ($report['ok'] === false) {
                return [[], "{$entry->model}: ".($report['error'] ?? 'unknown error')];
            }

            $verified[] = $entry->withMeasurement(new Measurement(
                supportsTools: $report['supports_tools'],
                writeGuard: WriteGuard::from($report['write_guard']),
                verifiedAt: now()->toIso8601String(),
            ))->toArray();
        }

        return [$verified, null];
    }

    /**
     * Form state is not catalog-shaped. A Select bound to an enum hands back a Plan
     * instance, a numeric TextInput hands back a string, and a toggle can hand back
     * "1". CatalogEntry is where that becomes a catalog row, with every scalar
     * failing closed, so the panel no longer keeps its own coercion rules beside
     * the ones every reader already applies.
     *
     * @param  list<array<string, mixed>>  $models
     * @return list<CatalogEntry>
     */
    private function parseModels(array $models): array
    {
        return array_values(array_filter(array_map(CatalogEntry::fromArray(...), $models)));
    }

    /**
     * The Verified column: an icon an operator can scan a whole catalog down, and a
     * tooltip carrying the detail the column has no room for. Filament renders that
     * tooltip as the icon's visually-hidden text too, so the meaning is not
     * hover-only.
     *
     * Four states, because they are four different claims: this install watched the
     * provider accept a real request, the row only declares what it inherited from
     * the config seed, the provider will not serve tools so ModelRegistry hides the
     * row from every picker, or nothing has been measured yet. That last one is a
     * promise only an enabled row keeps, since verified() skips disabled entries.
     *
     * @return array{icon: Heroicon, color: string, tooltip: string}
     */
    private function capabilityBadge(Get $get): array
    {
        $measurement = Measurement::fromEntry([
            'capabilities' => $get('capabilities'),
            'verified_at' => $get('verified_at'),
        ]);

        if (! $measurement instanceof Measurement) {
            return (bool) $get('enabled')
                ? ['icon' => Heroicon::OutlinedClock, 'color' => 'warning', 'tooltip' => 'Not measured yet. Saving probes this model against its provider.']
                : ['icon' => Heroicon::OutlinedMinusCircle, 'color' => 'gray', 'tooltip' => 'Disabled, so it is never probed and never served.'];
        }

        $guard = $measurement->writeGuard->value;

        if (! $measurement->supportsTools) {
            return ['icon' => Heroicon::OutlinedExclamationTriangle, 'color' => 'danger', 'tooltip' => "No tool calls, so this model is offered to nobody. Write guard: {$guard}."];
        }

        $measured = $this->verifiedAgo($measurement->verifiedAt);

        if ($measured === null) {
            return ['icon' => Heroicon::OutlinedCheckCircle, 'color' => 'gray', 'tooltip' => "Declares tool calls and the {$guard} write guard, but no probe has run on this install."];
        }

        return ['icon' => Heroicon::OutlinedCheckBadge, 'color' => 'success', 'tooltip' => "The provider accepted a real request {$measured}. Tool calls, {$guard} write guard."];
    }

    /**
     * A stored timestamp is written by verified(), but the settings row is editable
     * outside the panel, and an unparseable one must not take the page down.
     */
    private function verifiedAgo(mixed $verifiedAt): ?string
    {
        if (! is_string($verifiedAt) || $verifiedAt === '') {
            return null;
        }

        return rescue(fn (): string => Date::parse($verifiedAt)->diffForHumans(), null, report: false);
    }

    /**
     * The vendor's own name for a model it lists, or the tag when it publishes none
     * (OpenAI) or cannot be reached. Never blank: `label` is required, and a blank
     * one would fail validation on a row the operator never typed into.
     */
    private function listedLabel(mixed $provider, string $model): string
    {
        if (! is_string($provider) || $provider === '') {
            return $model;
        }

        $listed = resolve(ProviderModelCatalog::class)($provider)[$model] ?? null;

        return is_array($listed) && is_string($listed['label'] ?? null) && $listed['label'] !== ''
            ? $listed['label']
            : $model;
    }

    /**
     * Flags a stored model the provider is not currently listing, which is normal
     * for an install without that provider's key and suspicious otherwise.
     */
    private function unlistedNote(mixed $provider, mixed $model): ?string
    {
        if (! is_string($provider) || ! is_string($model) || $model === '') {
            return null;
        }

        $catalog = resolve(ProviderModelCatalog::class)($provider);

        // Say nothing when the provider gave us no list at all: that means this
        // install has no key for it, not that the model is wrong.
        if ($catalog === [] || array_key_exists($model, $catalog)) {
            return null;
        }

        return 'not listed by the provider';
    }

    /**
     * The measurements already on record, keyed by pairing. This is what makes a
     * measurement survive an operator deleting a row and adding it straight back.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<string, Measurement>
     */
    private function storedByPairing(array $models): array
    {
        return collect($models)
            ->map(fn (mixed $model): ?CatalogEntry => is_array($model) ? CatalogEntry::fromArray($model) : null)
            ->filter(fn (?CatalogEntry $entry): bool => $entry?->pairing() !== null && $entry->measurement instanceof Measurement)
            ->keyBy(fn (CatalogEntry $entry): string => (string) $entry->pairing())
            ->map(fn (CatalogEntry $entry): Measurement => $entry->measurement)
            ->all();
    }

    /**
     * laravel/ai already spells every provider it supports, on the enum case name:
     * `OpenAI`, `DeepSeek`, `xAI`. `headline()` renders those as `Openai`, `Deepseek`,
     * `Xai`, so ask the enum first and fall back for a provider it does not know.
     */
    private function providerLabel(string $provider): string
    {
        return Lab::tryFrom($provider)?->name ?? str($provider)->headline()->toString();
    }

    /**
     * The providers this install can actually reach, plus whatever a stored row
     * already names.
     *
     * A provider with no API key can serve nothing, because save() rejects every
     * model under one, so offering it is offering a dead end. The merge of the current
     * value is load-bearing for the same reason as in modelOptions(): a Select
     * silently drops a value missing from its options, so a row naming a provider
     * that is keyed in production but not here would blank itself on render.
     *
     * @return array<string, string>
     */
    private function providerOptions(mixed $current = null): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('ai.providers', []);

        $options = collect($providers)
            ->filter(fn (array $connection): bool => filled($connection['key'] ?? null))
            ->keys()
            ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerLabel($provider)])
            ->all();

        if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $this->providerLabel($current).' (no API key)';
        }

        return $options;
    }

    /**
     * The provider's live list, with whatever is already stored merged in.
     *
     * The merge is load-bearing: a Select silently drops a value that is not among
     * its options, so without it every entry goes invalid the moment a provider is
     * unreachable or its key is absent on this install.
     *
     * @return array<string, string>
     */
    private function modelOptions(mixed $provider, mixed $current = null): array
    {
        $options = [];

        if (is_string($provider) && $provider !== '') {
            foreach (array_keys(resolve(ProviderModelCatalog::class)($provider)) as $id) {
                $options[$id] = $id;
            }
        }

        if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }
}
