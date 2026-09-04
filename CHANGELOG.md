# Changelog

All notable changes to `voku/agent-ui` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

## [0.13.0] - 2026-09-04

### Changed

- Adopt the coordinated pre-1.0 release set: `voku/agent-kanban ^0.4.0`,
  `voku/agent-learning ^0.16.0`, `voku/agent-loop ^0.19.0`,
  `voku/agent-map ^0.10.0`, `voku/agent-recall-compiler ^0.15.0`.

### Added

- Add standalone `bin/agent-ui` executable CLI command supporting `serve` (default), `--port=PORT`, `--host=HOST`, and `--root=PATH` options to easily start the local developer control plane from any project.
- Expose `"bin": ["bin/agent-ui"]` in `composer.json` for installed Composer consumers.
- Add CLI application unit test coverage in `tests/Unit/CliApplicationTest.php`.
- Document standalone `bin/agent-ui` CLI usage and installation in `README.md`.

## [0.12.2] - 2026-09-04

### Fixed

- Raise the released owner floors to `voku/agent-kanban ^0.3.4` and `voku/agent-learning ^0.14.1`, matching the APIs already used by the 0.12.1 UI instead of advertising older incompatible versions.
- Require the maintained `voku/agent-loop ^0.18.6` release so the published dependency graph can resolve Learning 0.14 without pulling the breaking resource-layout changes already present on Loop `main`.
- Remove two redundant runtime guards exposed by the stronger released owner types, keeping PHPStan clean on both the current and lowest-supported dependency graphs without weakening analysis.

### Changed

- Add a PHP 8.3 lowest-supported CI lane using `composer update --prefer-lowest --prefer-stable`, so declared dependency floors are executed rather than merely documented.

## [0.12.1] - 2026-09-01

### Added

- Support multi-board configurations from `agent-kanban`. When multiple boards are configured in `todo/kanban.config.json`, the Board view and Overview render a board switcher with card counts, and individual task routes resolve card details across all available boards.
- Add complete catalog browsing, status filtering, and `MEMORY.md` durable rules / archived task learnings to the Knowledge view (`/knowledge?tab=rules|findings|proposals|archived`).

## [0.12.0] - 2026-09-01

### Fixed

- Read the board through `agent-loop`'s `ProjectLayout::boardRoot()` instead of the project root. A repository scaffolded by `agent-loop init scaffold` keeps its board below the state root, and every page of the control plane answered HTTP 500 there.
- Name the bundled enhancement script in the Content-Security-Policy by hash. `script-src 'self'` had been refusing the layout's inline script on every page, so no Copy button ever worked in a browser. `src/View/ClientScript.php` is now the single source of both the script and the policy source expression that admits it, and a test fails if they drift apart.
- Render errors, 404s and CSRF rejections through the normal layout with a status, an honest message and navigation, replacing an unstyled stub that dropped the operator out of the control plane.
- Close the setup host panel on the projection-error path; a `continue` inside the loop had been skipping the closing tag.
- Wrap wide evidence and work tables in their own scroll container, and let the masthead navigation wrap, so no page scrolls horizontally at a 390px viewport.
- Reproject the managed Claude assets from released `voku/agent-loop 0.18.4`, replacing machine-specific manifest provenance with schema-v3 package-relative `source_reference` values and picking up the owner-fixed ungoverned first-draft review entry path.

### Added

- Report the outcome of every recorded owner mutation — Contract approval, review acknowledgement, Learning decision, and each setup operation — through a one-shot notice on the page the redirect lands on. These say only what the UI invoked; they are never rendered as owner state.
- Require an explicit confirmation for managed-asset removal and mark it as destructive, so the only setup operation a mis-click cannot undo no longer looks like the install action above it.
- Give every task view the same navigation across all seven task routes. `/task/{id}/learning` was previously reachable only from Knowledge detail pages, and the task Prompt Workbench offered no way back.
- Gloss `command_template`, the `next_action_kind` agent-loop emits most often, which had been falling through to the unknown-vocabulary wording. Genuinely unknown kinds still render neutrally.
- Explain the empty board on Overview and Board, naming the owning package's card-creation command instead of showing a fresh repository five empty lanes and no next step.

### Changed

- Style `dl.facts` and the form controls on Setup and the Prompt Workbench through the existing design system; both pages had been falling back to browser defaults.
- Render Copy buttons hidden until the enhancement script reveals them, instead of showing controls that do nothing without it.

### Validation

- PR #25 final exact head `b774f31b1521b472b22e962e500bd28e8b6817fb` passed the PHP 8.3/8.4/8.5 `composer ci` matrix after the managed assets were regenerated by GitHub Actions from an installed `voku/agent-loop 0.18.4`; the remaining upstream-owned review findings were then resolved against those generated artifacts before squash merge.

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
