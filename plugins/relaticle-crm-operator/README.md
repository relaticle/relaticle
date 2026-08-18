# Relaticle CRM Operator Agent Plugin

This plugin packages Relaticle CRM operating guidance for agent workspaces such
as Codex, Claude, Copilot, and other MCP-compatible harnesses.

The plugin is the installable package. A Skill is one capability inside the
plugin, usually a focused `SKILL.md` workflow guide. This first package includes
a Skill for safely operating Relaticle's MCP server across contacts, companies,
opportunities, custom fields, and multi-team CRM context.

## What It Helps Agents Do

- Discover available Relaticle MCP tools before acting.
- Summarize CRM context for a company, contact, opportunity, or team.
- Draft safe contact, company, opportunity, task, and custom-field changes.
- Keep tenant boundaries and authorization constraints visible.
- Require approval before changing customer records.
- Keep CRM data, credentials, and tool arguments out of telemetry.

## Install Anywhere

Configure your agent harness with the Relaticle MCP server documented at
https://relaticle.com/docs/mcp, then use the Skill in
`skills/relaticle-crm-operator/SKILL.md` for read-first CRM workflows.

## Telvine Packaging

If this plugin is published through Telvine:

```bash
npm i -g telvine
telvine login
telvine publish ./plugins/relaticle-crm-operator
```

## Privacy Boundary

Do not emit prompts, source files, CRM record contents, customer identifiers,
API tokens, session cookies, tool arguments, or model outputs as telemetry. Safe
metadata can include the plugin component name, sanitized outcome, harness name,
duration bucket, and sanitized error class.
