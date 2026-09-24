# UI-4: Destructive setup removal looks and behaves like the benign install action

- **Ticket:** UI-4
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:18+00:00
- **Summary:** High. 'Remove managed assets' is an unstyled submit button rendered directly under 'Install / update managed assets', with identical weight, no danger styling and no confirmation step.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: Setup removal is a separate .danger-zone form with btn--danger and a required confirm_remove checkbox.

## Agent Task Brief
Give the removal control danger styling and an explicit confirmation before the owner mutation runs. Do not invent force/adopt semantics the owner does not project.
