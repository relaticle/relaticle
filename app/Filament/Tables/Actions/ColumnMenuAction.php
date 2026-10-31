<?php

declare(strict_types=1);

namespace App\Filament\Tables\Actions;

use App\Enums\ColumnMenuState;
use Filament\Actions\Action;

/**
 * An item of the table header's column menu. The menu is one Alpine component
 * (`tableColumnMenu`) shared by every column, so each item acts on whichever column opened it.
 */
final class ColumnMenuAction extends Action
{
    private string $run = '';

    private ?ColumnMenuState $refocus = null;

    private ?ColumnMenuState $enabledWhen = null;

    private ?string $visibleWhen = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grouped();
        $this->alpineClickHandler(fn (): string => "choose(() => {$this->run}, ".($this->refocus->value ?? 'null').')');
        $this->extraAttributes(fn (): array => array_filter([
            'data-label' => e($this->getLabel()),
            ...$this->disabledAttributes(),
            'x-bind:style' => $this->visibleWhen === null ? null : "{ display: {$this->visibleWhen} ? null : 'none' }",
        ]));
    }

    public function callsWire(string $method, ColumnMenuState ...$arguments): static
    {
        $arguments = array_map(fn (ColumnMenuState $argument): string => $argument->value, [ColumnMenuState::Column, ...$arguments]);
        $this->run = "\$wire.{$method}(".implode(', ', $arguments).')';

        return $this;
    }

    public function mountsAction(string $name): static
    {
        $this->run = "\$wire.mountAction('{$name}', { column })";

        return $this;
    }

    public function refocus(ColumnMenuState $column): static
    {
        $this->refocus = $column;

        return $this;
    }

    public function enabledWhen(ColumnMenuState $state): static
    {
        $this->enabledWhen = $state;

        return $this;
    }

    public function visibleWhen(ColumnMenuState $state): static
    {
        $this->visibleWhen = $state->value;

        return $this;
    }

    public function hiddenWhen(ColumnMenuState $state): static
    {
        $this->visibleWhen = "! {$state->value}";

        return $this;
    }

    public function getVisibleWhen(): ?string
    {
        return $this->visibleWhen;
    }

    /** @return array<string, string> */
    private function disabledAttributes(): array
    {
        if (! $this->enabledWhen instanceof ColumnMenuState) {
            return [];
        }

        $disabled = "! {$this->enabledWhen->value}";

        return [
            'x-bind:disabled' => $disabled,
            'x-bind:aria-disabled' => "({$disabled}).toString()",
            'x-bind:class' => "{ 'fi-disabled': {$disabled} }",
        ];
    }
}
