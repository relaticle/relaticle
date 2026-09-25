<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFieldType;
use App\Enums\InlineCommit;
use App\Enums\WorkspaceRole;
use App\Filament\Concerns\EditsRecordFieldsInline;
use App\Filament\Concerns\RendersRecordSplitView;
use App\Filament\CustomFields\CheckboxListFieldType;
use App\Filament\CustomFields\EmailEntry;
use App\Filament\CustomFields\EmailFieldType;
use App\Filament\CustomFields\MultiSelectFieldType;
use App\Filament\CustomFields\OptionChipEntry;
use App\Filament\CustomFields\RadioFieldType;
use App\Filament\CustomFields\RichEditorComponent;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Support\InlineField\FieldState;
use App\Filament\Support\InlineField\InlineField;
use App\Filament\Support\InlineField\NativeFormField;
use App\Filament\Support\InlineField\RecordWriter;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Filament\Integration\Components\Forms\PhoneInput\PhoneInputComponent;

mutates(
    EditsRecordFieldsInline::class,
    RendersRecordSplitView::class,
    ViewOpportunity::class,
    ViewCompany::class,
    ViewPeople::class,
    InlineField::class,
    InlineCommit::class,
    FieldState::class,
    NativeFormField::class,
    RecordWriter::class,
    CustomFieldType::class,
    EmailEntry::class,
    EmailFieldType::class,
    OptionChipEntry::class,
    CheckboxListFieldType::class,
    MultiSelectFieldType::class,
    RadioFieldType::class,
    RichEditorComponent::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

function opportunityStageField(): CustomField
{
    return CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->firstOrFail();
}

function opportunityAmountField(): CustomField
{
    return CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::AMOUNT)
        ->firstOrFail();
}

function opportunityCloseDateField(): CustomField
{
    return CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::CLOSE_DATE)
        ->firstOrFail();
}

function peopleJobTitleField(): CustomField
{
    return CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->firstOrFail();
}

function storedCustomFieldValue(Company|Opportunity|People $record, CustomField $field): mixed
{
    $value = $record->fresh()->customFieldValues()
        ->where('custom_field_id', $field->getKey())
        ->value($field->getValueColumn());

    return $value instanceof Collection ? $value->all() : $value;
}

/**
 * @param  list<string>  $optionNames
 * @return array{0: CustomField, 1: list<CustomFieldOption>}
 */
function opportunityChoiceField(string $workspaceId, CustomFieldType $type, array $optionNames): array
{
    $field = CustomField::factory()->create([
        'tenant_id' => $workspaceId,
        'custom_field_section_id' => opportunityStageField()->getAttribute('custom_field_section_id'),
        'entity_type' => 'opportunity',
        'code' => 'regions',
        'name' => 'Regions',
        'type' => $type->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    $options = [];

    foreach ($optionNames as $index => $name) {
        $options[] = CustomFieldOption::query()->create([
            'tenant_id' => $workspaceId,
            'custom_field_id' => $field->getKey(),
            'name' => $name,
            'sort_order' => $index + 1,
        ]);
    }

    return [$field->load('options'), $options];
}

it('starts company name editing in the name field', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => 'Centera',
    ]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('startInlineEdit', 'name')
        ->assertSet('inlineEditingField', 'name')
        ->assertSee('fi-inline-field-editor', false)
        ->assertSeeHtml('data-inline-field="name"')
        ->assertSeeHtml('data-inline-editing="true"');
});

it('renders the amount editor in the field after click-to-edit starts', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertDontSee('fi-inline-field-editor', false)
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->assertSet('inlineEditingField', OpportunityField::AMOUNT->value)
        ->assertSee('fi-inline-field-editor', false);
});

it('saves an opportunity stage through the update action', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $stage = opportunityStageField();
    $qualification = $stage->options->firstWhere('name', 'Qualification');

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::STAGE->value)
        ->assertSet('inlineEditingField', OpportunityField::STAGE->value)
        ->set('inlineEditData.custom_fields.stage', $qualification->getKey())
        ->call('saveInlineField')
        ->assertSet('inlineEditingField', null)
        ->assertHasNoErrors()
        ->assertNotNotified();

    expect(storedCustomFieldValue($record, $stage))->toEqual($qualification->getKey());
});

