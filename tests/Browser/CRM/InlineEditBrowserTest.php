<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFieldType;
use App\Filament\Concerns\EditsRecordFieldsInline;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Support\MultiValueAddPlaceholder;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Collection;

mutates(EditsRecordFieldsInline::class, ViewCompany::class, ViewOpportunity::class, ViewPeople::class, MultiValueAddPlaceholder::class);

it('toggles company icp from the record view', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);
    $icp = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::ICP)
        ->firstOrFail();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSee('Northwind')
        ->assertVisible('[data-inline-field="icp"] [role="switch"]')
        ->assertAttribute('[data-inline-field="icp"] [role="switch"]', 'aria-checked', 'false')
        ->click('[data-inline-field="icp"] [role="switch"]')
        ->wait(1)
        ->assertAttribute('[data-inline-field="icp"] [role="switch"]', 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();

    expect($company->fresh()->customFieldValues()
        ->where('custom_field_id', $icp->getKey())
        ->value($icp->getValueColumn()))->toBeTrue();
});

it('keeps the domain extra count after toggling another field', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);
    $domains = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::DOMAINS)
        ->firstOrFail();
    $company->saveCustomFieldValue($domains, [
        'www.list.ru',
        'ilyapashayan.com',
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSee('www.list.ru')
        ->assertSee(__('filament/inline-edit.show_n_more', ['count' => 1]))
        ->click('[data-inline-field="icp"] [role="switch"]')
        ->wait(1)
        ->assertAttribute('[data-inline-field="icp"] [role="switch"]', 'aria-checked', 'true')
        ->assertSee(__('filament/inline-edit.show_n_more', ['count' => 1]))
        ->assertNoJavaScriptErrors();
});

it('opens company name click-to-edit and saves from the keyboard', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSee('Northwind')
        ->click('[data-inline-field="name"] .fi-in-entry-content')
        ->assertVisible('[data-inline-field="name"] .fi-inline-field-editor input.fi-input')
        ->assertNoJavaScriptErrors();

    $page->keys('[data-inline-field="name"] .fi-inline-field-editor input.fi-input', ['Control+a'])
        ->type('[data-inline-field="name"] .fi-inline-field-editor input.fi-input', 'Contoso')
        ->keys('[data-inline-field="name"] .fi-inline-field-editor input.fi-input', 'Enter')
        ->assertSee('Contoso')
        ->assertNoJavaScriptErrors();

    expect($company->fresh()->name)->toBe('Contoso');
});

it('edits the person name in place beside its avatar and ignores clicks beside the name', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada')
        ->assertNoJavaScriptErrors();

    $idle = $page->script(<<<'JS'
        (() => {
            const field = document.querySelector('[data-inline-field="name"]');
            const box = field.getBoundingClientRect();
            const pencil = field.querySelector('.fi-inline-edit-pencil');
            document.elementFromPoint(box.right - 60, box.top + box.height / 2)?.click();

            return {
                pencilHidden: ! pencil || getComputedStyle(pencil).display === 'none',
                textLeft: field.querySelector('.fi-record-chip > .truncate').getBoundingClientRect().left,
                fieldHeight: box.height,
            };
        })();
    JS);

    $page->wait(1)
        ->assertAttribute('[data-inline-field="name"]', 'data-inline-editing', 'false')
        ->click('[data-inline-field="name"] .fi-in-entry-content')
        ->assertVisible('[data-inline-field="name"] .fi-inline-field-editor input.fi-input');

    $editing = $page->script(<<<'JS'
        (() => {
            const field = document.querySelector('[data-inline-field="name"]');
            const avatar = field.querySelector('.fi-record-chip > :first-child').getBoundingClientRect();
            const input = field.querySelector('.fi-inline-field-editor input.fi-input');
            const inputBox = input.getBoundingClientRect();

            return {
                avatarVisible: avatar.width > 0,
                gapAfterAvatar: inputBox.left - avatar.right,
                textLeft: inputBox.left + parseFloat(getComputedStyle(input).paddingInlineStart),
                fieldHeight: field.getBoundingClientRect().height,
            };
        })();
    JS);

    expect($idle['pencilHidden'])->toBeTrue()
        ->and($editing['avatarVisible'])->toBeTrue()
        ->and($editing['gapAfterAvatar'])->toBeGreaterThan(0)
        ->and(abs($editing['textLeft'] - $idle['textLeft']))->toBeLessThanOrEqual(1)
        ->and($editing['fieldHeight'])->toBe($idle['fieldHeight']);
});

it('stacks the opportunity name above company and contact chips', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Acme',
    ]);
    $contact = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
        'company_id' => $company->getKey(),
    ]);
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create([
        'name' => 'Enterprise rollout',
        'company_id' => $company->getKey(),
        'contact_id' => $contact->getKey(),
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/{$opportunity->getKey()}")
        ->assertSee('Enterprise rollout')
        ->assertSee('Acme')
        ->assertSee('Ada Lovelace')
        ->assertNoJavaScriptErrors();

    $layout = $page->script(<<<'JS'
        (() => {
            const name = document.querySelector('[data-inline-field="name"]').getBoundingClientRect();
            const company = document.querySelector('[data-inline-field="company_id"]').getBoundingClientRect();
            const contact = document.querySelector('[data-inline-field="contact_id"]').getBoundingClientRect();

            return {
                companyBelowName: company.top >= name.bottom - 1,
                contactBelowName: contact.top >= name.bottom - 1,
                chipsOverlapName: company.top < name.bottom - 4 && company.left < name.right - 4,
            };
        })();
    JS);

    expect($layout)->toMatchArray([
        'companyBelowName' => true,
        'contactBelowName' => true,
        'chipsOverlapName' => false,
    ]);
});

