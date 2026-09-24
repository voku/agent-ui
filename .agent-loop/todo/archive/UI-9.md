# UI-9: Primary navigation overflows the viewport on narrow screens

- **Ticket:** UI-9
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:19+00:00
- **Summary:** Medium. The masthead nav does not wrap, so the document is 518px wide in a 390px viewport on every page and the Knowledge link sits off-screen.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: Masthead wraps; /, /board, /knowledge, /map, /prompts, /commands, /setup, /task/UI-1 and /knowledge/dream all have scrollWidth 390 at a 390px viewport.

## Agent Task Brief
Let the masthead wrap and confirm no page scrolls horizontally at 390px.