it('keeps the editor open and the typed amount when validation fails', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', 'not-a-number')
        ->call('saveInlineField')
        ->assertHasErrors()
        ->assertNotified()
        ->assertSeeHtml('fi-inline-field-editor-invalid')
        ->assertSet('inlineEditingField', OpportunityField::AMOUNT->value)
        ->assertSet('inlineEditData.custom_fields.amount', 'not-a-number');

    expect(storedCustomFieldValue($record, opportunityAmountField()))->toBeEmpty();
});

it('clears an opportunity amount when saved empty', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $amount = opportunityAmountField();
    $record->saveCustomFieldValue($amount, 15000);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', null)
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($record, $amount))->toBeEmpty();
});

it('rejects a stale opportunity save and keeps the draft', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Original']);
    $amount = opportunityAmountField();

    $page = livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value);

    $this->travel(1)->second();
    $record->update(['name' => 'Changed elsewhere']);

    $page->set('inlineEditData.custom_fields.amount', 99)
        ->call('saveInlineField')
        ->assertHasErrors(['inlineEditConflict'])
        ->assertNotified()
        ->assertSet('inlineEditingField', OpportunityField::AMOUNT->value)
        ->assertSet('inlineEditData.custom_fields.amount', 99);

    expect(storedCustomFieldValue($record, $amount))->toBeEmpty()
        ->and($record->fresh()->name)->toBe('Changed elsewhere');
});

it('does not persist a person company when inline select editing is cancelled', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create([
        'company_id' => $company->getKey(),
    ]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'company_id')
        ->assertSet('inlineEditingField', 'company_id')
        ->assertSee('fi-inline-field-editor', false)
        ->call('cancelInlineEdit')
        ->assertSet('inlineEditingField', null)
        ->assertDontSee('fi-inline-field-editor', false);

    expect($person->fresh()->company_id)->toBe($company->getKey());
});

it('does not persist when the opportunity inline edit is cancelled', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $amount = opportunityAmountField();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', 42)
        ->call('cancelInlineEdit')
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($record, $amount))->toBeEmpty();
});

it('saves an opportunity amount through the update action', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $amount = opportunityAmountField();
    $record->saveCustomFieldValue($amount, 100);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', 200)
        ->call('saveInlineField')
        ->assertSet('inlineEditingField', null)
        ->assertNotNotified()
        ->assertHasNoErrors();

    expect(storedCustomFieldValue($record, $amount))->toEqual(200);
});

it('renders the close date picker after click-to-edit starts', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::CLOSE_DATE->value)
        ->assertSet('inlineEditingField', OpportunityField::CLOSE_DATE->value)
        ->assertSee('fi-fo-date-time-picker', false);
});

it('saves an opportunity close date', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $closeDate = opportunityCloseDateField();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::CLOSE_DATE->value)
        ->set('inlineEditData.custom_fields.close_date', '2026-09-20')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect((string) storedCustomFieldValue($record, $closeDate))->toStartWith('2026-09-20');
});

it('puts copy and delete on the opportunity details card instead of edit all', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $copy = TestAction::make('copyPageUrl')->schemaComponent('opportunityDetails');
    $delete = TestAction::make('delete')->schemaComponent('opportunityDetails');

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('opportunityDetails'))
        ->assertActionDoesNotExist('edit')
        ->assertActionExists($copy)
        ->assertActionExists($delete);
});

it('does not let a viewer start or save an inline opportunity edit', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);
    Filament::setTenant($this->workspace);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertDontSee('fi-inline-editable', false)
        ->call('startInlineEdit', OpportunityField::STAGE->value)
        ->assertSet('inlineEditingField', null)
        ->call('saveInlineField')
        ->assertForbidden();
});

