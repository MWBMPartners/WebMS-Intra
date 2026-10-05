---
paths:
  - "web/_apps/**/api/**"
  - "web/_core/ApiRouter.php"
  - "web/_core/ApiResponse.php"
  - "web/_core/Router.php"
---

<!-- .claude/rules/api-router.md - moved word for word out of .claude/CLAUDE.md on 5 October 2026
     (owner's decision, Salem874). Claude Code loads this file only when it reads or edits a file
     matching the paths above, because the trap only matters there. Codex reads the same rule as
     rule 15 of .OpenAI/CONTEXT.md. If you change the rule, change both. -->

## ApiRouter routing trap (apply on every new api/* endpoint)

- **`api/*` paths IGNORE tblRoutes.** `Router::handleSpecialRoutes` intercepts them and hands off to `ApiRouter::dispatch`, which splits the path into `appName` + `action` and loads `_apps/{appName}/api/{action}.php`. Handler at any other path is unreachable.
- **Every endpoint needs `api.{appName}.{action}.enabled = 'true'`** seeded in `tblSettings` or ApiRouter returns 403.
- **Don't register `api/...` routes in `tblRoutes`** — either the handler is at the convention path (settings flag does the gating) or it's dead code.
- **Adjacent gotcha**: the `ApiResponse` class exposes `::success()`, NOT `::ok()`. `::setJsonHeaders()` is `private`. Grep `_core/ApiResponse.php` for method names before calling.
- **v1 facade (#323 Phase 2)**: the `/api/v1/{resource}` facade maps REST verbs onto the same `{app}/{action}` handler files + `api.{app}.{action}.enabled` flags (`ApiRouter::dispatchV1`) — no separate gating vocabulary, nothing registered in `tblRoutes` for it either.
