<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CreationSource: string implements HasColor, HasLabel
{
    /**
     * Created through the web application interface by a user.
     * This includes records created through forms, dashboards, and
     * admin interfaces where a user directly inputs the data.
     */
    case WEB = 'web';

    /**
     * Sample records seeded when the workspace was created.
     */
    case SAMPLE = 'sample';

    /**
     * Created through bulk data import functionality.
     * This applies to records generated when users upload files
     * (CSV, Excel, etc.) through import tools or when data is
     * migrated from another system in bulk operations.
     */
    case IMPORT = 'import';

    /**
     * Created through the REST API.
     * This applies to records created by external integrations
     * and third-party applications using API tokens.
     */
    case API = 'api';

    /**
     * Created through the MCP server by AI agents.
     * This applies to records created via Model Context Protocol
     * tools, typically by AI assistants interacting with the system.
     */
    case MCP = 'mcp';

    /**
     * Created through the dashboard AI chat assistant.
     */
    case CHAT = 'chat';

    case MAILBOX = 'mailbox';

    case SYSTEM = 'system';

    /** @return list<self> */
    public static function automated(): array
    {
        return [self::SAMPLE, self::MAILBOX, self::SYSTEM];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WEB => 'info',
            self::SAMPLE => 'warning',
            self::IMPORT => 'success',
            self::API => 'purple',
            self::MCP => 'gray',
            self::CHAT => 'indigo',
            self::MAILBOX => 'gray',
            self::SYSTEM => 'gray',
        };
    }

    // One categorical slot each, validated for colour-vision separation on light and dark surfaces.
    public function chartColor(): string
    {
        return match ($this) {
            self::WEB => '#2a78d6',
            self::SAMPLE => '#d95926',
            self::IMPORT => '#1baf7a',
            self::API => '#c98500',
            self::MCP => '#d55181',
            self::CHAT => '#008300',
            self::MAILBOX => '#6250d6',
            self::SYSTEM => '#e34948',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::WEB => 'Web Interface',
            self::SAMPLE => 'Sample Data',
            self::IMPORT => 'Data Import',
            self::API => 'API',
            self::MCP => 'MCP Agent',
            self::CHAT => 'AI Chat',
            self::MAILBOX => 'Mailbox Sync',
            self::SYSTEM => 'System',
        };
    }
}