it('saves a company account owner through the update action', function (): void {
    $owner = User::factory()->create();
    $role = WorkspaceRole::tryFrom('member')
        ?? WorkspaceRole::tryFrom('editor')
        ?? WorkspaceRole::Viewer;
    $this->workspace->users()->attach($owner, ['role' => $role->value]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'account_owner_id' => $this->user->id,
    ]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('startInlineEdit', 'account_owner_id')
        ->set('inlineEditData.account_owner_id', $owner->getKey())
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null)
        ->assertNotNotified();

    expect($company->fresh()->account_owner_id)->toBe($owner->getKey());
});

it('puts copy and delete on the company details card instead of edit all', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $copy = TestAction::make('copyPageUrl')->schemaComponent('companyDetails');
    $delete = TestAction::make('delete')->schemaComponent('companyDetails');

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('companyDetails'))
        ->assertActionDoesNotExist('edit')
        ->assertActionExists($copy)
        ->assertActionExists($delete);
});

it('saves a person job title through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $jobTitle = peopleJobTitleField();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->set('inlineEditData.custom_fields.job_title', 'VP Sales')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $jobTitle))->toBe('VP Sales');
});

it('clears a person job title when saved empty', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $jobTitle = peopleJobTitleField();
    $person->saveCustomFieldValue($jobTitle, 'Engineer');

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->set('inlineEditData.custom_fields.job_title', '')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $jobTitle))->toBeEmpty();
});

it('puts copy and delete on the person details card instead of edit all', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $copy = TestAction::make('copyPageUrl')->schemaComponent('personDetails');
    $delete = TestAction::make('delete')->schemaComponent('personDetails');

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('personDetails'))
        ->assertActionExists($copy)
        ->assertActionExists($delete);
});

it('saves an opportunity name through the update action', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old name']);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', 'name')
        ->set('inlineEditData.name', 'New name')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null)
        ->assertNotNotified();

    expect($record->fresh()->name)->toBe('New name');
});

it('does not show a done button for a simple amount field', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->assertDontSee('fi-inline-field-done', false);
});

it('does not show a done button for a person email list', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->assertDontSee('fi-inline-field-done', false);
});

it('does not show done or cancel below a textarea custom field', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = peopleJobTitleField()->getAttribute('custom_field_section_id');
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'bio',
        'name' => 'Bio',
        'type' => CustomFieldType::TEXTAREA->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'bio')
        ->assertDontSeeHtml('fi-inline-field-editor-confirm')
        ->assertDontSeeHtml('fi-inline-field-done')
        ->assertDontSeeHtml('fi-inline-field-cancel')
        ->assertSeeHtml('<textarea');
});

it('opens a tags field as a compact input without done or cancel', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = peopleJobTitleField()->getAttribute('custom_field_section_id');
    $field = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'hobby',
        'name' => 'Hobby',
        'type' => CustomFieldType::TAGS_INPUT->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'hobby')
        ->assertSeeHtml('fi-fo-tags-input')
        ->assertDontSeeHtml('fi-inline-field-editor-confirm')
        ->assertDontSeeHtml('fi-inline-field-done')
        ->assertDontSeeHtml('fi-inline-field-cancel')
        ->set('inlineEditData.custom_fields.hobby', ['hiking'])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $field))->toBe(['hiking']);
});

it('renders person emails as packed links then starts edit', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, [
        'first@example.test',
        'second@example.test',
        'third@example.test',
    ]);

    livewire(ViewPeople::class, ['record' => $person->fresh()->getKey()])
        ->assertSee('mailto:first@example.test', false)
        ->assertSee('mailto:second@example.test', false)
        ->assertSee('mailto:third@example.test', false)
        ->assertSeeHtml('x-data="multiValueOverflow')
        ->assertDontSee(__('filament/inline-edit.show_less'))
        ->assertDontSeeHtml('fi-multi-value-copy')
        ->assertDontSeeHtml('fi-multi-value-toggle')
        ->assertDontSeeHtml('max-w-[250px]')
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->assertSet('inlineEditingField', PeopleField::EMAILS->value)
        ->assertSeeHtml('fi-inline-field-editor')
        ->assertSeeHtml('fi-fo-multi-value-input')
        ->assertDontSeeHtml('fi-fo-multi-value-plain')
        ->assertSeeHtml('+<span x-text="hiddenCount"></span>')
        ->assertDontSeeHtml('+<span x-text="hiddenCount"></span> more');
});

