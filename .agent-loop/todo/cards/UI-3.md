# UI-3: Content-Security-Policy blocks the only JavaScript in the product

- **Ticket:** UI-3
- **Lane:** VERIFY
- **Status:** in_review
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-08-29T00:58:55+00:00
- **Summary:** High. Response sends script-src 'self' while the layout footer ships an inline <script>. Chromium refuses to execute it on every page, so every Copy button is permanently dead.
- **Format version:** 1

## Agent Task Brief
Serve the progressive-enhancement script so it satisfies the app's own CSP, and cover the pairing with a test so the policy and the script cannot drift apart again.
