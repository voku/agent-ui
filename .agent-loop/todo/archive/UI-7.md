# UI-7: Task sub-navigation is inconsistent and leaves a route unreachable

- **Ticket:** UI-7
- **Lane:** VERIFY
- **Status:** done
- **Created:** 2026-08-28T19:59:11+00:00
- **Updated:** 2026-09-24T13:43:19+00:00
- **Summary:** Medium. Each task sub-page repeats a different partial button row with different labels and ordering; /task/{id}/learning is reachable only from Knowledge detail pages, and the task Prompt Workbench offers no way back.
- **Format version:** 1

## Handoff / Context
Verified against main bc55fff (agent-ui 0.18.7) on 2026-09-24: templates/layout/task-nav.php is the one shared task navigation with the current view marked.

## Agent Task Brief
Introduce one shared task navigation partial covering task, context, work, evidence, history, prompts and learning, with the current view marked.
