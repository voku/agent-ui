# UI-2: Board projection ignores agent-loop's owned board root

- **Ticket:** UI-2
- **Lane:** READY
- **Status:** todo
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-08-28T19:59:11+00:00
- **Summary:** High. BoardProjectionGateway passed the project root straight to agent-kanban, so a repository scaffolded by 'agent-loop init scaffold' (board under .agent-loop/todo) answered HTTP 500 on every page.
- **Format version:** 1

## Agent Task Brief
Resolve the board root through voku\AgentLoop\ProjectLayout::boardRoot() instead of spelling a filesystem location in the UI. Prove it with a test that a state-root board is readable.
