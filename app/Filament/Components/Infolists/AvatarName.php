<?php

declare(strict_types=1);

namespace App\Filament\Components\Infolists;

use Filament\Infolists\Components\Entry;
use Illuminate\Database\Eloquent\Model;

final class AvatarName extends Entry
{
    protected string $view = 'filament.components.infolists.avatar-name';

    private ?string $avatarPath = null;

    private ?string $namePath = null;

    private string $avatarSize = 'md';

    private string $textSize = 'sm';

    private bool $circular = true;

    public function avatar(string $path): static
    {
        $this->avatarPath = $path;

        return $this;
    }

    public function name(string $path): static
    {
        $this->namePath = $path;

        return $this;
    }

    public function avatarSize(string $size): static
    {
        $this->avatarSize = $size;

        return $this;
    }

    public function textSize(string $size): static
    {
        $this->textSize = $size;

        return $this;
    }

    public function square(): static
    {
        $this->circular = false;

        return $this;
    }

    public function circular(): static
    {
        $this->circular = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return [
                'avatar' => null,
                'name' => null,
                'avatarSize' => $this->avatarSize,
                'textSize' => $this->textSize,
                'circular' => $this->circular,
            ];
        }

        $avatarValue = in_array($this->avatarPath, [null, '', '0'], true) ? null : $this->resolvePath($record, $this->avatarPath);
        $nameValue = in_array($this->namePath, [null, '', '0'], true) ? null : $this->resolvePath($record, $this->namePath);

        return [
            'avatar' => $avatarValue,
            'name' => $nameValue,
            'avatarSize' => $this->avatarSize,
            'textSize' => $this->textSize,
            'circular' => $this->circular,
        ];
    }

    private function resolvePath(mixed $record, string $path): mixed
    {
        return $this->evaluate(function () use ($record, $path) {
            $segments = explode('.', $path);
            $value = $record;

            foreach ($segments as $segment) {
                if ($value === null) {
                    break;
                }

                $value = $value->{$segment} ?? null;
            }

            return $value;
        });
    }
}
