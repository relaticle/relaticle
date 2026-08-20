<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use Filament\Pages\Page;
use Relaticle\EmailIntegration\Filament\Clusters\EmailSettings;
use Relaticle\EmailIntegration\Filament\Concerns\HasClusterBreadcrumbs;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Livewire\OutboxTable;

/**
 * Standalone outbox page. The table itself lives in
 * {@see OutboxTable} so this page and the
 * Outbox tab on the email page render the same thing.
 */
final class EmailOutboxPage extends Page
{
    use HasClusterBreadcrumbs;
    use HasEmailFeatureFlag;

    protected string $view = 'email-integration::filament.pages.email-outbox';

    protected static ?string $slug = 'outbox';

    protected static ?string $title = 'Outbox';

    protected static ?string $cluster = EmailSettings::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 5;

    /**
     * Blank so the stock full-width header is not rendered: the page view carries its
     * own `<x-email-integration::cluster-header />` inside the content column.
     */
    protected ?string $heading = '';
}