it('renders a person email as a mailto link and still starts edit from the field', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, ['vp@example.com']);

    livewire(ViewPeople::class, ['record' => $person->fresh()->getKey()])
        ->assertSee('mailto:vp@example.com', false)
        ->assertSeeHtml('fi-multi-value-copy')
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->assertSet('inlineEditingField', PeopleField::EMAILS->value);
});

it('saves a person email list through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->set('inlineEditData.custom_fields.emails', ['vp@example.com'])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $emails))->toBe(['vp@example.com']);
});

it('rejects an invalid person email list and marks the editor', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->set('inlineEditData.custom_fields.emails', ['asas'])
        ->call('saveInlineField')
        ->assertHasErrors()
        ->assertNotified()
        ->assertSeeHtml('fi-inline-field-editor-invalid')
        ->assertSet('inlineEditingField', PeopleField::EMAILS->value);

    expect(storedCustomFieldValue($person, $emails))->toBeEmpty();
});

it('saves a person linkedin url through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::LINKEDIN->value)
        ->set('inlineEditData.custom_fields.linkedin', ['https://www.linkedin.com/in/example'])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $linkedin))->toBe(['www.linkedin.com/in/example']);
});

it('saves a person linkedin url typed as a single string', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::LINKEDIN->value)
        ->set('inlineEditData.custom_fields.linkedin', 'https://www.linkedin.com/in/example')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $linkedin))->toBe(['www.linkedin.com/in/example']);
});

it('saves a person linkedin url after a failed validation is corrected', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::LINKEDIN->value)
        ->set('inlineEditData.custom_fields.linkedin', 'not a url')
        ->call('saveInlineField')
        ->assertHasErrors()
        ->assertNotified()
        ->assertSet('inlineEditingField', PeopleField::LINKEDIN->value)
        ->set('inlineEditData.custom_fields.linkedin', 'sas.com')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $linkedin))->toBe(['sas.com']);
});

it('saves a person linkedin url without a scheme', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::LINKEDIN->value)
        ->set('inlineEditData.custom_fields.linkedin', 'test.com')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $linkedin))->toBe(['test.com']);
});

it('shows set-field placeholders on empty person values', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSee('Set company')
        ->assertSee('Set emails')
        ->assertSee('Set phone number')
        ->assertSee('Set job title')
        ->assertSee('Set linkedin')
        ->assertSeeHtml('class="fi-in-placeholder"')
        ->assertDontSeeHtml('<span class="text-gray-400 dark:text-gray-500">—</span>');
});

it('renders a live company icp switch instead of click-to-edit', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeHtml('data-inline-field="icp"')
        ->assertSeeHtml('role="switch"')
        ->assertSeeHtml('aria-checked="false"')
        ->assertSee('fi-inline-boolean', false)
        ->assertSee('fi-toggle', false)
        ->assertDontSee('Set ICP')
        ->call('startInlineEdit', CompanyField::ICP->value)
        ->assertSet('inlineEditingField', null)
        ->assertDontSee('fi-inline-field-editor', false);
});

it('saves a company icp toggle through the update action', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $icp = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::ICP)
        ->firstOrFail();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('toggleInlineBoolean', CompanyField::ICP->value)
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null)
        ->assertSeeHtml('aria-checked="true"');

    expect(storedCustomFieldValue($company, $icp))->toBeTrue();
});

it('turns a company icp toggle back off through the update action', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $icp = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::ICP)
        ->firstOrFail();
    $company->saveCustomFieldValue($icp, true);

    livewire(ViewCompany::class, ['record' => $company->fresh()->getKey()])
        ->assertSeeHtml('aria-checked="true"')
        ->call('toggleInlineBoolean', CompanyField::ICP->value)
        ->assertHasNoErrors()
        ->assertSeeHtml('aria-checked="false"');

    expect(storedCustomFieldValue($company, $icp))->toBeFalse();
});

