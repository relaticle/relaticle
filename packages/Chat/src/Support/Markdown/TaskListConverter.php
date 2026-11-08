<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support\Markdown;

use League\HTMLToMarkdown\Configuration;
use League\HTMLToMarkdown\ConfigurationAwareInterface;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\Converter\ListItemConverter;
use League\HTMLToMarkdown\ElementInterface;

final readonly class TaskListConverter implements ConfigurationAwareInterface, ConverterInterface
{
    public function __construct(private ListItemConverter $listItems = new ListItemConverter) {}

    public function setConfig(Configuration $config): void
    {
        $this->listItems->setConfig($config);
    }

    public function convert(ElementInterface $element): string
    {
        $item = $this->listItems->convert($element);

        if ($element->getAttribute('data-type') !== 'taskItem') {
            return $item;
        }

        $tick = $element->getAttribute('data-checked') === 'true' ? '[x] ' : '[ ] ';

        return (string) preg_replace('/^(\n?(?:[-*+]|\d+\.) )/', '$1'.$tick, $item, 1);
    }

    /** @return string[] */
    public function getSupportedTags(): array
    {
        return ['li'];
    }
}
