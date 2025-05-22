# Cambridge Mill Road Seventh-day Adventist Portal

> **Project codename:** *PortMillSDA*
> Target PHP **8.4** · MySQL **8.0+** · DreamHost shared hosting (no CLI)

A modular, multi‑tenant administration portal designed for churches, charities, and any organisation that needs lightweight internal tools ("mini‑apps").  Initial release delivers an **Expenses** workflow followed by **Events** scheduling, with strong audit‑logging, OAuth‑based single‑sign‑on, and PDF reporting.

---

## 1 · Tech stack

| Layer        | Choice                                                                                      | Rationale                                         |
| ------------ | ------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| **Backend**  | PHP 8.4 (strict types), MySQL 8.0                                                           | Ubiquitous LAMP stack; DreamHost‑friendly.        |
| **Routing**  | Lightweight front‑controller (`public_html/index.php`) + DB‑backed router                   | Pretty URLs, app isolation, easy overrides.       |
| **Auth**     | Microsoft 365 (Graph v1.0) · optional Google Workspace · local accounts (bcrypt + WebAuthn) | Secure SSO for staff, flexibility for volunteers. |
| **UI**       | Bootstrap 5.3, Font Awesome 6, custom SCSS→CSS (pre‑compiled)                               | Responsive, WCAG 2.2 AA, dark‑mode.               |
| **PDF**      | dompdf 2.0 (vendored)                                                                       | Server‑side PDF without external service.         |
| **E‑mail**   | Microsoft Graph "SendAs" via shared mailbox                                                 | DKIM/DMARC compliance, modern auth.               |
| **Realtime** | AJAX long‑polling → future WebSockets                                                       | Works on shared hosting, upgrade‑ready.           |

---

## 2 · Repository layout (top‑level)

```
/ (repo root)
├── .github/
│   └── workflows/deploy.yml   # CI/CD → DreamHost
├── public_html/               # Production code (live)
├── beta_html/                 # Staging (beta)
├── alpha_html/                # Development (alpha)
├── vendor/                    # Manually vendored libs
├── docs/                      # Developer & admin docs
├── sql/                       # Migration scripts
├── tools/                     # Local helper scripts (no Composer)
└── README.md                  # ← you are here
```

> **Heads‑up:** DreamHost deploys via FTP/SFTP.  GitHub Actions will mirror **only changed files** on push (see workflow).

---

## 3 · Branching & workflow

* **main**   → production (`public_html`)
* **develop** → alpha (`alpha_html`)
* **release/** → beta (`beta_html`)
* **feat/**, **fix/** → short‑lived feature branches

### CI → CD

1. **Lint** PHP (`php -l`) and run any `phpunit` tests.
2. **Sync** files via `lftp` using secrets `DH_HOST`, `DH_USER`, `DH_PASS`.
3. Post‑deploy health check hits `/health` route.

---

## 4 · Local setup (Mac / Windows)

1. Install PHP 8.3+ and MySQL 8 locally.
2. Create a database matching `sql/000_init.sql`.
3. Copy `.env.example` → `.env` and set your credentials.
4. Run a local server:

   ```bash
   php -S localhost:8080 -t alpha_html
   ```
5. Sign in with a seeded admin account or via Microsoft 365 if tenant config is ready.

---

## 5 · Coding conventions

* **Strict types:** `declare(strict_types=1);` at the top of every PHP file.
* **PSR‑12** formatting (4‑space indent, no shorthand control structures).
* **Constants:** use PHP predefined constants (`DIRECTORY_SEPARATOR`, `PHP_EOL`, etc.).
* **DB access:** `mysqli` prepared statements only.
* **Logging:** all actions funnel through `Core\Logger` to `tblActivityLogs` / `tblErrors`.
* **Comments:** full‑line docblocks, emoji call‑outs where helpful (e.g., `// 🛡️ CSRF check`).

---

## 6 · Contributing

1. Fork → feature branch → pull request.
2. Write descriptive commits: `feat(expenses): add claim upload handler`.
3. Keep code self‑contained; no Composer dependencies.  Vendored libs go under `/vendor/`.
4. Ensure `php -l` and tests pass **before** pushing.

---

## 7 · Roadmap

| Phase                           | Status        |
| ------------------------------- | ------------- |
| 0 · Repo & CI                   | ☐ In‑progress |
| 1 · Core Framework              | ☐             |
| 2 · Auth Module                 | ☐             |
| 3 · Settings UI                 | ☐             |
| 4 · Expenses v1 – Intake        | ☐             |
| 5 · Expenses v2 – Approval      | ☐             |
| 6 · Expenses v3 – Reimbursement | ☐             |
| 7 · Portal Home                 | ☐             |
| 8 · Alpha/Beta Gatekeeper       | ☐             |
| 9 · Events scaffold             | ☐             |
| 10 · Polish & Docs              | ☐             |

---

### Licence

© 2025 Cambridge Mill Road Seven-day Adventist Church
