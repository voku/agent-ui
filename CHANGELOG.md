# Changelog

All notable changes to `voku/agent-ui` will be documented in this file.

The format follows Keep a Changelog, and this project uses semantic versioning where practical.

## [Unreleased]

### Added

- Knowledge → Dream (`/knowledge/dream`) runs agent-loop's `WorkflowDreamService` preview and shows agent-learning's reviewable decisions, warnings and metrics as the owner reports them; viewing writes nothing. A CSRF-protected, explicitly confirmed POST re-runs Dream through the owner and writes candidate Proposals only, which still need review before becoming guidance.
- Require `voku/agent-loop ^0.20.44` and `voku/agent-learning ^0.18.25` for the typed Dream API.

### Changed

- Rebrand the control plane with the agent-loop identity from `voku/agent_loop_demo`: the gradient infinity mark as logo and favicon, a deep-navy masthead banner with a brand-gradient hairline, the violet → blue → cyan palette (owner authority now reads in brand blue), Inter / JetBrains Mono type stacks, and a gradient primary action. Everything stays inlined — no asset route, no build step, no web-font fetch — and the semantic state colours (attention, blocked) are unchanged.

### Fixed

- Prompt Workbench no longer jumps: the recipe catalog and the chosen recipe's inputs plus result sit side by side, choosing a recipe applies immediately, and the result is previewed live from the same POST endpoint as a server-rendered fragment (`_fragment=result`), so the preview is byte-identical to Generate. Without JavaScript each submit targets `#recipe-fields` / `#prompt-result`, so the browser lands on what changed instead of the page top. An out-of-date prompt is removed rather than left copyable.
- Knowledge no longer prints statistics frozen into the template from another corpus (`88.5% → 12.9%`, `80.7%`, `63.4%`, `117 / 145`, a hard-coded `0.0%` churn card) or internal issue numbers; every figure now comes from this repository's agent-learning analytics.
- The Knowledge tab, finding/proposal filter and Map region filter now show which one is selected; `pill--selected`, `pill--accent`, `pill--muted`, `.metric` and `.board-switcher__count` had no styles, and two templates referenced undefined colour tokens.
- The ~370 KB graph library is inlined only on the workflow progress page instead of every page (~470 KB → ~105 KB per page); the CSP names both scripts by hash.
- The workflow graph reads its colours from the design tokens instead of hard-coded values from the previous palette.
- The favicon is no longer blocked by the Content-Security-Policy (`img-src 'self' data:`).
- Task views use a compact tab row and no longer repeat the task title as the page heading, so each view's own content starts above the fold; the pinned masthead no longer wraps into a double-height bar at mid widths or covers a sixth of a phone screen.

## [0.18.4] - 2026-09-23

### Changed

