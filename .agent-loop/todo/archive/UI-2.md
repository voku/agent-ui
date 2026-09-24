# UI-2: Board projection ignores agent-loop's owned board root

- **Ticket:** UI-2
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:18+00:00
- **Summary:** High. BoardProjectionGateway passed the project root straight to agent-kanban, so a repository scaffolded by 'agent-loop init scaffold' (board under .agent-loop/todo) answered HTTP 500 on every page.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: BoardProjectionGateway resolves the board through ProjectLayout::boardRoot(); BoardProjectionGatewayTest covers a relocated state-root board.

## Agent Task Brief
Resolve the board root through voku\AgentLoop\ProjectLayout::boardRoot() instead of spelling a filesystem location in the UI. Prove it with a test that a state-root board is readable.
