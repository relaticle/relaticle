# Relaticle CRM Operator Evals

These eval cases verify that the Relaticle CRM Operator Skill discovers context
before acting, treats writes as approval-gated, respects multi-team CRM
boundaries, and avoids exposing customer data in plugin telemetry.

Run them with the eval runner of your choice by mapping each JSONL record to an
agent task and checking the `expected` criteria.