it('does not let a viewer toggle a company icp switch', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);
    Filament::setTenant($this->workspace);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeHtml('role="switch"')
        ->assertSeeHtml('disabled')
        ->call('toggleInlineBoolean', CompanyField::ICP->value)
        ->assertForbidden();
});

it('ignores a boolean toggle on a field that is not a boolean', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => 'Northwind',
    ]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('toggleInlineBoolean', 'name')
        ->assertHasNoErrors();

    expect($company->fresh()->name)->toBe('Northwind');
});

it('saves a tenant-defined checkbox through the live switch', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::ICP)
        ->firstOrFail()
        ->getAttribute('custom_field_section_id');
    $vip = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'company',
        'code' => 'vip',
        'name' => 'VIP',
        'type' => CustomFieldType::CHECKBOX->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeHtml('data-inline-field="vip"')
        ->assertSeeHtml('aria-label="VIP"')
        ->call('toggleInlineBoolean', 'vip')
        ->assertHasNoErrors()
        ->assertSeeHtml('aria-checked="true"');

    expect(storedCustomFieldValue($company, $vip))->toBeTrue();
});

it('shows a set-field placeholder on an empty opportunity option list', function (CustomFieldType $type): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    opportunityChoiceField($this->workspace->getKey(), $type, ['Enterprise', 'EU']);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertSee('Set regions')
        ->assertSeeHtml('data-inline-field="regions"')
        ->assertSeeHtml('class="fi-in-placeholder"')
        ->assertDontSeeHtml('fi-checkbox-input')
        ->assertDontSeeHtml('fi-multi-value-chip')
        ->assertDontSee('Enterprise');
})->with([
    'checkbox-list' => CustomFieldType::CHECKBOX_LIST,
    'multi-select' => CustomFieldType::MULTI_SELECT,
    'radio' => CustomFieldType::RADIO,
]);

it('renders selected option-list values as chips', function (CustomFieldType $type): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    [$field, $options] = opportunityChoiceField($this->workspace->getKey(), $type, ['Enterprise', 'EU', 'SMB']);
    $record->saveCustomFieldValue($field, [$options[0]->getKey(), $options[1]->getKey()]);

    livewire(ViewOpportunity::class, ['record' => $record->fresh()->getKey()])
        ->assertSee('Enterprise')
        ->assertSee('EU')
        ->assertDontSee('SMB')
        ->assertSeeHtml('fi-multi-value-chips')
        ->assertSeeHtml('x-data="multiValueOverflow')
        ->assertSeeHtml('fi-multi-value-chip')
        ->assertDontSeeHtml('fi-checkbox-input')
        ->assertDontSeeHtml('fi-multi-value-copy')
        ->call('startInlineEdit', 'regions')
        ->assertSet('inlineEditingField', 'regions')
        ->assertSeeHtml('fi-inline-field-editor')
        ->assertSeeHtml('fi-select-input')
        ->assertDontSeeHtml('fi-inline-choice-dropdown')
        ->assertDontSeeHtml('fi-fo-checkbox-list')
        ->assertSee('SMB')
        ->assertDontSeeHtml('fi-inline-field-done')
        ->assertDontSeeHtml('fi-inline-field-cancel');
})->with([
    'checkbox-list' => CustomFieldType::CHECKBOX_LIST,
    'multi-select' => CustomFieldType::MULTI_SELECT,
]);

it('opens a radio field as a dropdown of radio options without done or cancel', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    [$field, $options] = opportunityChoiceField($this->workspace->getKey(), CustomFieldType::RADIO, ['Enterprise', 'EU', 'SMB']);
    $record->saveCustomFieldValue($field, $options[0]->getKey());

    livewire(ViewOpportunity::class, ['record' => $record->fresh()->getKey()])
        ->assertSee('Enterprise')
        ->assertSeeHtml('fi-multi-value-chip')
        ->assertDontSee('SMB')
        ->call('startInlineEdit', 'regions')
        ->assertSet('inlineEditingField', 'regions')
        ->assertSeeHtml('fi-inline-radio-select')
        ->assertSeeHtml('fi-select-input')
        ->assertDontSeeHtml('fi-inline-choice-dropdown')
        ->assertDontSeeHtml('fi-fo-radio')
        ->assertSee('SMB')
        ->assertDontSeeHtml('fi-inline-field-done')
        ->assertDontSeeHtml('fi-inline-field-cancel');
});

