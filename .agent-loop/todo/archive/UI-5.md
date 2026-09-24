# UI-5: Every error, 404 and CSRF failure renders a bare unstyled HTML stub

- **Ticket:** UI-5
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:19+00:00
- **Summary:** High. Application::errorPage() emits a minimal document with no lang, viewport, stylesheet, masthead or link back. A mistyped URL, an expired CSRF token or any 500 drops the operator out of the control plane entirely.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: Application::errorPage() renders templates/error/index.php through the normal layout; the bare stub remains only as the layout-unavailable fallback.

## Agent Task Brief
Render errors through the normal layout with the status, an honest message and navigation back into the app.
