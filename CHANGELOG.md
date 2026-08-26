# Changelog

All notable changes to `voku/agent-ui` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

## [0.11.0] - 2026-08-26

### Added

- Publish the first tagged `agent-ui` control plane after the completed v0.1→v0.10 roadmap: repository setup/readiness, board and task lifecycle views, persisted Recall context explainability, work/scope transparency, review/evidence/history, durable Learning/knowledge browsing, and the attention-first integrated cockpit all remain projections over their typed domain owners.
- Add deterministic `/prompts` and `/task/{id}/prompts` Prompt Workbench routes that compose an `agent-loop` workflow prompt envelope with an explicitly selected `agent-recall-compiler` operating-prompt preview. Same normalized inputs and owner state produce the same prompt bytes and provenance digests; no LLM generates the prompt.
- Add intent-first recipe presentation grouped by Recall-owned `purpose`. Task-aware pages surface the `recover` group as “Need help moving forward?” while recipe choice remains explicit and no recipe id, keyword classifier, ranking policy, or hidden auto-selection is introduced.
- Show owner-declared required prompt arguments plus task-context and mutation-authority requirements before selection, then preserve exact workflow, Contract, Run and Recall lineage in generated prompt provenance.

### Changed

- Require stable `voku/agent-loop ^0.18.2` and `voku/agent-recall-compiler ^0.13.15` owner APIs for workflow envelopes, delegated post-approval task authority, typed prompt catalog/preview validation and applicability metadata.
- Replace the legacy hand-written coding-agent handoff prompt with a redirect into the owner-backed task Prompt Workbench, removing duplicated workflow/prompt semantics from the UI.

### Safety and authority

- `agent-ui` remains a presentation/invocation layer: workflow authority stays in `agent-loop`, prompt/context semantics stay in `agent-recall-compiler`, Learning truth stays in `agent-learning`, board truth stays in `agent-kanban`, and Runner state remains observation. The UI does not parse owner CLI output or private state to recreate those decisions.
- Prompt Copy remains fail-closed on owner disagreements, missing required task/mutation authority, invalid recipe arguments, and unverifiable persisted Recall context.

### Validation

- Prompt Workbench PR #18 exact head `0c750012cd939a259f0944cfc907c11418bcf488` passed `composer ci` on PHP 8.3, 8.4 and 8.5 against released Loop 0.18.2 and Recall 0.13.15 before squash merge.
- Intent-first follow-up PR #22 exact head `8b3bb1c9f109d026c130cdd721dcc4c8bb2f344e` passed the same PHP 8.3/8.4/8.5 matrix, including 61 PHPUnit tests / 129 assertions, PHPStan, template lint and CS check before squash merge.
