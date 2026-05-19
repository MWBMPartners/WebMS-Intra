<!--
WebMS Intra — Pull Request Template
Fill in the sections below. The security checklist is required for ALL PRs.
The PR Security Checks workflow will run on push and also flag heuristic
issues automatically; this checklist is your manual review pass.
-->

## Summary

<!-- 1-3 sentences: what does this PR do and why. -->

## Scope of changes

- [ ] Backend (PHP under `web/`)
- [ ] Frontend (CSS/JS under `web/public_html/assets/`)
- [ ] SQL migrations (`web/sql/`)
- [ ] CI / workflows (`.github/workflows/`)
- [ ] Documentation (CHANGELOG, DEV_NOTES, README, in-app help)
- [ ] Other: _____

## Test plan

<!-- Checklist of how you / a reviewer can verify this works. -->

- [ ] Verified locally on `php -S` dev server
- [ ] Tested the golden path
- [ ] Tested at least one edge case
- [ ] Verified no regressions in related apps

## Security review

**Every PR must complete this checklist.** Tick each row consciously.

### Input handling

- [ ] All user input from `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / URL params is validated and type-coerced before use
- [ ] No user input is interpolated directly into SQL — mysqli prepared statements with `bind_param` are used throughout
- [ ] No user input reaches `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`
- [ ] File-upload paths validate extension allowlist, size cap, and server-side MIME

### Output handling

- [ ] All variables echoed into HTML are passed through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- [ ] No `innerHTML =` of user-derived strings in JS — use `textContent` or DOM APIs
- [ ] JSON responses set `Content-Type: application/json` and use `json_encode` (not string concat)

### State-changing requests

- [ ] Every `POST`, `PUT`, `PATCH`, `DELETE` handler calls `Auth::verifyCsrf($_POST['csrf_token'] ?? '')` before any side-effect
- [ ] Forms with `method="post"` include `<input type="hidden" name="csrf_token" value="<?= ... ?>">`
- [ ] CSRF tokens are read once and rotated after sensitive actions (login, password change)

### AuthN / AuthZ

- [ ] Sensitive routes call `Auth::requireLogin()` (or the route is correctly flagged `isProtected=1` in `tblRoutes`)
- [ ] Admin-only routes call `App::isAdmin() === true` (or `isRootAdmin` for super-admin paths) before any work
- [ ] Site-aware routes call `App::isSiteAdmin()` / `App::isUmbrellaAdmin()` as appropriate
- [ ] Open redirects: any `$_GET['redirect']` is validated against an allowlist before `header('Location: …')`

### Secrets / credentials

- [ ] No DB credentials, API keys, tokens, encryption keys, or passwords are committed in code or config
- [ ] Sensitive settings written to `tblSettings` set `isSensitive=1` so they're encrypted at rest
- [ ] No `var_dump` / `print_r` of credentials, sessions, or full `$_SERVER` left in production paths
- [ ] No new files were added to `web/_auth_keys/`, `web/_uploads/`, or `web/_backups/`. **If any were, justify here:** ____

### Dependencies

- [ ] No new vendored library was added without committing the source under `web/vendor/` (or fetching it via `tools/download-X.sh`)
- [ ] If `tools/download-dompdf.sh` (or similar) was updated: the new version was checked for known CVEs

### Logging

- [ ] No `Logger::activity` or `Logger::error` call logs raw credentials, full session data, OAuth state, or CSRF tokens
- [ ] Errors leak to logs (not to users) — production env hides messages; dev env can show them

### Migrations

- [ ] Each new SQL file uses `IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE` so it can be re-run safely
- [ ] No `DROP`, `TRUNCATE`, `DELETE` without explicit comment justifying it
- [ ] If a column type or constraint changed: there's a rollback / forward path documented in the migration

## Deployment notes

- [ ] No manual server steps required to deploy this — OR — manual steps are listed below
- [ ] Targets: alpha → ☐ &nbsp;&nbsp; beta → ☐ &nbsp;&nbsp; main → ☐
- [ ] Tag a release? `v____` (only if this PR ships to `main` AND warrants a tag)

## Related issues

<!-- Closes #N, refs #N, etc. -->

---

<sub>This PR template is enforced by repo convention, not by CI. The PR Security Checks workflow runs additional automated heuristic scans on top of this manual review.</sub>