it('saves an opportunity checkbox list without closing the editor', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    [$field, $options] = opportunityChoiceField($this->workspace->getKey(), CustomFieldType::CHECKBOX_LIST, ['Enterprise', 'EU']);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', 'regions')
        ->set('inlineEditData.custom_fields.regions', [$options[1]->getKey()])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', 'regions')
        ->assertDontSeeHtml('fi-inline-field-done');

    expect(storedCustomFieldValue($record, $field))->toBe([$options[1]->getKey()]);
});

it('saves an opportunity radio choice and closes the editor', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    [$field, $options] = opportunityChoiceField($this->workspace->getKey(), CustomFieldType::RADIO, ['Enterprise', 'EU']);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', 'regions')
        ->set('inlineEditData.custom_fields.regions', $options[1]->getKey())
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($record, $field))->toBe($options[1]->getKey());
});

it('saves a tenant-defined custom text field through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = peopleJobTitleField()->getAttribute('custom_field_section_id');
    $nickname = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'nickname',
        'name' => 'Nickname',
        'type' => CustomFieldType::TEXT->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'nickname')
        ->set('inlineEditData.custom_fields.nickname', 'TC')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $nickname))->toBe('TC');
});

it('opens the same color picker as the edit-all form', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = peopleJobTitleField()->getAttribute('custom_field_section_id');
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'brand_color',
        'name' => 'Brand color',
        'type' => CustomFieldType::COLOR_PICKER->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    $page = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'brand_color')
        ->assertSet('inlineEditingField', 'brand_color')
        ->assertSee('fi-fo-color-picker', false)
        ->instance();

    $picker = collect($page->getSchema('inlineEditForm')->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof ColorPicker);

    expect($picker)->not->toBeNull()
        ->and($picker->isLive())->toBeFalse()
        ->and($picker->isAutofocused())->toBeFalse();
});

it('saves a tenant-defined color picker through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $sectionId = peopleJobTitleField()->getAttribute('custom_field_section_id');
    $color = CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'brand_color',
        'name' => 'Brand color',
        'type' => CustomFieldType::COLOR_PICKER->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', 'brand_color')
        ->set('inlineEditData.custom_fields.brand_color', '#d62828')
        ->assertSet('inlineEditingField', 'brand_color')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $color))->toBe('#d62828');
});

it('commits the open field when another inline field is opened', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $jobTitle = peopleJobTitleField();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->set('inlineEditData.custom_fields.job_title', 'VP Sales')
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', PeopleField::PHONE_NUMBER->value);

    expect(storedCustomFieldValue($person, $jobTitle))->toBe('VP Sales');
});

it('stays on the open field when switching fails validation', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->set('inlineEditData.custom_fields.phone_number', [['country' => 'US', 'number' => 'not-a-phone']])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->assertHasErrors()
        ->assertNotified()
        ->assertSet('inlineEditingField', PeopleField::PHONE_NUMBER->value);

    expect(storedCustomFieldValue($person, $phone))->toBeEmpty();
});

it('ignores a field code that is not editable on the record', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->assertSet('inlineEditingField', null)
        ->call('startInlineEdit', 'created_at')
        ->assertSet('inlineEditingField', null);
});

it('re-renders sibling custom fields after an inline save', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();

    $person->saveCustomFieldValue($emails, ['vp@example.com']);
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    livewire(ViewPeople::class, ['record' => $person->fresh()->getKey()])
        ->call('startInlineEdit', PeopleField::JOB_TITLE->value)
        ->set('inlineEditData.custom_fields.job_title', 'VP Sales')
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSee('vp@example.com')
        ->assertSee('+14155550103');
});

