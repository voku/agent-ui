# UI-3: Content-Security-Policy blocks the only JavaScript in the product

- **Ticket:** UI-3
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:18+00:00
- **Summary:** High. Response sends script-src 'self' while the layout footer ships an inline <script>. Chromium refuses to execute it on every page, so every Copy button is permanently dead.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: ClientScript is admitted by hash in Response::contentSecurityPolicy() script-src; ClientScriptTest pins the pairing.

## Agent Task Brief
Serve the progressive-enhancement script so it satisfies the app's own CSP, and cover the pairing with a test so the policy and the script cannot drift apart again.
