<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

final readonly class SetupOption
{
    public const string KIND_PROMPT = 'prompt';

    public const string KIND_LINK = 'link';

    /** @var list<string> */
    public const array ICONS = [
        'heroicon-o-flag',
        'heroicon-o-clock',
        'heroicon-o-building-office-2',
        'heroicon-o-briefcase',
        'heroicon-o-envelope',
        'heroicon-o-user-plus',
        'heroicon-o-clipboard-document',
        'heroicon-o-arrow-top-right-on-square',
        'heroicon-o-arrow-up-tray',
    ];

    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public string $icon,
        public ?string $prompt = null,
        public ?string $url = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     label: string,
     *     prompt: ?string,
     *     url: ?string,
     *     icon: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'prompt' => $this->prompt,
            'url' => $this->url,
            'icon' => $this->icon,
        ];
    }
}