it('stacks company fields in one column on the desktop record view', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
        'account_owner_id' => $user->id,
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSee('Northwind')
        ->assertVisible('[data-inline-field="account_owner_id"]')
        ->assertVisible('[data-inline-field="icp"]')
        ->assertNoJavaScriptErrors();

    $layout = $page->script(<<<'JS'
        (() => {
            const owner = document.querySelector('[data-inline-field="account_owner_id"]').getBoundingClientRect();
            const icp = document.querySelector('[data-inline-field="icp"]').getBoundingClientRect();
            const domains = document.querySelector('[data-inline-field="domains"]')?.getBoundingClientRect();

            return {
                icpBelowOwner: icp.top >= owner.bottom - 1,
                icpNotBesideOwner: icp.left < owner.right - 24 || icp.top >= owner.bottom - 1,
                domainsBelowIcp: domains ? domains.top >= icp.bottom - 1 : true,
            };
        })();
    JS);

    expect($layout)->toMatchArray([
        'icpBelowOwner' => true,
        'icpNotBesideOwner' => true,
        'domainsBelowIcp' => true,
    ]);
});

it('opens the person email editor from the packed field', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
    ]);
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, [
        'first@example.test',
        'second@example.test',
        'third@example.test',
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('first@example.test')
        ->assertSee(__('filament/inline-edit.show_n_more', ['count' => 2]))
        ->click('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="emails"] .fi-inline-field-editor')
        ->assertVisible('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertAttribute('[data-inline-field="emails"]', 'data-inline-editing', 'true')
        ->assertNoJavaScriptErrors();
});

