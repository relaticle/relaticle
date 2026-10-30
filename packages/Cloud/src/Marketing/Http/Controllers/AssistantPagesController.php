<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use App\Enums\ConnectableAssistant;
use Illuminate\View\View;

final readonly class AssistantPagesController
{
    private const string CHATGPT_PLUGIN_URL = 'https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961';

    public function show(string $assistant): View
    {
        return view('cloud::marketing.assistants.show', [
            'assistant' => ConnectableAssistant::from($assistant),
            'mcpUrl' => url()->getMcpUrl(),
            'chatGptPluginUrl' => self::CHATGPT_PLUGIN_URL,
        ]);
    }
}
