<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

final readonly class SetupOption
{
    public const string KIND_PROMPT = 'prompt';

    public const string KIND_LINK = 'link';

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