it('keeps email overlay delete buttons inside the panel', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
    ]);
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, [
        'first@example.test',
        'very-long-address-that-should-not-push-the-delete-icon-out@example.test',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="emails"] .fi-inline-field-editor');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-inline-field="emails"] button[aria-haspopup="dialog"]');
            if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
                trigger.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
            }
            return true;
        })();
    JS);

    $inside = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            if (! panel) {
                return { ok: false };
            }

            const panelBox = panel.getBoundingClientRect();
            const deletes = [...panel.querySelectorAll('button[aria-label^="Delete"]')];
            const allInside = deletes.length > 0 && deletes.every((button) => {
                const box = button.getBoundingClientRect();

                return box.right <= panelBox.right + 1 && box.left >= panelBox.left - 1;
            });

            return {
                ok: true,
                count: deletes.length,
                allInside,
            };
        })();
    JS);

    expect($inside['ok'])->toBeTrue()
        ->and($inside['count'])->toBe(2)
        ->and($inside['allInside'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('hugs the empty email overlay to the add row', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
    ]);
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, []);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="emails"] .fi-inline-field-editor');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-inline-field="emails"] button[aria-haspopup="dialog"]');
            if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
                trigger.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
            }
            return true;
        })();
    JS);

    $overlay = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            const add = panel?.querySelector('input[placeholder]');
            if (! panel || ! add) {
                return { ok: false };
            }

            const panelBox = panel.getBoundingClientRect();
            const addBox = add.getBoundingClientRect();

            return {
                ok: true,
                height: panelBox.height,
                rows: panel.querySelectorAll('.fi-fo-multi-value-panel-row').length,
                placeholder: add.getAttribute('placeholder') ?? '',
                addFits: addBox.top >= panelBox.top - 1 && addBox.bottom <= panelBox.bottom + 1,
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['rows'])->toBe(0)
        ->and($overlay['height'])->toBeGreaterThan(28)
        ->and($overlay['height'])->toBeLessThan(56)
        ->and($overlay['placeholder'])->toBe(__('filament/inline-edit.add_email').'...')
        ->and($overlay['addFits'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('paints the email overlay above the work pane', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
    ]);
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, [
        'first@example.test',
        'second@example.test',
        'third@example.test',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="emails"] .fi-inline-field-editor');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-inline-field="emails"] button[aria-haspopup="dialog"]');
            if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
                trigger.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
            }
            return true;
        })();
    JS);

    $hitsPanel = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            const work = document.querySelector('.fi-record-work-pane');
            if (! panel || ! work) {
                return false;
            }

            const box = panel.getBoundingClientRect();
            const workBox = work.getBoundingClientRect();
            const x = Math.min(box.right - 8, workBox.left + 12);
            const y = box.top + Math.min(24, box.height / 2);
            const hit = document.elementFromPoint(x, y);

            return Boolean(hit?.closest('.fi-fo-multi-value-panel'));
        })();
    JS);

    expect($hitsPanel)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('opens company domains as a covering overlay', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);
    $domains = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::DOMAINS)
        ->firstOrFail();
    $company->saveCustomFieldValue($domains, ['google.com']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->click('[data-inline-field="domains"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="domains"] .fi-inline-field-editor')
        ->assertVisible('[data-inline-field="domains"] .fi-in-entry-label');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-inline-field="domains"] button[aria-haspopup="dialog"]');
            if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
                trigger.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
            }
            return true;
        })();
    JS);

    $page->assertVisible('.fi-fo-multi-value-panel');

    $overlay = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail');
            const wrp = document.querySelector('[data-inline-field="domains"] .fi-input-wrp');
            const trigger = document.querySelector('[data-inline-field="domains"] button[aria-haspopup="dialog"]');
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            if (! rail || ! wrp || ! trigger || ! panel) {
                return { ok: false };
            }

            const railBox = rail.getBoundingClientRect();
            const wrpBox = wrp.getBoundingClientRect();
            const panelBox = panel.getBoundingClientRect();
            const wrpStyle = getComputedStyle(wrp);
            const triggerStyle = getComputedStyle(trigger);
            const addInput = panel.querySelector('input[placeholder]');
            const search = panel.querySelector('.fi-select-input-search-ctn, input[placeholder*="Search"]');

            return {
                ok: true,
                panelWidth: panelBox.width,
                extendsPastRail: panelBox.right > railBox.right + 8,
                coversTrigger: panelBox.top <= wrpBox.top + 12,
                triggerHidden: Number.parseFloat(triggerStyle.opacity) === 0,
                hasTriggerRing: wrpStyle.boxShadow.includes('1px'),
                addPlaceholder: addInput?.getAttribute('placeholder') ?? '',
                hasSearch: search !== null,
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['panelWidth'])->toBeGreaterThanOrEqual(300)
        ->and($overlay['extendsPastRail'])->toBeTrue()
        ->and($overlay['coversTrigger'])->toBeTrue()
        ->and($overlay['triggerHidden'])->toBeTrue()
        ->and($overlay['hasTriggerRing'])->toBeFalse()
        ->and($overlay['addPlaceholder'])->toBe(__('filament/inline-edit.add_domain').'...')
        ->and($overlay['hasSearch'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('opens the company picker as a floating search overlay', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Escrow',
    ]);
    Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
        'company_id' => $company->getKey(),
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="company_id"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="company_id"] .fi-inline-field-editor')
        ->assertVisible('.fi-select-input-search-ctn input')
        ->assertAttribute('.fi-select-input-search-ctn input', 'placeholder', __('filament/inline-edit.search_records'));

    $overlay = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail');
            const panel = document.querySelector('[data-inline-field="company_id"] .fi-dropdown-panel');
            const wrp = document.querySelector('[data-inline-field="company_id"] .fi-input-wrp');
            if (! rail || ! panel || ! wrp || panel.style.display === 'none') {
                return { ok: false };
            }

            const railBox = rail.getBoundingClientRect();
            const panelBox = panel.getBoundingClientRect();
            const btn = document.querySelector('[data-inline-field="company_id"] .fi-select-input-btn');
            const wrpStyle = getComputedStyle(wrp);
            const btnStyle = btn ? getComputedStyle(btn) : null;
            const wrpBox = wrp.getBoundingClientRect();

            const clear = document.querySelector('[data-inline-field="company_id"] .fi-select-input-value-remove-btn');

            return {
                ok: true,
                panelWidth: panelBox.width,
                extendsPastRail: panelBox.right > railBox.right + 8,
                coversTrigger: panelBox.top <= wrpBox.top + 12,
                triggerHidden: btnStyle !== null && Number.parseFloat(btnStyle.opacity) === 0,
                hasTriggerRing: wrpStyle.boxShadow.includes('1px'),
                clearHidden: clear === null || getComputedStyle(clear).display === 'none',
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['panelWidth'])->toBeGreaterThanOrEqual(300)
        ->and($overlay['extendsPastRail'])->toBeTrue()
        ->and($overlay['coversTrigger'])->toBeTrue()
        ->and($overlay['triggerHidden'])->toBeTrue()
        ->and($overlay['hasTriggerRing'])->toBeFalse()
        ->and($overlay['clearHidden'])->toBeTrue();

    $showsPickedName = $page->script(<<<'JS'
        (async () => {
            const option = [...document.querySelectorAll('[data-inline-field="company_id"] .fi-select-input-option')]
                .find((el) => (el.textContent || '').includes('Northwind'));
            option?.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
            await Promise.resolve();
            await Promise.resolve();
            const field = document.querySelector('[data-inline-field="company_id"]');

            return Boolean(field?.innerText.includes('Northwind'));
        })();
    JS);

    expect($showsPickedName)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('opens the account owner picker as a floating search overlay', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
        'account_owner_id' => $user->id,
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->click('[data-inline-field="account_owner_id"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="account_owner_id"] .fi-inline-field-editor')
        ->assertVisible('[data-inline-field="account_owner_id"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="account_owner_id"] .fi-select-input-search-ctn input')
        ->assertAttribute(
            '[data-inline-field="account_owner_id"] .fi-select-input-search-ctn input',
            'placeholder',
            __('filament/inline-edit.search_records'),
        );

    $overlay = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail');
            const panel = document.querySelector('[data-inline-field="account_owner_id"] .fi-dropdown-panel');
            const wrp = document.querySelector('[data-inline-field="account_owner_id"] .fi-input-wrp');
            if (! rail || ! panel || ! wrp || panel.style.display === 'none') {
                return { ok: false };
            }

            const railBox = rail.getBoundingClientRect();
            const panelBox = panel.getBoundingClientRect();
            const btn = document.querySelector('[data-inline-field="account_owner_id"] .fi-select-input-btn');
            const wrpStyle = getComputedStyle(wrp);
            const btnStyle = btn ? getComputedStyle(btn) : null;
            const wrpBox = wrp.getBoundingClientRect();
            const clear = document.querySelector('[data-inline-field="account_owner_id"] .fi-select-input-value-remove-btn');

            return {
                ok: true,
                panelWidth: panelBox.width,
                extendsPastRail: panelBox.right > railBox.right + 8,
                coversTrigger: panelBox.top <= wrpBox.top + 12,
                triggerHidden: btnStyle !== null && Number.parseFloat(btnStyle.opacity) === 0,
                hasTriggerRing: wrpStyle.boxShadow.includes('1px'),
                clearHidden: clear === null || getComputedStyle(clear).display === 'none',
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['panelWidth'])->toBeGreaterThanOrEqual(300)
        ->and($overlay['extendsPastRail'])->toBeTrue()
        ->and($overlay['coversTrigger'])->toBeTrue()
        ->and($overlay['triggerHidden'])->toBeTrue()
        ->and($overlay['hasTriggerRing'])->toBeFalse()
        ->and($overlay['clearHidden'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('opens a textarea at field size then grows over following rows up to a max height', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->firstOrFail()
        ->getAttribute('custom_field_section_id');
    CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'bio',
        'name' => 'Bio',
        'type' => CustomFieldType::TEXTAREA->value,
        'active' => true,
        'sort_order' => 0,
        'validation_rules' => [],
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}");

    $before = $page->script(<<<'JS'
        (() => {
            const field = document.querySelector('[data-inline-field="bio"]');
            const label = field?.querySelector('.fi-in-entry-label');
            const content = field?.querySelector('.fi-in-entry-content');

            return {
                labelTop: label?.getBoundingClientRect().top ?? null,
                contentHeight: content?.getBoundingClientRect().height ?? 0,
            };
        })();
    JS);

    $page->click('[data-inline-field="bio"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="bio"] .fi-inline-field-editor textarea')
        ->assertVisible('[data-inline-field="bio"] .fi-in-entry-label')
        ->assertMissing('.fi-inline-textarea-panel');

    $empty = $page->script(<<<'JS'
        (() => {
            const wrp = document.querySelector('[data-inline-field="bio"] .fi-input-wrp');
            const textarea = document.querySelector('[data-inline-field="bio"] textarea');
            if (! wrp || ! textarea) {
                return { ok: false };
            }

            const wrpStyle = getComputedStyle(wrp);
            const ring = `${wrpStyle.boxShadow} ${wrpStyle.getPropertyValue('--tw-ring-color')}`;

            const field = document.querySelector('[data-inline-field="bio"]');
            const label = field?.querySelector('.fi-in-entry-label');
            const taStyle = getComputedStyle(textarea);

            return {
                ok: true,
                height: wrp.getBoundingClientRect().height,
                fieldHeight: field?.getBoundingClientRect().height ?? 0,
                labelTop: label?.getBoundingClientRect().top ?? null,
                padTop: Number.parseFloat(taStyle.paddingTop),
                coversNext: (() => {
                    const next = field?.nextElementSibling?.getBoundingClientRect();
                    const box = wrp.getBoundingClientRect();

                    return Boolean(next && box.bottom > next.top + 4);
                })(),
                hasPrimaryRing: /293|0\.247|7c3aed|8b5cf6/i.test(ring),
                hasDropShadow: wrpStyle.boxShadow.includes('10px') || wrpStyle.boxShadow.includes('28px'),
                radius: Number.parseFloat(wrpStyle.borderRadius),
            };
        })();
    JS);

    expect($empty['ok'])->toBeTrue()
        ->and($empty['height'])->toBeGreaterThanOrEqual(30)
        ->and($empty['height'])->toBeLessThanOrEqual(34)
        ->and(abs($empty['height'] - $before['contentHeight']))->toBeLessThan(2)
        ->and($empty['fieldHeight'])->toBeLessThanOrEqual(34)
        ->and(abs($empty['labelTop'] - $before['labelTop']))->toBeLessThan(2)
        ->and($empty['padTop'])->toBeGreaterThanOrEqual(5)
        ->and($empty['padTop'])->toBeLessThanOrEqual(7)
        ->and($empty['coversNext'])->toBeFalse()
        ->and($empty['hasPrimaryRing'])->toBeTrue()
        ->and($empty['hasDropShadow'])->toBeFalse()
        ->and($empty['radius'])->toBeGreaterThanOrEqual(8)
        ->and($empty['radius'])->toBeLessThanOrEqual(12);

    $grown = $page->script(<<<'JS'
        (() => {
            const editor = document.querySelector('[data-inline-field="bio"] .fi-inline-field-editor');
            const textarea = editor?.querySelector('textarea');
            const wrp = editor?.querySelector('.fi-input-wrp');
            if (! editor || ! textarea || ! wrp || ! window.Alpine) {
                return { ok: false };
            }

            textarea.value = "line one\nline two\nline three\nline four\nline five";
            Alpine.$data(editor).growTextarea();

            const field = document.querySelector('[data-inline-field="bio"]');
            const next = field?.nextElementSibling;
            const wrpBox = wrp.getBoundingClientRect();
            const nextBox = next?.getBoundingClientRect();
            const fieldBox = field?.getBoundingClientRect();

            textarea.value = Array.from({ length: 20 }, (_, index) => `line ${index}`).join('\n');
            Alpine.$data(editor).growTextarea();
            const capped = wrp.getBoundingClientRect();

            return {
                ok: true,
                height: wrpBox.height,
                fieldHeight: fieldBox?.height ?? 0,
                coversNext: Boolean(nextBox && wrpBox.bottom > nextBox.top + 4),
                cappedHeight: capped.height,
                overflow: getComputedStyle(textarea).overflowY,
            };
        })();
    JS);

    expect($grown['ok'])->toBeTrue()
        ->and($grown['height'])->toBeGreaterThan(40)
        ->and($grown['height'])->toBeLessThan(136)
        ->and($grown['fieldHeight'])->toBeLessThanOrEqual(40)
        ->and($grown['coversNext'])->toBeTrue()
        ->and($grown['cappedHeight'])->toBeGreaterThanOrEqual(120)
        ->and($grown['cappedHeight'])->toBeLessThanOrEqual(136)
        ->and($grown['overflow'])->toBe('auto');

    $jsErrors = $page->script(<<<'JS'
        (window.__pestBrowser?.jsErrors || []).filter((error) => {
            const message = typeof error === 'string' ? error : String(error?.message ?? '');

            return ! message.includes('ResizeObserver loop');
        });
    JS);

    expect($jsErrors)->toBeEmpty();
});

it('keeps compact field labels vertically aligned while editing', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();
    $person->saveCustomFieldValue($linkedin, ['www.linkedin.com/in/ada']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace');

    $before = $page->script(<<<'JS'
        document.querySelector('[data-inline-field="linkedin"] .fi-in-entry-label')?.getBoundingClientRect().top ?? null
    JS);

    $page->click('[data-inline-field="linkedin"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="linkedin"] .fi-inline-field-editor');

    $after = $page->script(<<<'JS'
        (() => {
            const label = document.querySelector('[data-inline-field="linkedin"] .fi-in-entry-label');
            const wrp = document.querySelector('[data-inline-field="linkedin"] .fi-input-wrp');
            const input = document.querySelector('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input');
            const shadow = wrp ? getComputedStyle(wrp).boxShadow : '';
            const ring = wrp ? getComputedStyle(wrp).getPropertyValue('--tw-ring-color') : '';
            const inputStyle = input ? getComputedStyle(input) : null;

            return {
                top: label?.getBoundingClientRect().top ?? null,
                hasInputBorder: shadow !== 'none' && shadow !== '',
                hasPrimaryRing: /293|0\.247|7c3aed|8b5cf6|124,\s*58,\s*237|139,\s*92,\s*246/i.test(`${shadow} ${ring}`),
                isGrayFill: wrp ? /243,\s*244,\s*246|0\.967/.test(getComputedStyle(wrp).backgroundColor) : false,
                urlLooksLikeLink: Boolean(inputStyle && (inputStyle.textDecorationLine.includes('underline') || /124,\s*58,\s*237|139,\s*92,\s*246/.test(inputStyle.color))),
                wrpHeight: wrp ? wrp.getBoundingClientRect().height : 0,
                inputPad: inputStyle ? Number.parseFloat(inputStyle.paddingInlineStart) : 0,
            };
        })();
    JS);

    expect($before)->toBeNumeric()
        ->and($after['top'])->toBeNumeric()
        ->and(abs($after['top'] - $before))->toBeLessThan(2)
        ->and($after['hasInputBorder'])->toBeTrue()
        ->and($after['hasPrimaryRing'])->toBeTrue()
        ->and($after['isGrayFill'])->toBeFalse()
        ->and($after['urlLooksLikeLink'])->toBeFalse()
        ->and($after['wrpHeight'])->toBeGreaterThanOrEqual(30)
        ->and($after['wrpHeight'])->toBeLessThanOrEqual(34)
        ->and($after['inputPad'])->toBeGreaterThanOrEqual(6)
        ->and($after['inputPad'])->toBeLessThanOrEqual(10);

    $page->assertNoJavaScriptErrors();
});

it('opens the company from the chip and edits from the rest of the field', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Escrow',
    ]);
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
        'company_id' => $company->getKey(),
    ]);

    $personUrl = "/app/{$workspace->slug}/people/{$person->getKey()}";
    $companyUrl = "/app/{$workspace->slug}/companies/{$company->getKey()}";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate($personUrl)
        ->assertSee('Escrow')
        ->click('[data-inline-field="company_id"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="company_id"] .fi-inline-field-editor')
        ->assertPathIs($personUrl)
        ->assertNoJavaScriptErrors();

    $page->navigate($personUrl)
        ->click('[data-inline-field="company_id"] a.fi-record-chip')
        ->assertPathIs($companyUrl)
        ->assertNoJavaScriptErrors();
});

