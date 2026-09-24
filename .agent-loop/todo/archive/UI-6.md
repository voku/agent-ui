# UI-6: Successful owner mutations give no confirmation at all

- **Ticket:** UI-6
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:19+00:00
- **Summary:** Medium. Approve, review acknowledgement, Learning decisions and every setup operation redirect silently. The operator cannot tell a recorded approval from a no-op.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: FlashNotice carries one-shot outcome notices through owner-mutation redirects.

## Agent Task Brief
Carry a one-shot outcome notice through the redirect and render it on the destination page, without inventing owner state.
