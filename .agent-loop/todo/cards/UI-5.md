# UI-5: Every error, 404 and CSRF failure renders a bare unstyled HTML stub

- **Ticket:** UI-5
- **Lane:** VERIFY
- **Status:** in_review
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-08-29T00:58:56+00:00
- **Summary:** High. Application::errorPage() emits a minimal document with no lang, viewport, stylesheet, masthead or link back. A mistyped URL, an expired CSRF token or any 500 drops the operator out of the control plane entirely.
- **Format version:** 1

## Agent Task Brief
Render errors through the normal layout with the status, an honest message and navigation back into the app.