it('opens a person phone editor as a covering overlay', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="phone_number"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->assertVisible('[data-inline-field="phone_number"] .fi-in-entry-label');

    $overlay = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail');
            const wrp = document.querySelector('[data-inline-field="phone_number"] .fi-fo-phone-input');
            const tel = document.querySelector('[data-inline-field="phone_number"] input[type=tel]');
            if (! rail || ! wrp || ! tel) {
                return { ok: false };
            }

            const railBox = rail.getBoundingClientRect();
            const wrpBox = wrp.getBoundingClientRect();
            const wrpStyle = getComputedStyle(wrp);
            const radius = Number.parseFloat(wrpStyle.borderRadius);

            return {
                ok: true,
                wrpWidth: wrpBox.width,
                extendsPastRail: wrpBox.right > railBox.right + 8,
                coversField: wrpBox.top <= rail.querySelector('[data-inline-field="phone_number"]').getBoundingClientRect().top + 12,
                hasTriggerRing: /293|0\.247|7c3aed|8b5cf6|124,\s*58,\s*237|139,\s*92,\s*246/i.test(`${wrpStyle.boxShadow} ${wrpStyle.getPropertyValue('--tw-ring-color')}`),
                fieldRadius: radius,
                overlayShadow: wrpStyle.boxShadow.includes('10px') && ! wrpStyle.boxShadow.includes('32px'),
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['wrpWidth'])->toBeGreaterThanOrEqual(240)
        ->and($overlay['extendsPastRail'])->toBeTrue()
        ->and($overlay['coversField'])->toBeTrue()
        ->and($overlay['hasTriggerRing'])->toBeFalse()
        ->and($overlay['fieldRadius'])->toBeGreaterThanOrEqual(8)
        ->and($overlay['fieldRadius'])->toBeLessThan(14)
        ->and($overlay['overlayShadow'])->toBeTrue();

    $page->click('[data-inline-field="phone_number"] button[role="combobox"]')
        ->assertVisible('.fi-fo-phone-country-panel');

    $country = $page->script(<<<'JS'
        (() => {
            const wrp = document.querySelector('[data-inline-field="phone_number"] .fi-fo-phone-input');
            const panel = document.querySelector('.fi-fo-phone-country-panel');
            const selected = document.querySelector('.fi-fo-phone-country-panel [aria-selected="true"] button');
            if (! wrp || ! panel) {
                return { ok: false };
            }

            const wrpBox = wrp.getBoundingClientRect();
            const panelBox = panel.getBoundingClientRect();
            const panelStyle = getComputedStyle(panel);
            const selectedStyle = selected ? getComputedStyle(selected) : null;

            return {
                ok: true,
                fieldVisible: wrpBox.height >= 28,
                panelBelowField: panelBox.top >= wrpBox.bottom - 4,
                panelRadius: Number.parseFloat(panelStyle.borderRadius),
                fieldDropShadow: getComputedStyle(wrp).boxShadow.includes('10px') && ! getComputedStyle(wrp).boxShadow.includes('32px'),
                panelDropShadow: panelStyle.boxShadow.includes('10px') && ! panelStyle.boxShadow.includes('32px'),
                selectedPurple: selectedStyle !== null && /124,\s*58,\s*237|139,\s*92,\s*246|167,\s*139,\s*250/.test(selectedStyle.backgroundColor + selectedStyle.color),
            };
        })();
    JS);

    expect($country['ok'])->toBeTrue()
        ->and($country['fieldVisible'])->toBeTrue()
        ->and($country['panelBelowField'])->toBeTrue()
        ->and($country['panelRadius'])->toBeGreaterThanOrEqual(6)
        ->and($country['panelRadius'])->toBeLessThan(12)
        ->and($country['fieldDropShadow'])->toBeTrue()
        ->and($country['panelDropShadow'])->toBeTrue()
        ->and($country['selectedPurple'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('keeps the person phone overlay after a failed enter save', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="phone_number"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->clear('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->type('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]', 'not-a-phone')
        ->keys('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]', 'Enter')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor-invalid input[type=tel]');

    $overlay = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail');
            const wrp = document.querySelector('[data-inline-field="phone_number"] .fi-fo-phone-input');
            const tel = document.querySelector('[data-inline-field="phone_number"] input[type=tel]');
            if (! rail || ! wrp || ! tel) {
                return { ok: false };
            }

            const railBox = rail.getBoundingClientRect();
            const wrpBox = wrp.getBoundingClientRect();
            const wrpStyle = getComputedStyle(wrp);

            return {
                ok: true,
                value: tel.value,
                wrpWidth: wrpBox.width,
                extendsPastRail: wrpBox.right > railBox.right + 8,
                coversField: wrpBox.top <= rail.querySelector('[data-inline-field="phone_number"]').getBoundingClientRect().top + 12,
                fieldRadius: Number.parseFloat(wrpStyle.borderRadius),
                overlayShadow: wrpStyle.boxShadow.includes('10px') && ! wrpStyle.boxShadow.includes('32px'),
                hasTriggerRing: /293|0\.247|7c3aed|8b5cf6|124,\s*58,\s*237|139,\s*92,\s*246/i.test(`${wrpStyle.boxShadow} ${wrpStyle.getPropertyValue('--tw-ring-color')}`),
            };
        })();
    JS);

    expect($overlay['ok'])->toBeTrue()
        ->and($overlay['value'])->toBe('not-a-phone')
        ->and($overlay['wrpWidth'])->toBeGreaterThanOrEqual(240)
        ->and($overlay['extendsPastRail'])->toBeTrue()
        ->and($overlay['coversField'])->toBeTrue()
        ->and($overlay['fieldRadius'])->toBeGreaterThanOrEqual(8)
        ->and($overlay['overlayShadow'])->toBeTrue()
        ->and($overlay['hasTriggerRing'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('closes an invalid person phone editor on click outside without saving', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="phone_number"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->clear('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->type('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]', 'not-a-phone')
        ->keys('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]', 'Enter')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor-invalid input[type=tel]');

    $page->script(<<<'JS'
        (() => {
            const wrp = document.querySelector('[data-inline-field="phone_number"] .fi-input-wrp.fi-fo-phone-input');
            if (! wrp) {
                return;
            }
            wrp.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window }));
        })();
    JS);

    $page->assertMissing('[data-inline-field="phone_number"] .fi-inline-field-editor')
        ->assertSee('+14155550103');

    $page->assertNoJavaScriptErrors();
});

