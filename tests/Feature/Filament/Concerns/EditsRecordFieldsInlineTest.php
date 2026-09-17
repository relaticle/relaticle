<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFieldType;
use App\Enums\WorkspaceRole;
use App\Filament\Concerns\EditsRecordFieldsInline;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Support\InlineField\InlineField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

mutates(
    EditsRecordFieldsInline::class,
    ViewOpportunity::class,
    ViewCompany::class,
    ViewPeople::class,
    InlineField::class,
    CustomFieldType::class,
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

it('keeps undo next to the amount after a successful save', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $amount = opportunityAmountField();
    $record->saveCustomFieldValue($amount, 100);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', 200)
        ->call('saveInlineField')
        ->assertSet('inlineEditingField', null)
        ->assertNotNotified()
        ->assertSee('fi-inline-field-feedback', false)
        ->assertSee('fi-inline-field-saved', false)
        ->assertSee('fi-inline-field-undo', false)
        ->assertSee(__('filament/inline-edit.saved'));
});

it('restores the previous amount when the save is undone', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $amount = opportunityAmountField();
    $record->saveCustomFieldValue($amount, 100);

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->call('startInlineEdit', OpportunityField::AMOUNT->value)
        ->set('inlineEditData.custom_fields.amount', 200)
        ->call('saveInlineField')
        ->call('undoInlineField')
        ->assertHasNoErrors();

    expect(storedCustomFieldValue($record, $amount))->toEqual(100);
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

it('keeps the header edit-all action on an opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertActionExists('edit')
        ->assertActionHasLabel('edit', __('filament/resources/opportunity.pages.view.actions.edit.label'))
        ->mountAction('edit')
        ->assertActionMounted('edit');
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
    $this->workspace->users()->attach($owner, ['role' => WorkspaceRole::Editor->value]);
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

it('keeps the header edit-all action on a company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionExists('edit')
        ->assertActionHasLabel('edit', __('filament/resources/company.pages.view.actions.edit.label'))
        ->mountAction('edit')
        ->assertActionMounted('edit');
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

it('keeps the header edit-all action on a person', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionExists('edit')
        ->assertActionHasLabel('edit', __('filament/resources/person.pages.view.actions.edit.label'))
        ->mountAction('edit')
        ->assertActionMounted('edit');
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
        ->assertDontSee('fi-inline-field-done', false)
        ->assertSee('fi-inline-field-saving', false);
});

it('does not show a done button for a person email list', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('startInlineEdit', PeopleField::EMAILS->value)
        ->assertDontSee('fi-inline-field-done', false);
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

it('saves a company icp toggle through the update action', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $icp = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::ICP)
        ->firstOrFail();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('startInlineEdit', CompanyField::ICP->value)
        ->set('inlineEditData.custom_fields.icp', true)
        ->call('saveInlineField')
        ->assertHasNoErrors()
        ->assertSet('inlineEditingField', null);

    expect(storedCustomFieldValue($company, $icp))->toBeTrue();
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
        ->assertSee('must be a valid phone number')
        ->assertSee('fi-fo-field-wrp-error-message', false)
        ->assertSet('inlineEditingField', PeopleField::PHONE_NUMBER->value)
        ->assertSet('inlineEditData.custom_fields.phone_number.0.number', 'not-a-phone');

    expect(storedCustomFieldValue($person, $phone))->toBeEmpty();
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