it('keeps an invalid person phone in the editor', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->set('inlineEditData.custom_fields.phone_number', [['country' => 'US', 'number' => 'not-a-phone']])
        ->call('saveInlineField')
        ->assertHasErrors()
        ->assertNotified()
        ->assertSeeHtml('fi-inline-field-editor-invalid')
        ->assertSet('inlineEditingField', PeopleField::PHONE_NUMBER->value)
        ->assertSet('inlineEditData.custom_fields.phone_number.0.number', 'not-a-phone');

    expect(storedCustomFieldValue($person, $phone))->toBeEmpty();
});

it('keeps the phone editor from saving until enter or blur', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    $page = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->instance();

    $input = collect($page->getSchema('inlineEditForm')->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof PhoneInputComponent);

    expect($input)->not->toBeNull()
        ->and($input->isLive())->toBeFalse();
});

it('saves a person phone number after a failed validation is corrected', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->set('inlineEditData.custom_fields.phone_number', [['country' => 'US', 'number' => 'not-a-phone']])
        ->call('saveInlineField')
        ->assertHasErrors()
        ->assertNotified()
        ->assertSet('inlineEditingField', PeopleField::PHONE_NUMBER->value)
        ->set('inlineEditData.custom_fields.phone_number', [['country' => 'US', 'number' => '4155550103']])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $phone))->toBe(['+14155550103']);
});

it('saves a person phone number through the update action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::PHONE_NUMBER->value)
        ->set('inlineEditData.custom_fields.phone_number', [['country' => 'US', 'number' => '4155550103']])
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($person, $phone))->toBe(['+14155550103']);
});

it('shows a set-field placeholder on an empty company rich editor', function (): void {
    CustomField::forceCreate([
        'tenant_id' => $this->workspace->id,
        'code' => 'account_plan',
        'name' => 'Account plan',
        'type' => CustomFieldType::RICH_EDITOR->value,
        'entity_type' => 'company',
        'sort_order' => 50,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('Set account plan')
        ->assertSeeHtml('data-inline-field="account_plan"')
        ->assertDontSee('fi-inline-field-editor', false);
});

it('opens a right-side sheet rich editor from a company field click', function (): void {
    CustomField::forceCreate([
        'tenant_id' => $this->workspace->id,
        'code' => 'account_plan',
        'name' => 'Account plan',
        'type' => CustomFieldType::RICH_EDITOR->value,
        'entity_type' => 'company',
        'sort_order' => 50,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $page = livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('startInlineEdit', 'account_plan')
        ->assertSet('inlineEditingField', null)
        ->assertActionMounted('editRichField')
        ->assertDontSee('fi-inline-field-editor', false)
        ->instance();

    $editor = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof RichEditor);

    expect($editor)->not->toBeNull()
        ->and($editor->getPlaceholder())->toBeNull()
        ->and($editor->getExtraAttributes())->toHaveKey('data-slash-menu')
        ->and($editor->getExtraAttributes()['class'])->toContain('fi-fo-rich-editor-seamless')
        ->and($page->getAction('editRichField')->isModalSlideOver())->toBeTrue();
});

it('saves a company rich editor field through the right-side sheet', function (): void {
    $plan = CustomField::forceCreate([
        'tenant_id' => $this->workspace->id,
        'code' => 'account_plan',
        'name' => 'Account plan',
        'type' => CustomFieldType::RICH_EDITOR->value,
        'entity_type' => 'company',
        'sort_order' => 50,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(TestAction::make('editRichField')->arguments(['code' => 'account_plan']), [
            'custom_fields' => ['account_plan' => '<p>Q3 plan</p>'],
        ])
        ->assertHasNoActionErrors();

    expect(storedCustomFieldValue($company, $plan))->toContain('Q3 plan');
});

it('does not let a viewer open the company rich editor sheet', function (): void {
    CustomField::forceCreate([
        'tenant_id' => $this->workspace->id,
        'code' => 'account_plan',
        'name' => 'Account plan',
        'type' => CustomFieldType::RICH_EDITOR->value,
        'entity_type' => 'company',
        'sort_order' => 50,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new CustomFieldSettingsData,
    ]);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);
    Filament::setTenant($this->workspace);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('startInlineEdit', 'account_plan')
        ->assertActionNotMounted('editRichField')
        ->assertSet('inlineEditingField', null);
});