it('saves a valid person phone on click outside', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="phone_number"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->clear('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->type('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]', '4155550199')
        ->wait(1);

    $page->script('document.body.click();');

    $page->assertMissing('[data-inline-field="phone_number"] .fi-inline-field-editor')
        ->assertSee('+14155550199');

    $page->assertNoJavaScriptErrors();
});

it('does not save a person phone when only the country is chosen', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $phone = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::PHONE_NUMBER)
        ->firstOrFail();
    $person->saveCustomFieldValue($phone, ['+14155550103']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="phone_number"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->click('[data-inline-field="phone_number"] [role=combobox]')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        document.querySelector('[id*="country-option"][id$="-AM"] button')?.click();
    JS);

    $page->assertVisible('[data-inline-field="phone_number"] .fi-inline-field-editor input[type=tel]')
        ->assertNoJavaScriptErrors();

    $stored = $person->fresh()->customFieldValues()
        ->where('custom_field_id', $phone->getKey())
        ->value($phone->getValueColumn());

    expect($stored instanceof Collection ? $stored->all() : $stored)->toBe(['+14155550103']);
});

it('opens another person field on the first click after a color picker', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->value('custom_field_section_id');
    CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'brand_color',
        'name' => 'Brand color',
        'type' => CustomFieldType::COLOR_PICKER->value,
        'active' => true,
        'validation_rules' => [],
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="brand_color"] .fi-in-entry-content')
        ->assertVisible('[data-inline-field="brand_color"] .fi-inline-field-editor .fi-fo-color-picker')
        ->click('[data-inline-field="job_title"] .fi-in-entry-content')
        ->assertVisible('[data-inline-field="job_title"] .fi-inline-field-editor input.fi-input')
        ->assertMissing('[data-inline-field="brand_color"] .fi-inline-field-editor')
        ->assertNoJavaScriptErrors();
});

