@php
    /** @var string $formHtml */
    /** @var string|null $error */
    /** @var bool $saveOnEnterOrBlur */
    /** @var bool $saveOnChange */
    /** @var bool $saveOnConfirm */
    $saveOnChange ??= false;
@endphp

<div
    class="fi-inline-field-editor"
    x-data="{
        committing: false,
        cancelled: false,
        selectWasOpen: false,
        selectInitialValue: '',
        overlaySelector: '[role=listbox], [role=dialog], [role=option], [role=combobox], .fi-dropdown-panel, .fi-fo-date-time-picker-panel, [id*=country-listbox]',
        phoneRoot() {
            return $el.querySelector('.fi-fo-phone-input [x-data], .fi-fo-phone-input-wrp [x-data]');
        },
        phoneData() {
            const root = this.phoneRoot();
            if (! root || ! window.Alpine) {
                return null;
            }
            return Alpine.$data(root);
        },
        phoneTel() {
            return $el.querySelector('.fi-fo-phone-input input[type=tel]');
        },
        isPhoneCountryOpen() {
            const data = this.phoneData();
            return data?.activeCountryDropdown !== null && data?.activeCountryDropdown !== undefined;
        },
        countryPanel() {
            const listbox = document.querySelector('[id*=country-listbox]');
            return listbox?.parentElement ?? null;
        },
        isPhoneCountryUi(node) {
            if (! node || node.nodeType !== 1) {
                return false;
            }
            if (node.closest?.('[id*=country-listbox], [id*=country-option], [role=option], [role=listbox], [role=searchbox]')) {
                const panel = this.countryPanel();
                if (panel?.contains(node)) {
                    return true;
                }
                return Boolean(node.closest?.('[id*=country-listbox], [id*=country-option]'));
            }
            return Boolean(this.countryPanel()?.contains(node));
        },
        isPhoneCountryEvent(event) {
            const nodes = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];
            return nodes.some((node) => this.isPhoneCountryUi(node));
        },
        isOverlay(node) {
            return Boolean(node?.closest?.(this.overlaySelector)) || this.isPhoneCountryUi(node);
        },
        bindPhoneCountry() {
            const data = this.phoneData();
            if (! data || data._inlinePhoneBound) {
                return;
            }
            data._inlinePhoneBound = true;
            const originalSelect = data.selectCountry;
            if (typeof originalSelect === 'function') {
                data.selectCountry = (...args) => {
                    const result = originalSelect.apply(data, args);
                    queueMicrotask(() => this.phoneTel()?.focus());

                    return result;
                };
            }
        },
        selectButton() {
            return $el.querySelector('.fi-select-input-btn');
        },
        selectAlpine() {
            const btn = this.selectButton();
            const root = btn?.closest('[x-data]');
            if (! root || ! window.Alpine) {
                return null;
            }
            const data = Alpine.$data(root);

            return data?.select ?? null;
        },
        isSelectOpen() {
            return this.selectButton()?.getAttribute('aria-expanded') === 'true';
        },
        selectValue() {
            const state = this.selectAlpine()?.state;
            if (state === null || state === undefined) {
                return '';
            }

            return String(state);
        },
        cancelEdit() {
            if (this.cancelled) {
                return;
            }
            this.cancelled = true;
            document.activeElement?.blur?.();
            const entry = $el.closest('[data-inline-field]');
            if (entry) {
                entry.dataset.inlineEditing = 'false';
            }
            $el.hidden = true;
            $wire.cancelInlineEdit();
        },
        dismissSelect() {
            if (this.cancelled || this.committing || this.isSelectOpen() || ! this.selectWasOpen) {
                return;
            }
            if (this.selectValue() !== this.selectInitialValue) {
                return;
            }
            this.cancelEdit();
        },
        bindSelectDismiss() {
            const btn = this.selectButton();
            const select = this.selectAlpine();
            if (! btn || ! select || typeof select.closeDropdown !== 'function') {
                return false;
            }
            if (btn.dataset.inlineSelectBound === 'true') {
                return true;
            }
            btn.dataset.inlineSelectBound = 'true';
            this.selectInitialValue = this.selectValue();
            if (this.isSelectOpen()) {
                this.selectWasOpen = true;
            }
            const observer = new MutationObserver(() => {
                if (this.isSelectOpen()) {
                    this.selectWasOpen = true;
                    return;
                }
                setTimeout(() => this.dismissSelect(), 50);
            });
            observer.observe(btn, { attributes: true, attributeFilter: ['aria-expanded'] });
            return true;
        },
        shouldHold() {
            return this.committing
                || $el.dataset.inlineOpening === 'true'
                || this.isPhoneCountryOpen();
        },
        commitPhoneDraft() {
            const data = this.phoneData();
            const tel = this.phoneTel();
            if (! data || ! tel || typeof data.updateEntry !== 'function') {
                return;
            }
            data.updateEntry(0, 'number', tel.value);
        },
        commitDrafts() {
            this.commitPhoneDraft();
            const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
            if (! multiValue || ! window.Alpine) {
                return;
            }
            const data = Alpine.$data(multiValue);
            if (! data) {
                return;
            }
            if (typeof data.addValue === 'function' && data.newValue?.trim()) {
                data.addValue();
            }
            if (! data.allowMultiple && typeof data.setSingleValue === 'function') {
                const input = multiValue.querySelector('input:not([type=hidden])');
                if (input) {
                    data.setSingleValue(input.value);
                }
            }
        },
        save() {
            if (this.shouldHold()) {
                return;
            }
            this.commitDrafts();
            this.committing = true;
            Promise.resolve($wire.saveInlineField())
                .catch(() => {})
                .finally(() => {
                    this.committing = false;
                });
        },
    }"
    x-on:click.stop
    @if (filled($formHtml))
        x-on:keydown.escape.window="
            const picker = $el.querySelector('.fi-fo-date-time-picker [x-data]');
            const pickerData = picker && window.Alpine ? Alpine.$data(picker) : null;
            if (pickerData && typeof pickerData.isOpen === 'function' && pickerData.isOpen()) {
                pickerData.togglePanelVisibility();
                return;
            }
            const phone = phoneData();
            if (phone && phone.activeCountryDropdown !== null && phone.activeCountryDropdown !== undefined) {
                phone.closeCountryDropdown();
                return;
            }
            cancelEdit();
        "
        @if ($saveOnEnterOrBlur)
            x-on:keydown.enter="
                if (isPhoneCountryOpen() || $event.target.closest('[role=searchbox], [role=listbox]')) {
                    return;
                }
                if ($event.target.closest('.fi-fo-multi-value-input')) {
                    const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
                    const data = multiValue && window.Alpine ? Alpine.$data(multiValue) : null;
                    if (data?.allowMultiple) {
                        return;
                    }
                }
                save();
            "
            x-on:focusout="
                if (shouldHold()) {
                    return;
                }
                const next = $event.relatedTarget;
                if ($el.contains(next) || isOverlay(next) || isPhoneCountryUi(next)) {
                    return;
                }
                save();
            "
            x-on:click.outside="
                if (shouldHold() || isOverlay($event.target) || isPhoneCountryEvent($event)) {
                    return;
                }
                save();
            "
        @elseif ($saveOnChange)
            x-on:click.outside="
                if (isOverlay($event.target) || isPhoneCountryEvent($event)) {
                    return;
                }
                if ($event.target.closest('[data-inline-field]')) {
                    return;
                }
                cancelEdit();
            "
        @endif
        x-init="
            bindPhoneCountry();
            const bindSelect = (tries = 0) => {
                if (bindSelectDismiss()) {
                    return;
                }
                if (tries < 20) {
                    setTimeout(() => bindSelect(tries + 1), 16);
                }
            };
            bindSelect();
            $nextTick(() => {
                bindPhoneCountry();
                bindSelect();
            });

            const activate = (tries = 0) => {
                const picker = $el.querySelector('.fi-fo-date-time-picker [x-data]');
                if (picker && window.Alpine) {
                    const data = Alpine.$data(picker);
                    if (data && typeof data.togglePanelVisibility === 'function') {
                        if (typeof data.isOpen !== 'function' || ! data.isOpen()) {
                            data.togglePanelVisibility();
                        }
                        picker.querySelector('input')?.focus();
                        return;
                    }
                }

                const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
                if (multiValue && window.Alpine) {
                    const data = Alpine.$data(multiValue);
                    if (data && typeof data.openPanel === 'function') {
                        if (! data.$refs?.trigger || ! data.$refs?.panel) {
                            if (tries < 20) {
                                setTimeout(() => activate(tries + 1), 16);
                            }
                            return;
                        }
                        $el.dataset.inlineOpening = 'true';
                        data.openPanel();
                        setTimeout(() => { delete $el.dataset.inlineOpening }, 300);
                        return;
                    }
                }

                const selectBtn = $el.querySelector('.fi-select-input-btn');
                if (selectBtn) {
                    bindSelectDismiss();
                    if (selectBtn.getAttribute('aria-expanded') === 'true') {
                        return;
                    }
                    const selectRoot = selectBtn.closest('[x-data]');
                    const selectData = selectRoot && window.Alpine ? Alpine.$data(selectRoot) : null;
                    const select = selectData?.select ?? selectData;
                    if (select && typeof select.openDropdown === 'function') {
                        if (! select.isOpen) {
                            select.openDropdown();
                        }
                        return;
                    }
                    selectBtn.focus();
                    selectBtn.click();
                    return;
                }

                const phoneRoot = $el.querySelector('.fi-fo-phone-input [x-data], .fi-fo-phone-input-wrp [x-data]');
                if (phoneRoot) {
                    bindPhoneCountry();
                    const tel = $el.querySelector('.fi-fo-phone-input input[type=tel]');
                    if (tel) {
                        tel.focus();
                        return;
                    }
                    if (tries < 20) {
                        setTimeout(() => activate(tries + 1), 16);
                    }
                    return;
                }

                const input = $el.querySelector('input.fi-input:not([type=hidden]):not([readonly]), textarea, select');
                if (input) {
                    input.focus();
                    if (typeof input.showPicker === 'function') {
                        try { input.showPicker(); } catch (e) {}
                    }
                    return;
                }

                if (tries < 20) {
                    setTimeout(() => activate(tries + 1), 16);
                }
            };

            $el.dataset.inlineOpening = 'true';
            setTimeout(() => { delete $el.dataset.inlineOpening }, 400);
            $nextTick(() => activate());
        "
    @endif
>
    <div class="fi-inline-field-editor-main">
        <div class="fi-inline-field-editor-form">
            {!! $formHtml !!}
        </div>

        <p
            class="fi-inline-field-saving"
            role="status"
            wire:loading
            wire:target="saveInlineField"
        >
            {{ __('filament/inline-edit.saving') }}
        </p>
    </div>

    @if (filled($error))
        <p class="fi-fo-field-wrp-error-message fi-inline-field-error" role="alert">{{ $error }}</p>
    @endif

    @if ($saveOnConfirm)
        <div class="fi-inline-field-actions">
            <button
                type="button"
                class="fi-inline-field-done"
                wire:click.stop="saveInlineField"
            >
                {{ __('filament/inline-edit.done') }}
            </button>
            <button
                type="button"
                class="fi-inline-field-cancel"
                wire:click.stop="cancelInlineEdit"
            >
                {{ __('filament/inline-edit.cancel') }}
            </button>
        </div>
    @endif
</div>
