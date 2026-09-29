@php
    /** @var string $formHtml */
    /** @var bool $saveOnEnterOrBlur */
    /** @var bool $saveOnChange */
    /** @var bool $invalid */
    $saveOnChange ??= false;
    $invalid ??= false;
@endphp

<div
    class="fi-inline-field-editor{{ $invalid ? ' fi-inline-field-editor-invalid' : '' }}"
    data-invalid-email="{{ __('filament/inline-edit.invalid_email') }}"
    data-invalid-url="{{ __('filament/inline-edit.invalid_url') }}"
    data-invalid-domain="{{ __('filament/inline-edit.invalid_domain') }}"
    x-data="{
        committing: false,
        cancelled: false,
        pendingSwitch: null,
        blockingSwitch: false,
        lastDraftToastAt: 0,
        discardedInvalidDraft: false,
        holdUntil: 0,
        selectWasOpen: false,
        selectInitialValue: '',
        overlaySelector: '[role=listbox], [role=dialog], [role=option], [role=combobox], .fi-dropdown-panel, .fi-fo-date-time-picker-panel, .fi-fo-color-picker-panel, hex-color-picker, [id*=country-listbox]',
        phoneRoot() {
            const nodes = $el.querySelectorAll('.fi-fo-phone-input [x-data], .fi-fo-phone-input-wrp [x-data]');
            for (let i = 0; i < nodes.length; i++) {
                if (! window.Alpine) {
                    break;
                }
                const data = Alpine.$data(nodes[i]);
                if (data && typeof data.selectCountry === 'function') {
                    return nodes[i];
                }
            }

            return nodes[0] ?? null;
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
        colorPickerRoot() {
            return $el.querySelector('.fi-fo-color-picker [x-data]');
        },
        colorPickerData() {
            const root = this.colorPickerRoot();
            if (! root || ! window.Alpine) {
                return null;
            }
            return Alpine.$data(root);
        },
        isColorPickerOpen() {
            const panel = $el.querySelector('.fi-fo-color-picker-panel');
            if (panel && (panel.style.display === 'block' || getComputedStyle(panel).display === 'block')) {
                return true;
            }
            const data = this.colorPickerData();
            return typeof data?.isOpen === 'function' && data.isOpen();
        },
        isColorPickerUi(node) {
            if (! node || node.nodeType !== 1) {
                return false;
            }
            return Boolean(node.closest?.('.fi-fo-color-picker, .fi-fo-color-picker-panel, hex-color-picker'));
        },
        isColorPickerEvent(event) {
            const nodes = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];
            return nodes.some((node) => this.isColorPickerUi(node));
        },
        countryPanel() {
            const listbox = document.querySelector('[id*=country-listbox]');
            return listbox?.parentElement ?? null;
        },
        isPhoneCountryUi(node) {
            if (! node || node.nodeType !== 1) {
                return false;
            }

            return Boolean(
                node.closest?.('[id*=country-listbox], [id*=country-option], [id*=phone-input-], [role=option], [role=listbox]')
                || this.countryPanel()?.contains(node)
            );
        },
        isPhoneCountryEvent(event) {
            const nodes = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];
            return nodes.some((node) => this.isPhoneCountryUi(node));
        },
        holdOpen(ms) {
            this.holdUntil = Date.now() + ms;
        },
        holdPhoneCountry() {
            this.holdOpen(500);
        },
        isOverlay(node) {
            return Boolean(node?.closest?.(this.overlaySelector)) || this.isPhoneCountryUi(node) || this.isColorPickerUi(node);
        },
        isInlineControl(node) {
            if (! node || node.nodeType !== 1) {
                return false;
            }
            if (this.isPhoneCountryUi(node) || this.isColorPickerUi(node)) {
                return true;
            }

            return Boolean(node.closest?.('input, textarea, select, [role=combobox], [role=searchbox], [role=listbox], [role=option], .fi-fo-phone-country-panel, .fi-fo-phone-country-search, .fi-dropdown-panel, .fi-fo-date-time-picker-panel, .fi-fo-color-picker-panel, hex-color-picker'));
        },
        dismissIfInvalidOutside(event) {
            if (! $el.classList.contains('fi-inline-field-editor-invalid')) {
                return false;
            }
            if (this.isPhoneCountryEvent(event) || this.isInlineControl(event.target)) {
                return false;
            }
            this.closeInvalid();

            return true;
        },
        bindPhoneCountry() {
            const data = this.phoneData();
            if (! data || data._inlinePhoneBound) {
                return;
            }
            data._inlinePhoneBound = true;
            const originalOpen = data.openCountryDropdown;
            if (typeof originalOpen === 'function') {
                data.openCountryDropdown = (...args) => {
                    this.holdPhoneCountry();
                    return originalOpen.apply(data, args);
                };
            }
            const originalSelect = data.selectCountry;
            if (typeof originalSelect === 'function') {
                data.selectCountry = (...args) => {
                    this.holdPhoneCountry();
                    const result = originalSelect.apply(data, args);
                    queueMicrotask(() => {
                        this.phoneTel()?.focus();
                        this.holdPhoneCountry();
                    });

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
                || Date.now() < this.holdUntil
                || this.isPhoneCountryOpen()
                || this.isColorPickerOpen();
        },
        nextEditableCode(node) {
            const next = node?.closest?.('[data-inline-field].fi-inline-editable');
            const current = $el.closest('[data-inline-field]');
            if (! next || ! current || next === current || next.getAttribute('data-inline-editing') === 'true') {
                return null;
            }

            return next.getAttribute('data-inline-field');
        },
        closeFloatingPanels() {
            const color = this.colorPickerData();
            if (color && typeof color.isOpen === 'function' && color.isOpen() && typeof color.togglePanelVisibility === 'function') {
                color.togglePanelVisibility();
            }
            const dateRoot = $el.querySelector('.fi-fo-date-time-picker [x-data]');
            const dateData = dateRoot && window.Alpine ? Alpine.$data(dateRoot) : null;
            if (dateData && typeof dateData.isOpen === 'function' && dateData.isOpen() && typeof dateData.togglePanelVisibility === 'function') {
                dateData.togglePanelVisibility();
            }
            const select = this.selectAlpine();
            if (select && typeof select.closeDropdown === 'function' && this.isSelectOpen()) {
                select.closeDropdown();
            }
            const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
            const multiData = multiValue && window.Alpine ? Alpine.$data(multiValue) : null;
            if (multiData && typeof multiData.closePanel === 'function') {
                multiData.closePanel();
            }
            const phone = this.phoneData();
            if (phone && typeof phone.closeCountryDropdown === 'function' && this.isPhoneCountryOpen()) {
                phone.closeCountryDropdown();
            }
        },
        commitPhoneDraft() {
            const data = this.phoneData();
            const tel = this.phoneTel();
            if (! data || ! tel || typeof data.updateEntry !== 'function') {
                return;
            }
            data.updateEntry(0, 'number', tel.value);
        },
        commitTagsDraft() {
            const root = $el.querySelector('.fi-fo-tags-input');
            if (! root || ! window.Alpine) {
                return;
            }
            const data = Alpine.$data(root);
            if (data && typeof data.createTag === 'function') {
                data.createTag();
            }
        },
        commitDrafts(strict = false) {
            this.commitPhoneDraft();
            this.commitTagsDraft();
            this.discardedInvalidDraft = false;
            const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
            if (! multiValue || ! window.Alpine) {
                return true;
            }
            const data = Alpine.$data(multiValue);
            if (! data) {
                return true;
            }
            if (typeof data.addValue === 'function' && data.newValue?.trim()) {
                if (! data.isValidValue(data.newValue.trim())) {
                    if (strict) {
                        data.addInvalid = true;

                        return false;
                    }
                    this.discardedInvalidDraft = true;
                    data.newValue = '';
                    data.addInvalid = false;
                } else if (data.addValue() === false) {
                    return false;
                }
            }
            if (! data.allowMultiple && typeof data.setSingleValue === 'function') {
                const input = multiValue.querySelector('input:not([type=hidden])');
                if (input && data.setSingleValue(input.value) === false) {
                    return false;
                }
            }

            return true;
        },
        toastDraftInvalid() {
            const now = Date.now();
            if (now - this.lastDraftToastAt < 400) {
                return;
            }
            this.lastDraftToastAt = now;
            const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
            const data = multiValue && window.Alpine ? Alpine.$data(multiValue) : null;
            const message = data?.inputType === 'email'
                ? $el.dataset.invalidEmail
                : data?.inputType === 'url'
                    ? (data.allowMultiple ? $el.dataset.invalidDomain : $el.dataset.invalidUrl)
                    : '';
            if (! message || typeof FilamentNotification !== 'function') {
                return;
            }
            new FilamentNotification().title(message).danger().send();
        },
        closeInvalid() {
            this.toastDraftInvalid();
            this.cancelEdit();
        },
        saveFromEnter() {
            if (this.committing || this.isPhoneCountryOpen()) {
                return;
            }
            const active = document.activeElement;
            if (active?.closest('[role=searchbox], [role=listbox], textarea')) {
                return;
            }
            if (active?.closest('.fi-fo-multi-value-input')) {
                const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
                const data = multiValue && window.Alpine ? Alpine.$data(multiValue) : null;
                if (data?.allowMultiple) {
                    return;
                }
            }
            this.save(true, true);
        },
        save(dismiss = false, fromEnter = false) {
            if (this.committing) {
                return;
            }
            if (! fromEnter && this.shouldHold()) {
                return;
            }
            if (dismiss && ! fromEnter && $el.classList.contains('fi-inline-field-editor-invalid')) {
                this.closeInvalid();
                return;
            }
            if (this.commitDrafts(fromEnter) === false) {
                if (fromEnter) {
                    $el.classList.add('fi-inline-field-editor-invalid');
                    this.toastDraftInvalid();
                    return;
                }
                if (dismiss) {
                    this.closeInvalid();
                }
                return;
            }
            if (dismiss && this.discardedInvalidDraft) {
                this.toastDraftInvalid();
            }
            $el.querySelectorAll('input:not([type=hidden]), textarea').forEach((input) => {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            this.committing = true;
            Promise.resolve($wire.saveInlineField())
                .catch(() => {
                    if (dismiss && ! fromEnter) {
                        this.cancelEdit();
                    }
                })
                .finally(() => {
                    this.committing = false;
                });
        },
        growTextarea() {
            if (this._growingTextarea) {
                return;
            }
            const textarea = $el.querySelector('textarea');
            if (! textarea) {
                return;
            }
            if (textarea.clientWidth < 8) {
                requestAnimationFrame(() => this.growTextarea());
                return;
            }
            this._growingTextarea = true;
            const styles = getComputedStyle(textarea);
            const min = Number.parseFloat(styles.minHeight) || 30;
            const cap = Number.parseFloat(styles.maxHeight) || 128;
            textarea.style.height = `${min}px`;
            const content = textarea.scrollHeight;
            const next = Math.min(Math.max(content, min), cap);
            textarea.style.height = `${next}px`;
            textarea.style.overflowY = content > cap + 1 ? 'auto' : 'hidden';
            const wrp = textarea.closest('.fi-input-wrp');
            if (wrp) {
                wrp.style.height = `${next}px`;
            }
            this._growingTextarea = false;
        },
    }"
    x-on:click.stop
    x-on:input="
        if ($event.target.tagName === 'TEXTAREA') {
            growTextarea();
        }
    "
    x-on:submit.capture.window="
        if (! $el.contains(document.activeElement) && ! ($event.submitter && $el.contains($event.submitter))) {
            return;
        }
        $event.preventDefault();
        $event.stopImmediatePropagation();
    "
    x-on:click.capture.window="
        if (! blockingSwitch) {
            return;
        }
        $event.preventDefault();
        $event.stopImmediatePropagation();
        blockingSwitch = false;
    "
    x-on:mousedown.capture.window="
        if (isPhoneCountryEvent($event)) {
            holdPhoneCountry();
            pendingSwitch = null;
            return;
        }
        if (dismissIfInvalidOutside($event)) {
            pendingSwitch = null;
            return;
        }
        const next = nextEditableCode($event.target);
        if (next) {
            if (commitDrafts() === false) {
                pendingSwitch = next;
                closeInvalid();
                return;
            }
            pendingSwitch = next;
            closeFloatingPanels();
            return;
        }
        pendingSwitch = null;
    "
    @if (filled($formHtml))
        x-on:keydown.escape.window="
            const picker = $el.querySelector('.fi-fo-date-time-picker [x-data]');
            const pickerData = picker && window.Alpine ? Alpine.$data(picker) : null;
            if (pickerData && typeof pickerData.isOpen === 'function' && pickerData.isOpen()) {
                pickerData.togglePanelVisibility();
                return;
            }
            const color = colorPickerData();
            if (color && typeof color.isOpen === 'function' && color.isOpen()) {
                color.togglePanelVisibility();
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
            x-on:keydown.enter.capture="
                if (isPhoneCountryOpen() || $event.target.closest('[role=searchbox], [role=listbox], textarea')) {
                    return;
                }
                if ($event.target.closest('.fi-fo-multi-value-input')) {
                    const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
                    const data = multiValue && window.Alpine ? Alpine.$data(multiValue) : null;
                    if (data?.allowMultiple) {
                        return;
                    }
                }
                $event.preventDefault();
                saveFromEnter();
            "
            x-on:focusout="
                if (pendingSwitch || isPhoneCountryOpen() || isColorPickerOpen() || isColorPickerUi($event.relatedTarget)) {
                    return;
                }
                if (! $event.relatedTarget && $event.target?.closest?.('.fi-fo-multi-value-panel, .fi-fo-multi-value-input')) {
                    return;
                }
                const next = $event.relatedTarget;
                if ($el.contains(next) || isOverlay(next) || isPhoneCountryUi(next) || nextEditableCode(next)) {
                    return;
                }
                if ($el.classList.contains('fi-inline-field-editor-invalid')) {
                    closeInvalid();
                    return;
                }
                if (shouldHold()) {
                    return;
                }
                save(true);
            "
            x-on:click.outside="
                if (! $event.target?.isConnected) {
                    return;
                }
                if (pendingSwitch || nextEditableCode($event.target) || isOverlay($event.target) || isPhoneCountryEvent($event) || isColorPickerEvent($event)) {
                    return;
                }
                if ($el.classList.contains('fi-inline-field-editor-invalid')) {
                    closeInvalid();
                    return;
                }
                if (shouldHold()) {
                    return;
                }
                save(true);
            "
        @elseif ($saveOnChange)
            x-on:click.outside="
                if (! $event.target?.isConnected) {
                    return;
                }
                if (pendingSwitch || nextEditableCode($event.target) || isOverlay($event.target) || isPhoneCountryEvent($event)) {
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
                const textarea = $el.querySelector('textarea');
                if (textarea) {
                    growTextarea();
                    new ResizeObserver(() => growTextarea()).observe(textarea);
                }
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

                if ($el.querySelector('.fi-fo-color-picker')) {
                    return;
                }

                const multiValue = $el.querySelector('.fi-fo-multi-value-input [x-data]');
                if (multiValue && window.Alpine) {
                    const data = Alpine.$data(multiValue);
                    if (data) {
                        data.maxVisibleValues = 1;
                    }
                    if (data && typeof data.openPanel === 'function') {
                        holdOpen(300);
                        data.openPanel();
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
                    if (input.tagName === 'TEXTAREA') {
                        growTextarea();
                        requestAnimationFrame(() => growTextarea());
                    }
                    if (typeof input.showPicker === 'function') {
                        try { input.showPicker(); } catch (e) {}
                    }
                    return;
                }

                if (tries < 20) {
                    setTimeout(() => activate(tries + 1), 16);
                }
            };

            holdOpen(400);
            $nextTick(() => activate());
        "
    @endif
>
    <div class="fi-inline-field-editor-main">
        <form
            class="fi-inline-field-editor-form"
            novalidate
            x-on:submit.prevent="saveFromEnter()"
        >
            {!! $formHtml !!}
        </form>
    </div>
</div>