it('does not add an invalid email from the inline editor', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $emails = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::EMAILS)
        ->firstOrFail();
    $person->saveCustomFieldValue($emails, ['ada@example.test']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="emails"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="emails"] .fi-inline-field-editor input[inputmode="email"]')
        ->type('[data-inline-field="emails"] .fi-inline-field-editor input[inputmode="email"]', 'asas')
        ->keys('[data-inline-field="emails"] .fi-inline-field-editor input[inputmode="email"]', 'Enter')
        ->assertSee(__('filament/inline-edit.invalid_email'))
        ->assertDontSee('Delete asas')
        ->assertNoJavaScriptErrors();

    $stored = $person->fresh()->customFieldValues()
        ->where('custom_field_id', $emails->getKey())
        ->value($emails->getValueColumn());

    expect($stored instanceof Collection ? $stored->all() : $stored)->toBe(['ada@example.test']);
});

it('toasts an invalid domain then closes on click outside', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Northwind',
    ]);
    $domains = CustomField::query()
        ->forEntity(Company::class)
        ->where('code', CompanyField::DOMAINS)
        ->firstOrFail();
    $company->saveCustomFieldValue($domains, ['google.com']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSee('Northwind')
        ->click('[data-inline-field="domains"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="domains"] .fi-inline-field-editor input[inputmode="url"]')
        ->type('[data-inline-field="domains"] .fi-inline-field-editor input[inputmode="url"]', 'a')
        ->keys('[data-inline-field="domains"] .fi-inline-field-editor input[inputmode="url"]', 'Enter')
        ->assertSee(__('filament/inline-edit.invalid_domain'))
        ->assertVisible('[data-inline-field="domains"] .fi-inline-field-editor')
        ->assertSee('google.com');

    $chrome = $page->script(<<<'JS'
        (() => {
            const input = document.querySelector('[data-inline-field="domains"] .fi-inline-field-editor input[inputmode="url"]');
            const error = document.querySelector('[data-inline-field="domains"] .fi-fo-multi-value-add-error');
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            const inputStyle = input ? getComputedStyle(input) : null;
            const errorStyle = error ? getComputedStyle(error) : null;

            return {
                typedValue: input?.value ?? '',
                typedRed: inputStyle !== null && /oklch\(0\.577|225,\s*29,\s*72|e11d48|fb7185|27\.3/i.test(inputStyle.color),
                inlineErrorHidden: errorStyle === null || errorStyle.display === 'none',
                panelOpen: panel !== null && getComputedStyle(panel).display !== 'none',
            };
        })();
    JS);

    expect($chrome['typedValue'])->toBe('a')
        ->and($chrome['typedRed'])->toBeTrue()
        ->and($chrome['inlineErrorHidden'])->toBeTrue()
        ->and($chrome['panelOpen'])->toBeTrue();

    $page->wait(1)
        ->script(<<<'JS'
            document.body.click();
        JS);

    $page->assertMissing('[data-inline-field="domains"] .fi-inline-field-editor')
        ->assertSee('google.com')
        ->assertSee(__('filament/inline-edit.invalid_domain'))
        ->assertNoJavaScriptErrors();
});

