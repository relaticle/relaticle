<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

final readonly class SetupOption
{
    public const string KIND_PROMPT = 'prompt';

    public const string KIND_LINK = 'link';

    /** @var list<string> */
    public const array ICONS = [
        'lucide-flag',
        'lucide-clock',
        'lucide-building-2',
        'lucide-briefcase',
        'lucide-mail',
        'lucide-user-plus',
        'lucide-clipboard',
        'lucide-external-link',
        'lucide-upload',
        'lucide-file-spreadsheet',
        'lucide-database',
        'lucide-brain',
        'lucide-sparkles',
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
