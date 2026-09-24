# UI-8: Setup and Prompt Workbench bypass the design system

- **Ticket:** UI-8
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:19+00:00
- **Summary:** Medium. dl.facts has no CSS rule at all, and both pages use bare label/input/textarea/select/button markup instead of .field, .form__row and .btn, so two of the five primary views render as unstyled HTML.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: dl.facts is styled in app.css; Setup and Prompt Workbench use .field and .btn classes.

## Agent Task Brief
Style dl.facts and base form controls, and move both pages onto the existing form and button classes.
