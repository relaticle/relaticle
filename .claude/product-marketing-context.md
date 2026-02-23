# Product Marketing Context

*Last updated: 2026-02-23*

## Product Overview
**One-liner:** The open-source CRM built for AI agents
**What it does:** Relaticle is a self-hosted CRM platform with a production-grade MCP server (20 tools), REST API, and 22 custom field types. Teams and AI agents manage contacts, companies, opportunities, tasks, and notes with full data ownership.
**Product category:** CRM / Customer Relationship Management
**Product type:** Open-source SaaS (self-hosted or managed)
**Business model:** Free open-source (AGPL-3.0), managed hosting available at app.relaticle.com. No per-seat pricing.

## Target Audience
**Target companies:** Small-to-medium teams (2-50 people), developer-led companies, AI-forward startups, agencies, consultancies
**Decision-makers:** Technical founders, CTOs, engineering leads, revenue operations managers
**Primary use case:** Managing customer relationships with AI agent integration -- letting AI assistants create contacts, update deals, log notes, and query CRM data through MCP or REST API
**Jobs to be done:**
- Track and manage customer relationships (companies, people, opportunities)
- Let AI agents read/write CRM data without manual entry
- Customize the data model to match their business without code
- Own their data on their own infrastructure
**Use cases:**
- AI agent automatically logs meeting notes and creates follow-up tasks
- Developer builds custom integrations via REST API
- Team customizes fields for their industry (real estate, recruiting, consulting)
- Self-hosted deployment for data sovereignty requirements (EU, healthcare, finance)

## Problems & Pain Points
**Core problem:** Traditional CRMs are either closed-source with vendor lock-in (HubSpot, Salesforce) or outdated open-source alternatives with poor UX and no AI integration (SuiteCRM, EspoCRM)
**Why alternatives fall short:**
- HubSpot/Salesforce: Expensive per-seat pricing, no self-hosting, limited MCP support (HubSpot has 9 MCP tools vs Relaticle's 20)
- Attio: Agent-native vision but closed-source, no self-hosting
- SuiteCRM/EspoCRM: Open source but dated UI, no MCP server, no modern API patterns
- Folk/Clay: Focused on enrichment/automation, not full CRM
**What it costs them:** Vendor lock-in, monthly per-seat fees that scale badly, inability to integrate AI agents, data leaving their control
**Emotional tension:** Frustration with CRM bloat and pricing, anxiety about data ownership, excitement about AI but no way to connect it to their CRM

## Competitive Landscape
**Direct:** Attio -- same agent-native vision, but closed-source and no self-hosting
**Direct:** HubSpot -- dominant CRM, but 9 MCP tools (vs 20), expensive at scale, no self-hosting
**Secondary:** Salesforce -- enterprise CRM, Agentforce product, but massive complexity and cost
**Indirect:** SuiteCRM, EspoCRM -- open-source CRMs but dated UX, no MCP/AI integration
**Indirect:** Folk, Clay -- relationship tools but not full CRM platforms

## Differentiation
**Key differentiators:**
- MCP server with 20 tools (most comprehensive open-source CRM MCP integration)
- 22 custom field types including entity relationships, conditional visibility, per-field encryption
- Self-hosted with full data ownership
- Open source (AGPL-3.0)
- Modern stack: Laravel 12, Filament 5, Livewire 4, PHP 8.4
- 5-layer authorization with multi-team isolation
**How we do it differently:** Agent-native from the ground up -- MCP server and REST API are first-class citizens, not afterthoughts. Custom fields don't require migrations or code changes.
**Why that's better:** AI agents get full CRUD + schema discovery out of the box. Teams customize without developers. Data stays on your infrastructure.
**Why customers choose us:** Data ownership + AI integration + no per-seat pricing + modern UI

## Objections
| Objection | Response |
|-----------|----------|
| "Is it production-ready?" | 900+ tests, CI/CD, used by real teams. Managed hosting at app.relaticle.com. |
| "Can it scale?" | Built on Laravel 12 + PostgreSQL 17, same stack powering millions of users at other companies |
| "What about support?" | Active Discord community, GitHub discussions, documentation, managed hosting includes support |

**Anti-persona:** Enterprise teams (500+ people) needing Salesforce-level workflow automation, companies that want a fully managed solution with zero ops responsibility

## Switching Dynamics
**Push:** Frustration with CRM pricing that scales per-seat, inability to connect AI agents, data locked in vendor platforms
**Pull:** Free and open source, 20 MCP tools for any AI agent, self-hosted data ownership, modern beautiful UI
**Habit:** Existing CRM data, team familiarity with current tools, integrations already set up
**Anxiety:** Migration effort, will it have the features we need, will it be maintained long-term

## Customer Language
**How they describe the problem:**
- "I want my AI to update my CRM automatically"
- "HubSpot is getting too expensive as we grow"
- "I need to self-host for compliance"
- "Every CRM feels like it was built 10 years ago"
**How they describe us:**
- "Open-source CRM with MCP support"
- "Like Attio but self-hosted"
- "Modern CRM I can actually connect my AI agents to"
**Words to use:** agent-native, self-hosted, open source, MCP, custom fields, data ownership, modern
**Words to avoid:** enterprise-grade, cutting-edge, revolutionary, disruptive, leverage, synergize, robust
**Glossary:**
| Term | Meaning |
|------|---------|
| MCP | Model Context Protocol -- standard for AI agents to interact with tools |
| MCP tools | Individual operations an AI agent can perform (create contact, list deals, etc.) |
| Custom fields | User-defined data fields added without migrations or code |
| Agent-native | Designed from the start for AI agent interaction, not retrofitted |

## Brand Voice
**Tone:** Direct, technical but accessible, confident without being arrogant
**Style:** Concise, developer-friendly, show don't tell, no marketing fluff
**Personality:** Calm, competent, transparent, opinionated (Basecamp-inspired)

## Proof Points
**Metrics:**
- 20 MCP tools (vs HubSpot's 9)
- 22 custom field types
- 900+ automated tests
- 5 entity types with full CRUD
**Customers:** Early-stage, growing community
**Testimonials:** (gathering)
**Value themes:**
| Theme | Proof |
|-------|-------|
| Agent-native | 20 MCP tools, REST API, schema discovery resources |
| Customizable | 22 field types, no-code, entity relationships, conditional visibility |
| Data ownership | Self-hosted, AGPL-3.0, PostgreSQL, your infrastructure |
| Modern stack | Laravel 12, Filament 5, PHP 8.4, Livewire 4 |

## Goals
**Business goal:** Grow open-source community and managed hosting users
**Conversion action:** Sign up for free account (app.relaticle.com) or star on GitHub
**Current metrics:** Early stage -- focus on awareness and adoption
