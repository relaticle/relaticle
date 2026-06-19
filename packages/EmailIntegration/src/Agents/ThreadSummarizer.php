<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenAI)]
final class ThreadSummarizer implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
You are a CRM assistant summarising email threads for sales and account management professionals.

Rules:
- 2-4 sentences maximum
- Identify the main topic, key decisions, next steps, and any urgency
- Mention participants by role (e.g., "prospect", "account manager") not by name unless highly relevant
- Never reproduce verbatim content from the emails
- Write in flowing professional prose, no bullet points
PROMPT;
    }
}