it('toasts an invalid linkedin url then closes on click outside', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();
    $person->saveCustomFieldValue($linkedin, ['www.linkedin.com/in/ada']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="linkedin"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input')
        ->clear('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input')
        ->type('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input', 'asas')
        ->keys('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input', 'Enter')
        ->assertSee(__('filament/inline-edit.invalid_url'))
        ->assertVisible('[data-inline-field="linkedin"] .fi-inline-field-editor-invalid');

    $chrome = $page->script(<<<'JS'
        (() => {
            const wrp = document.querySelector('[data-inline-field="linkedin"] .fi-input-wrp');
            const error = document.querySelector('[data-inline-field="linkedin"] .fi-fo-multi-value-add-error');
            const wrpStyle = wrp ? getComputedStyle(wrp) : null;
            const errorStyle = error ? getComputedStyle(error) : null;
            const ring = wrpStyle ? `${wrpStyle.boxShadow} ${wrpStyle.getPropertyValue('--tw-ring-color')}` : '';

            return {
                inlineErrorHidden: errorStyle === null || errorStyle.display === 'none',
                hasDangerRing: wrpStyle !== null && wrpStyle.boxShadow.includes('1px') && ! /293|0\.247|7c3aed/i.test(ring),
            };
        })();
    JS);

    expect($chrome['inlineErrorHidden'])->toBeTrue()
        ->and($chrome['hasDangerRing'])->toBeTrue();

    $page->script(<<<'JS'
        (() => {
            const wrp = document.querySelector('[data-inline-field="linkedin"] .fi-input-wrp');
            wrp?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window }));
        })();
    JS);

    $page->assertMissing('[data-inline-field="linkedin"] .fi-inline-field-editor')
        ->assertSee('www.linkedin.com/in/ada')
        ->assertSee(__('filament/inline-edit.invalid_url'))
        ->assertNoJavaScriptErrors();
});

it('toasts an invalid linkedin url on click outside without enter', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $linkedin = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::LINKEDIN)
        ->firstOrFail();
    $person->saveCustomFieldValue($linkedin, ['www.linkedin.com/in/ada']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="linkedin"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input')
        ->clear('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input')
        ->type('[data-inline-field="linkedin"] .fi-inline-field-editor input.fi-input', 'asas')
        ->wait(1);

    $page->script(<<<'JS'
        document.body.click();
    JS);

    $page->assertSee(__('filament/inline-edit.invalid_url'))
        ->assertMissing('[data-inline-field="linkedin"] .fi-inline-field-editor')
        ->assertSee('www.linkedin.com/in/ada')
        ->assertNoJavaScriptErrors();
});

it('keeps the tags overlay open after deleting a tag', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->value('custom_field_section_id');
    $hobby = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'hobby',
        'name' => 'Hobby',
        'type' => CustomFieldType::TAGS_INPUT->value,
        'active' => true,
        'validation_rules' => [],
    ]);
    $person->saveCustomFieldValue($hobby, ['alpha', 'beta', 'gamma']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ada Lovelace')
        ->click('[data-inline-field="hobby"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="hobby"] .fi-inline-field-editor')
        ->assertVisible('[data-inline-field="hobby"] input[placeholder="'.__('filament/inline-edit.add_tag').'..."]');

    $page->script(<<<'JS'
        document.querySelector('[data-inline-field="hobby"] [aria-label="Delete alpha"]')?.click();
    JS);

    $page->assertVisible('[data-inline-field="hobby"] .fi-inline-field-editor')
        ->assertVisible('[data-inline-field="hobby"] input[placeholder="'.__('filament/inline-edit.add_tag').'..."]')
        ->assertSee('beta')
        ->assertDontSee('Delete alpha')
        ->assertNoJavaScriptErrors();
});

