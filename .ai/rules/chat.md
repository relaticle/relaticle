---
paths:
  - 'packages/Chat/**'
---

# Chat

## Chat always passes a resolved provider, so #[Provider] failover lists never run
AiModelResolver::resolve() returns one concrete provider, ChatController hands it to ProcessChatMessage, and the job calls $agent->stream(provider: ...). laravel/ai reads the #[Provider] attribute ONLY when the prompt's provider argument is null (Promptable::getProvidersAndModels), so a provider LIST on CrmAssistant would never fail over. To actually get provider failover, stream() has to receive the array - a product decision, because the fallback provider runs a different model.
Two related limits: StreamErrorException is not a FailoverableException, and StreamableAgentResponse::hasYielded() suppresses failover once any event reached the consumer, so a mid-stream failure cannot fail over either.
Provider failover therefore lives in the job, not the attribute. When resolution came from `auto` (resolve() tags it `source`), nothing has streamed yet, and transient retries are exhausted, ProcessChatMessage re-dispatches itself once against the next entry in `chat.auto_chain` (failoverDepth bounds it to one hop). An EXPLICIT model pick never fails over: users pay per-model credit multipliers, so a silent swap is worse than an error.

## Anthropic prompt caching rides on providerOptions() overriding the request body
laravel/ai still has no native prompt-cache API (v0.11.0; PR #860 closed, #869 open). CrmAssistant::anthropicCachedSystemBlocks() works only because Gateway\Anthropic\Concerns\BuildsTextRequests ends with array_merge($body, $providerOptions), so the returned 'system' array of content blocks replaces Anthropic's plain-string system prompt and carries the cache_control breakpoint. Tool schemas render before system in Anthropic's cache prefix, so the one breakpoint covers all of them plus the static instructions; per-turn context must stay in the second, uncached block.
If that merge order ever changes upstream nothing throws - turns just silently cost full price. tests/Feature/Chat/AnthropicPromptCachingTest.php asserts the built request body; keep it green on every laravel/ai upgrade. Also: Usage::promptTokens is the UNCACHED remainder only, so any cost figure priced off it understates the real prompt.
