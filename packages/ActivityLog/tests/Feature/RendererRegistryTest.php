<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\ActivityLog\Renderers\ActivityLogRenderer;
use Relaticle\ActivityLog\Renderers\DefaultRenderer;
use Relaticle\ActivityLog\Renderers\RendererRegistry;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

beforeEach(function (): void {
    $this->registry = app(RendererRegistry::class);
    $this->entry = new TimelineEntry(
        id: 'x', type: 'related_model', event: 'email_sent',
        occurredAt: CarbonImmutable::now(), dedupKey: 'k', sourcePriority: 20,
    );
});

it('falls back to DefaultRenderer when nothing registered', function (): void {
    expect($this->registry->resolve($this->entry))->toBeInstanceOf(DefaultRenderer::class);
});

it('resolves by event name before type', function (): void {
    $this->registry->register('email_sent', fn () => new HtmlString('event'));
    $this->registry->register('related_model', fn () => new HtmlString('type'));

    $renderer = $this->registry->resolve($this->entry);
    $result = $renderer->render($this->entry);

    expect((string) $result)->toBe('event');
});

it('resolves by type when event has no binding', function (): void {
    $this->registry->register('related_model', fn () => new HtmlString('type'));

    $result = $this->registry->resolve($this->entry)->render($this->entry);

    expect((string) $result)->toBe('type');
});

it('honors $entry->renderer to bypass registry', function (): void {
    $this->registry->register('email_sent', fn () => new HtmlString('registered'));

    $entry = new TimelineEntry(
        id: 'x', type: 'related_model', event: 'email_sent',
        occurredAt: CarbonImmutable::now(), dedupKey: 'k', sourcePriority: 20,
        renderer: 'email_sent',
    );
    $this->registry->register('email_sent', fn () => new HtmlString('bypass'));

    expect((string) $this->registry->resolve($entry)->render($entry))->toBe('bypass');
});

it('resolves class-string renderers from the container', function (): void {
    $this->registry->register('email_sent', DefaultRenderer::class);

    expect($this->registry->resolve($this->entry))->toBeInstanceOf(DefaultRenderer::class);
});

it('resolves view-name string renderers', function (): void {
    $this->registry->register('email_sent', 'activity-log::entries.default');

    $result = $this->registry->resolve($this->entry)->render($this->entry);

    expect($result)->toBeInstanceOf(View::class);
});

it('DefaultRenderer returns a View', function (): void {
    $renderer = new DefaultRenderer;
    expect($renderer->render($this->entry))->toBeInstanceOf(View::class);
});

it('ActivityLogRenderer returns a View', function (): void {
    $renderer = new ActivityLogRenderer;
    $entry = new TimelineEntry(
        id: 'x', type: 'activity_log', event: 'created',
        occurredAt: CarbonImmutable::now(), dedupKey: 'k', sourcePriority: 10,
    );
    expect($renderer->render($entry))->toBeInstanceOf(View::class);
});