it('caps the tags overlay height and keeps the add field in view', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->value('custom_field_section_id');
    $hobby = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'hobby',
        'name' => 'Hobby',
        'type' => CustomFieldType::TAGS_INPUT->value,
        'active' => true,
        'validation_rules' => [],
    ]);
    $person->saveCustomFieldValue($hobby, ['s', 'k', 'ds', 'sds', 'dsd', 'sa', 'a', 'sasas', 'more', 'tags']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="hobby"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="hobby"] input[placeholder="'.__('filament/inline-edit.add_tag').'..."]');

    $chrome = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            const add = document.querySelector('[data-inline-field="hobby"] input[placeholder]');
            if (! panel || ! add) {
                return { ok: false };
            }

            const panelBox = panel.getBoundingClientRect();
            const addBox = add.getBoundingClientRect();
            const maxH = Number.parseFloat(getComputedStyle(panel).maxHeight);

            return {
                ok: true,
                height: panelBox.height,
                maxHeight: maxH,
                rowCount: panel.querySelectorAll('.fi-fo-multi-value-panel-row').length,
                addVisible: addBox.bottom <= window.innerHeight && addBox.top >= 0,
                opensUp: getComputedStyle(panel).bottom !== 'auto' && panel.style.top === 'auto',
            };
        })();
    JS);

    expect($chrome['ok'])->toBeTrue()
        ->and($chrome['height'])->toBeGreaterThan(150)
        ->and($chrome['height'])->toBeLessThanOrEqual(208)
        ->and($chrome['maxHeight'])->toBeLessThanOrEqual(208)
        ->and($chrome['addVisible'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('opens the tags overlay tall enough to show every tag before scrolling', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->value('custom_field_section_id');
    $hobby = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'hobby',
        'name' => 'Hobby',
        'type' => CustomFieldType::TAGS_INPUT->value,
        'active' => true,
        'validation_rules' => [],
    ]);
    $person->saveCustomFieldValue($hobby, ['hi', 'how are u', 'tets']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="hobby"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="hobby"] input[placeholder="'.__('filament/inline-edit.add_tag').'..."]');

    $chrome = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-multi-value-panel');
            if (! panel) {
                return { ok: false };
            }

            const rows = [...panel.querySelectorAll('.fi-fo-multi-value-panel-row')];
            const panelBox = panel.getBoundingClientRect();
            const allVisible = rows.every((row) => {
                const box = row.getBoundingClientRect();

                return box.top >= panelBox.top - 1 && box.bottom <= panelBox.bottom + 1;
            });

            return {
                ok: true,
                height: panelBox.height,
                rowCount: rows.length,
                allVisible,
            };
        })();
    JS);

    expect($chrome['ok'])->toBeTrue()
        ->and($chrome['rowCount'])->toBe(3)
        ->and($chrome['allVisible'])->toBeTrue()
        ->and($chrome['height'])->toBeGreaterThan(100)
        ->and($chrome['height'])->toBeLessThan(180);

    $page->assertNoJavaScriptErrors();
});

it('keeps compact right padding on an inline select trigger', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create([
        'name' => 'Enterprise rollout',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/{$opportunity->getKey()}")
        ->click('[data-inline-field="stage"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="stage"] .fi-select-input-btn');

    $chrome = $page->script(<<<'JS'
        (() => {
            const field = document.querySelector('[data-inline-field="stage"]');
            const wrp = field?.querySelector('.fi-input-wrp');
            const btn = field?.querySelector('.fi-select-input-btn');
            if (! wrp || ! btn) {
                return { ok: false };
            }

            const wrpBox = wrp.getBoundingClientRect();
            const colBox = field.querySelector('.fi-in-entry-content-col')?.getBoundingClientRect();

            return {
                ok: true,
                paddingEnd: Number.parseFloat(getComputedStyle(btn).paddingInlineEnd),
                hugsContent: ! colBox || wrpBox.width <= colBox.width - 8,
            };
        })();
    JS);

    expect($chrome['ok'])->toBeTrue()
        ->and($chrome['paddingEnd'])->toBeLessThanOrEqual(10)
        ->and($chrome['hugsContent'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('aligns a custom select placeholder with idle set-field padding', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ada Lovelace',
    ]);
    $sectionId = CustomField::query()
        ->forEntity(People::class)
        ->where('code', PeopleField::JOB_TITLE)
        ->firstOrFail()
        ->getAttribute('custom_field_section_id');
    CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'code',
        'name' => 'code',
        'type' => CustomFieldType::TEXT->value,
        'active' => true,
        'sort_order' => 0,
        'validation_rules' => [],
    ]);
    CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $sectionId,
        'entity_type' => 'people',
        'code' => 'pick',
        'name' => 'pick',
        'type' => CustomFieldType::SELECT->value,
        'active' => true,
        'sort_order' => 1,
        'validation_rules' => [],
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('[data-inline-field="pick"] .fi-in-entry-label')
        ->assertVisible('[data-inline-field="pick"] .fi-select-input-placeholder');

    $chrome = $page->script(<<<'JS'
        (() => {
            const idle = document.querySelector('[data-inline-field="code"] .fi-in-placeholder');
            const wrap = document.querySelector('[data-inline-field="pick"] .fi-select-input div[x-ref="select"]');
            const btn = document.querySelector('[data-inline-field="pick"] .fi-select-input-btn');
            const placeholder = document.querySelector('[data-inline-field="pick"] .fi-select-input-placeholder');
            if (! idle || ! wrap || ! btn || ! placeholder) {
                return { ok: false };
            }

            return {
                ok: true,
                wrapPad: Number.parseFloat(getComputedStyle(wrap).paddingInlineStart),
                btnPad: Number.parseFloat(getComputedStyle(btn).paddingInlineStart),
                shift: Math.abs(placeholder.getBoundingClientRect().left - idle.getBoundingClientRect().left),
            };
        })();
    JS);

    expect($chrome['ok'])->toBeTrue()
        ->and($chrome['wrapPad'])->toBe(0)
        ->and($chrome['btnPad'])->toBeGreaterThanOrEqual(6)
        ->and($chrome['btnPad'])->toBeLessThanOrEqual(10)
        ->and($chrome['shift'])->toBeLessThan(3);

    $page->assertNoJavaScriptErrors();
});

it('opens the close date calendar without extra space under the days', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create([
        'name' => 'Enterprise rollout',
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/{$opportunity->getKey()}")
        ->click('[data-inline-field="close_date"] .fi-in-entry-label')
        ->assertVisible('.fi-fo-date-time-picker-panel');

    $chrome = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-fo-date-time-picker-panel');
            const days = [...(panel?.querySelectorAll('.fi-fo-date-time-picker-calendar-day') ?? [])];
            if (! panel || days.length === 0) {
                return { ok: false, dayCount: days.length };
            }

            const panelBox = panel.getBoundingClientRect();
            const last = days.at(-1).getBoundingClientRect();
            const style = getComputedStyle(panel);

            return {
                ok: true,
                padBottom: Number.parseFloat(style.paddingBottom),
                gapBelowDays: panelBox.bottom - last.bottom,
            };
        })();
    JS);

    expect($chrome['ok'])->toBeTrue()
        ->and($chrome['padBottom'])->toBeLessThanOrEqual(10)
        ->and($chrome['gapBelowDays'])->toBeLessThanOrEqual(16);

    $page->assertNoJavaScriptErrors();
});
