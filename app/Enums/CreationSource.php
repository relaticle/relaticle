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
    case SYSTEM = 'system';

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

    /** @return list<self> */
    public static function automated(): array
    {
        return [self::SYSTEM, self::MAILBOX];
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
            self::SYSTEM => 'warning',
            self::IMPORT => 'success',
            self::API => 'purple',
            self::MCP => 'gray',
            self::CHAT => 'indigo',
            self::MAILBOX => 'gray',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::WEB => 'Web Interface',
            self::SYSTEM => 'System Process',
            self::IMPORT => 'Data Import',
            self::API => 'API',
            self::MCP => 'MCP Agent',
            self::CHAT => 'AI Chat',
            self::MAILBOX => 'Mailbox Sync',
        };
    }
}
