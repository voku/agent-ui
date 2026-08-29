# UI/UX blind-spot review — 2026-08

A walk of every route `agent-ui` serves, against a real dogfood project: this repository, with
`agent-loop` initialised in it and a live `UI`-prefixed board.

Method: read each template and its action, then load all eleven routes in Chromium at 1280px and
390px, capturing console output and document overflow. Findings are severity-ranked by what they
cost an operator, not by how hard they were to fix.

Each finding is a board card. High and medium findings are fixed; low findings are collected on
`UI-14`. Friction in the workflow tooling itself is out of scope here and lives in
[`workflow-feedback.md`](workflow-feedback.md).

## High

| Card | Finding | Evidence |
| --- | --- | --- |
| UI-2 | Board projection ignored agent-loop's owned board root, so every page answered 500 in a scaffolded repository | `ConfigurationException` on `/`, `/board` and every task route |
| UI-3 | The app's own CSP blocked the only JavaScript it ships, so no Copy button had ever worked | Chromium console, all 11 routes: `Refused to execute inline script … "script-src 'self'"` |
| UI-4 | Destructive managed-asset removal was styled and placed identically to the benign install action, with no confirmation | Setup page, 6 host panels; Claude's plan listed 23 removals |
| UI-5 | Every error, 404 and CSRF rejection rendered a bare unstyled stub with no navigation | `/nope` → Times New Roman, no layout, no way back |

## Medium

| Card | Finding | Evidence |
| --- | --- | --- |
| UI-6 | Successful owner mutations redirected silently | approve / review-ack / learning / 4 setup operations |
| UI-7 | Task sub-navigation inconsistent; `/task/{id}/learning` unreachable from the task | 5 different partial button rows; Prompt Workbench had none |
| UI-8 | Setup and Prompt Workbench bypassed the design system | `dl.facts` had no CSS rule at all; bare `button`/`input`/`textarea`/`select` |
| UI-9 | Masthead navigation could not wrap | document 518px wide in a 390px viewport, every page; "Knowledge" off-screen |
| UI-10 | `command_template` — the kind agent-loop emits most — had no gloss | live task showed the unknown-vocabulary fallback |
| UI-11 | Copy buttons rendered enabled and dead until the script ran | `button.hidden` set only after execution |
| UI-12 | `continue` inside the setup host loop skipped `</section>` | latent; unbalanced markup on the projection-error path |
| UI-13 | A freshly scaffolded repository was a dead end | five empty lanes, no card-creation hint, no Overview → Board link |

## Low — collected on UI-14, not fixed

- Overview projects the workflow once per card (N+1) and lists every card under Current work unbounded.
- `HEAD` requests answer 404; the router matches `GET` and `POST` only.
- Knowledge pages drop the project label from the masthead.
- A favicon request 404s into the browser console on first load.

## Verification after the fixes

`composer ci`: 67 tests, PHPStan max, template lint, php-cs-fixer — all green.

Chromium re-run across all eleven routes at 1280px and 390px: no console errors, and no page
scrolls horizontally at 390px. The 404 route's own status is the only remaining console entry.

## Boundaries kept

No finding was fixed by deriving owner state in the UI. The board root now comes from
`ProjectLayout`, the removal plan identity and expected-state token still come from the owner,
the outcome notices say only what the UI invoked, and unknown owner vocabulary still renders
neutrally.