- Align the released first-party dependency graph with `voku/agent-loop ^0.20.40`, `voku/agent-learning ^0.18.24`, and `voku/agent-recall-compiler ^0.25.0`. This removes the direct Recall 0.24 constraint that prevented Composer from resolving Loop 0.20.40 and keeps the UI on typed owner projections without reconstructing sparse Learning or Recall semantics (#74).
- Prove the coordinated graph on PHP 8.3, 8.4, and 8.5, including the lowest-supported dependency graph and both Runner-installed and Runner-absent integration matrices (#74).

## [0.18.3] - 2026-09-23

### Changed

- Require `voku/agent-loop ^0.20.32` and `voku/agent-recall-compiler ^0.23.0`, following the Loop line that moved to Recall 0.23; `^0.22.0` made the UI uninstallable next to `voku/agent-loop >=0.20.32`.

## [0.18.2] - 2026-09-21

### Changed

- Require `voku/agent-loop ^0.20.30`, `voku/agent-map ^0.18.0`, and `voku/agent-recall-compiler ^0.22.0`, keeping the UI's first-party dependency graph compatible with the current released Loop line.

## [0.18.1] - 2026-09-20

### Changed

- Require `voku/agent-loop ^0.20.27`, `voku/agent-map ^0.17.0`, and `voku/agent-recall-compiler ^0.21.0`, aligning the UI with the current released first-party dependency graph without compatibility unions or VCS fallbacks.

## [0.18.0] - 2026-09-18

### Added

- Typed `CommandCatalogGateway` in `src/Integration/AgentLoop/` consuming the authoritative `CommandCatalog` from `voku/agent-loop` 0.20.18 (#63).
- `/commands` route and server-rendered reference view displaying all 21 top-level loop commands grouped by `CommandGroup` (Workflow & Lifecycle, Evidence & Learning, Inspection & Navigation, Setup & Operational) with owner badges, usage codeblocks with copy buttons, quick-jump anchor pills, and server-rendered group/owner/search filtering (#63).
- Added `Commands` navigation link under `Tools` in the workspace shell header (#63).
- Exact `next_action` → command-reference link resolving canonical loop command identities from action strings across the persistent task context header, developer cockpit workgroups, and prompt workbench (#63).

### Changed

- Bumped `voku/agent-loop` constraint to `^0.20.18` to require the typed Command Catalog release (#63).

## [0.17.0] - 2026-09-17

### Added

- Carry a persistent task context across all ten task views: task identity, agent-kanban's lane, agent-loop's run state and agent-loop's canonical next action, each labelled with the owner it came from and rendered verbatim, with the task navigation directly beneath it (#58, part of the #59 workbench epic).

### Changed

- Group the primary navigation into a workspace shell by developer intent - Work, Knowledge, Code, Tools - instead of six peer links, so choosing a destination no longer requires knowing which package owns the answer (#58).
- Group the ten peer task views into Summary, Intent, Execution, Evidence and Tools, so "Edit card" no longer sits beside "Workflow" as an equal weight.
- Mark the current section and the current task view with a weight and shape cue in addition to colour, and announce the group name to assistive technology without printing it on screen.
- Move the task navigation from the very bottom of each task page to the top, where the context it belongs to is.
- Order the Overview by the questions a returning developer asks: what needs a person, then current work, then the board, then knowledge, with system vitals and architecture pulse last under `System & tooling` (#58 slice C).
- Group Current work by agent-loop's own `next_action_kind` instead of listing one identical placeholder command per task. Each group's exact commands stay verbatim behind a keyboard-operable disclosure; the cockpit is ~20% shorter than before the grouping.
- Give the workspace a clearer visual hierarchy: larger page headings, section labels carried by a hairline, roomier panels, and lanes and execution modes sized to their own content instead of stretching to the tallest sibling.

Presentation and HTTP normalization only: every route and deep link keeps its URL, no lifecycle is inferred, no owner semantics are added, and navigation still works with JavaScript disabled.

### Fixed

- Render a 404 page instead of a 500 for a task id no board holds - the case a governed task with a Contract and a Run but no agent-kanban card actually reaches.
- Render agent-loop's canonical next action exactly once per task view. The persistent task context left the earlier copies in place, so `/task/{id}` and `/task/{id}/progress` each printed it twice.
- Drop the task-summary duplication the persistent context replaced: the breadcrumb lane, the page-head links now in the task navigation, and the repeated run state and lane in `Current state`. The page keeps its own H1 and everything summary-specific.
- Stop the task title breaking one character per line on a phone. The task-context grid had no floor on its identity column, so at 390px the facts took the width and `overflow-wrap: anywhere` did the rest.

### Validation

- `composer ci` passed with 215 tests, 963 assertions, clean template linting, and 0 PHPStan errors.
- Differential render probes against `1ee94ad` and `64e7fed`: 16 pages served side by side in each case, identical HTTP statuses, and no previously emitted route lost.
- Rendered evidence, not source inspection: full-page screenshots of `/`, `/board`, `/task/UI-1`, `/task/UI-1/progress`, `/knowledge`, `/map`, `/setup` and `/prompts` at 1440px, plus `/` and `/task/UI-1` in dark mode and at 390px.

## [0.16.0] - 2026-09-16

### Added

- Add workflow progress projection view at `/task/{id}/progress`, exposing deterministic stage transitions, task completion status, and active decision context directly from `voku/agent-loop`'s `RunProgressProjector` (#57).
- Render interactive workflow stage diagram using Cytoscape.js, featuring zoom, fit, reset controls, light/dark mode styling, active node auto-selection, node inspection panel, and smooth scrolling to detail cards.
- Support complete zero-JS fallback accessibility with pure CSS status badges and sequential stage cards for environments without client-side script execution.
- Wire human decision actions (`APPROVED`, `APPROVED_WITH_MODIFICATIONS`, `REJECTED`) directly within the workflow progress view, redirecting seamlessly back to `/task/{id}/progress`.
- Add workflow progress navigation links across task headers, detail panels, and task navigation menus.
- Bump `voku/agent-loop` dependency to `^0.20.14`.

### Validation

- `composer ci` passed with 197 tests, 738 assertions, clean template linting, and 0 PHPStan errors.

## [0.15.3] - 2026-09-14

### Fixed

- Rename "Permanent Graduation" to "Durable Handoff" to accurately reflect that landed guidance and active constraints are evolving lifecycle checkpoints, not immutable endpoints (#116).
- Format unobserved cohort latencies cleanly as dashes instead of `0.0d` / `0.0h` (#115).

### Validation

- `composer ci` passed with 192 tests, 702 assertions, clean template linting, and 0 PHPStan errors.

## [0.15.2] - 2026-09-14

### Fixed

- Align Knowledge Analytics tab with `voku/agent-learning` 0.18.13: render strict retirement buckets (`RATIONALE_CORRECTED`, `OTHER_AUDITED_REASON`, `UNKNOWN_LEGACY_REASON`) and handle optional consolidation classification gracefully.

### Validation

- `composer ci` passed with 192 tests, 702 assertions, clean template linting, and 0 PHPStan errors.

## [0.15.1] - 2026-09-14

### Added

- Add **Analytics & Evolution** tab to `/knowledge` rendering `voku/agent-learning`'s `CorpusAnalysisResult`:
  - Visualise monthly workflow evolution across epochs (Issue #115), highlighting the collapse of single-session proposal generation (88.5% -> 12.9%) as LearningNotes and deterministic compile-down were adopted.
  - Deconstruct the aggregate 80.7% terminal proposal rate (Issue #116) into 63.4% permanent graduation into skills/docs/constraints, 14.5% human review triage, 9.7% NO_DURABLE_LEARNING acknowledgement, and 0% unexplained churn.
  - Expose consolidation diagnostics and Dream recurrence distributions (Issue #117).
  - Add a Corpus Evolution overview teaser linking from `/knowledge` to the full analytics view.
- Expose typed `corpusAnalytics()` method on `LearningCatalogGateway` backed by `voku/agent-learning: ^0.18.12`.

### Validation

- `composer ci` passed with 192 tests, 702 assertions, clean template linting, and 0 PHPStan errors.

## [0.15.0] - 2026-09-11

### Added

- Show, beside the approve form, what the Contract revision in front of the reader changes relative to the revision they already approved. `agent-loop` has always archived the revision each `revise()` replaces, and `TaskContractStore::supersededRevisions()` now lets it be read; the page names the earlier revision and who approved it, then lists what moved in goal, scope, validation, non-goals and acceptance criteria. The comparison is mechanical set difference over two owner-provided Contracts and judges nothing about whether a change is significant, safe or acceptable — that is the judgement being asked for. It always compares against the revision being replaced rather than the original, a revision that changed none of those fields says so rather than rendering nothing, and a first revision shows no comparison at all.

- Answer, on the Knowledge pages, whether a Finding will ever be seen again. `agent-learning` decides: a Finding becomes reusable only by becoming a LearningNote, and only a LearningNote is ever returned as a precedent to a later task, so a Finding that cannot be promoted is recorded and never read back however good the lesson is. The Finding page renders the owner's verdict with every missing input it names, keeping the owner's identifier beside each label so a newer vocabulary is never silently reworded, and the Knowledge overview counts those verdicts — a root where none of the open Findings can be promoted now says it is write-only instead of looking like accumulating knowledge. The UI computes no part of this, offers no way to promote, and distinguishes a project with no readable Learning from one whose Learning holds nothing reusable.

- Answer, on a task's Contract page, what outside the declared scope can notice this change. Each declared path is asked of `agent-map`'s new file-level impact, and every file that traversal reaches is partitioned by `agent-loop`'s own `ApprovedScope` — the same rule the task page's Git-observed scope drift already uses, so the two cannot disagree over a trailing slash. Reading a Contract no longer means opening each path in Code Search and comparing the callers by eye. Reaching outside the declared scope is rendered as an observation, never as a verdict on the Contract: the traversal is bounded, its uncertainty is preserved, a path `agent-map` does not index is reported as an absent projection rather than as "nothing depends on it", and a snapshot behind the working tree says so.

- Make `/map/source` a junction instead of a terminus: each indexed symbol continues into its own impact, and the file carries the architecture region `agent-map` placed it in, with every other file in that region one click from its own source. A reader who found one relevant file can keep reading without starting another search. The region is shown only when the owner answers about that exact path — asking the graph projection about an unindexed file answers with the whole architecture instead of nothing, and rendering that would invent a placement `agent-map` never made — and it carries the map's own staleness when the snapshot is behind the working tree.
- Add an interactive explorer to `/map/graph`: pan, zoom, fit and neighbourhood focus over the exact same `MapGraphSnapshot` the static drawing and the evidence tables already render. Focus dims the drawing only — the node and edge tables always list the complete bounded projection — and every detail panel it reveals, including its Search, Source and region links, is server-rendered from that snapshot, so the browser never resolves a navigation target or derives an adjacency of its own. Activating a node with the keyboard moves focus into the panel it reveals, and neighbour identities travel as JSON because a file node's id is its repository path and a path may contain a space. No graph library, no build step, and drawing state stays ephemeral: only the SVG `viewBox` moves, never a node.
- Answer code search on `/map` through `agent-map`'s `HybridSearch` over its derived chunk index, so a query reaches method bodies, comments and error strings instead of symbol names alone. Every result set renders the owner-reported channel mode, degraded reason, recognised structural terms, and both the map and search-index snapshots, and every hit lists the per-channel ranks it earned.
- Fall back to `AgentMapIndex::query()`, which needs no derived index, whenever the chunk index is missing, unusable or unreadable, so an unindexed repository never looks like a repository with no matching code. The fallback is labelled as the consumer's own composition: agent-ui marks its mode and reason as its own labels, reports the absent channel ranks, structural terms and search-index snapshot as absent instead of synthesizing owner-shaped values, and carries through only the map snapshot, which is genuinely owner-derived.
- Add `/map/source`: bounded repository source windows materialized by `agent-map`'s own `SourceMaterializer` and refused when the recorded file hash no longer matches the working tree, so a stale map is reported as stale instead of rendering code the rest of the page is not describing. A refusal is classified from `AgentMapIndex::staleEntries()` rather than from the exception: a file agent-map names stale renders as stale with the owner's reason (`hash` or `missing`), and any other materialization failure stays `unavailable` with the owner's message. Search hits and symbol pages carry the same verified windows inline.
- Add `/map/impact`: `agent-map`'s bounded reverse-dependency traversal drawn as concentric depth rings with dashed uncertain paths, backed by the same nodes listed as text with their relation kinds and evidence counts. Depth, node bounds, truncation and uncertainty stay exactly as the owner reported them.
- Add a search-index readiness projection alongside map readiness, separating "no FTS5 in this PHP build", "index never built", "index behind the map" and "index reports integrity failures", each with the command that repairs it.
- Add task-level Map navigation and a `Work ↔ Architecture` bridge: task actions link to code search, architecture and history, while the work page keeps Loop-approved scope and Git-observed changed paths separately attributed before linking either into Map-owned evidence. Candidate Contract scope does not become approved Map navigation before approval.

### Changed

- Neutralize the `hidden` attribute in the base stylesheet. An author `display` rule outranks the user-agent rule behind `hidden`, so a control that ships hidden for progressive enhancement rendered — and did nothing — for a reader without JavaScript.
- Resolve agent-map artifact locations once through a shared `MapArtifactLocator`, so the map, source and search gateways cannot disagree about which index this repository's map is.
- Restore the semantic channel through `agent-map`'s typed `SearchIndexStore::semanticProvider()` factory: when stored vectors and sqlite-vec are available, `HybridSearch` automatically uses the restored provider; when absent, it gracefully degrades and reports `semantic_channel_unavailable` as owner-reported.

## [0.14.1] - 2026-09-07

### Fixed

- Require `voku/agent-loop ^0.20.1` so lowest-supported installs include the released Recall-floor bridge instead of falling back to Loop 0.20.0.
- Exclude the broken Recall 0.17.0 patch while preserving the older supported lines: `voku/agent-recall-compiler ^0.15.0 || ^0.16.0 || ^0.17.1`.
- Require `voku/agent-learning ^0.18.2`, the first released owner patch that returns active LearningNotes as precedents for new tasks without direct findings, matching the UI return-loop dogfood.

### Validation

- PR #37 passed `composer ci` on PHP 8.3, 8.4 and 8.5; its PHP 8.3 `--prefer-lowest --prefer-stable` lane resolved released Learning 0.18.2, Loop 0.20.1, Recall 0.17.1, Map 0.10.0 and Runner 0.1.1, then passed the real LearningNote return-loop dogfood.
- The Runner optional-installation matrix passed on the same PR head.

## [0.14.0] - 2026-09-05

### Added

- Add bounded human-readable architecture and file-coupling graph projection from agent-map owner projections with deterministic SVG rendering, region drill-down, evidence tables, file-path lookup, region navigation, quick jumps, and bounded node/edge controls on `/map/graph` (#30).
- Transform the home overview into a unified Developer Cockpit with an Action Deck, System Vitals, Architecture Pulse, Code Map health, Kanban flow, attention state, and optional Loop Runner status.
- Add released-consumer dogfood for the LearningNote return loop: a completed task can expose optional LearningNote work, lose its transient Session, and a later normal `enter` deterministically receives the current precedent through Recall without private Learning storage access (#31, #32).
- Add the Runner optional-installation matrix: a `--no-dev` consumer proves the UI remains usable with Runner absent, while the installed shape proves the tagged Runner owner projection remains observational and cannot advance Loop authority (#33).
- Add repository contribution, security, issue/PR template, funding, editor, and release-archive metadata for the public package.

### Changed

- Raise the released owner floors to `voku/agent-learning ^0.16.1` and `voku/agent-loop ^0.20.0` for the LearningNote return-loop contract exercised by the UI.
- Require released `voku/agent-loop-runner ^0.1.1` instead of `dev-main`. Keep the stable Runner VCS discovery entry because normal Composer discovery still does not resolve the tagged package; `minimum-stability: dev` remains removed.
- Keep Runner optional in `require-dev`/`suggest`: normal production installation and read-only UI use do not require the managed-execution package.

### Validation

- PR #31 exact head `2b6be113cfad6b4650b0dae975148eee222adf63` passed PHP 8.3/8.4/8.5 plus `prefer-lowest` against released Learning `0.16.1`, Loop `0.20.0`, Recall `0.15.0`, and Runner `0.1.1`; follow-up #32 kept the observed Run-B outcome evidence-bound as `NO_EFFECT` because delivery was proven but downstream use was not observed.
- PR #33 exact head `62922e186d18130bde7e0ebe3d3fb8bea48d1ab1` passed PHP 8.3/8.4/8.5 plus `prefer-lowest`, and its dedicated Runner matrix passed both the production `--no-dev` absent shape and released tagged installed shape.

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
