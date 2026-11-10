# Cross-repository evidence workbench (first read-only slice)

Route: `/github-workflow` in the existing local `agent-ui` application.

## Scope and authority

The page observes the public GitHub API for six explicitly allowlisted `voku` repositories.
It never authorizes a Contract, claims canonical `agent-loop` next-action semantics,
starts an agent, changes a repository, or merges a PR. Coding continues via the installed
native Codex, Claude Code, OpenCode (or compatible) host using Loop's own enter/finish
projection. `agent-loop-runner` is optional, not the default runtime.

The page intentionally separates:

- PR/issue description **claim** from verifiable checks and artifacts **proof**.
- Observed **exact head SHA** from snapshots on previous heads.
- GitHub **check success** from engineering correctness and independent review.
- REST review submissions/comments from the **GraphQL review thread resolution** state,
  which remains unknown until a trusted import supplies it.
- Proposed next **investigation** from Loop's canonical authorized next action.
- Issue references and imported dependency edges from a verified release/consumer
  chain. The UI does not invent missing dependency edges.

## Interaction

- Live inventory: open PRs and issues; only selected PRs incur detailed review,
  Actions and artifact fetches. Manual refresh, plus 30-minute visible-tab refresh.
  GitHub's anonymous rate limit may prevent partial sources from loading, in which
  case they remain unavailable rather than being displayed as empty.
- Filters: repository, PR/issue, heuristic/explicit phase, status, evidence type,
  head freshness, blocker and text. Selection gives a full evidence view. Compare
  two to four work items side by side.
- JSON v1 interchange: import/export a bounded snapshot (max 300 items / 2 MiB);
  input is never treated as executable markup or repository authority. An imported
  thread disposition is an operator-supplied observation, not a GraphQL verification.
  On head change, previous SHA-bound CI/review receipts remain visible as stale
  history; prior thread dispositions and head-unbound artifact views are invalidated.
  When both historical and current-head proof exists, freshness is `mixed`,
  not a fabricated all-current pass.
- No OAuth token, private repository details, or GitHub API key are sent to
  the browser. Requests are fixed to `https://api.github.com`, `voku/` and
  the six hardcoded repositories. CSP permits that destination and SHA-pinned
  local scripts only.

## Gaps / next bounded slice

1. Add a server-side authenticated, bounded evidence adapter for real GitHub
   GraphQL review-thread state and pagination, once a credential-handling and
   permissions boundary is agreed. Never put tokens in browser JavaScript.
2. Import validated release tags, package versions, consumer lockfile receipts
   and post-merge dogfood as distinct proof types. Without them, a merged fix is
   **not** displayed as released or installed.
3. Add a fixture-driven browser test for head-change invalidation and filters;
   `node --check` is only syntax proof.
4. If a cross-repository transition is to be executed, use the native host
   with the *owner-projected* action and normal review/approval gates. No new
   lifecycle policy belongs in this UI.

A running tab can refresh automatically. No background daemon or cross-session
scheduled refresh is claimed; persistent snapshots require explicit JSON export.
