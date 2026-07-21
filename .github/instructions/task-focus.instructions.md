---
description: "Use when: user asks to work on a task or feature. Prevents the agent from modifying unrelated files or systems."
applyTo: "**"
---

# Task Focus Rule

## Stay on the assigned task

- Do NOT modify unrelated files, settings, configs, or systems unless the user explicitly asks.
- Do NOT work on other pipelines, features, or infrastructure when the user has asked about a specific one.
- Do NOT run seeders, modify `.env`, or touch config files unless explicitly requested.

## If you discover a related issue

- Mention it briefly but do NOT fix it unless the user asks.
- Ask before making changes outside the current scope.

## Example violations

- User asks to fix Pipeline P → Agent starts modifying trading settings, max-age configs, or running seeders
- User asks about a backtest → Agent starts refactoring unrelated pipelines or UI components
