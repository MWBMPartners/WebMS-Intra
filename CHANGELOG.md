# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-present

### Added

#### Core Framework
- **Router** (`core/Router.php`) - Front-controller dispatcher with clean URL routing via tblRoutes, hardcoded special routes (login, logout, MS365 OAuth, health check, API), and error page rendering
- **App Registry** (`core/App.php`) - Static service registry replacing `global $mysqli, $SETTINGS` pattern. Methods: `db()`, `settings()`, `user()`, `isDebug()`, `version()`, `env()`, `hasRole()`, `isAdmin()`, `isRootAdmin()`
- **Asset Loader** (`core/Asset.php`) - CDN-with-fallback asset loading for Bootstrap 5.3.3, Font Awesome 6.5.1, with SRI integrity checks and onerror fallback handlers
- **Avatar System** (`core/Avatar.php`) - Avatar cascade: MS365 URL -> local file -> Gravatar -> placeholder SVG
- **Debug Panel** (`core/Debug.php`) - Admin-only diagnostic overlay showing page load time, peak memory, PHP version, DB queries, session data (activated via `?debug=true`)
- **Captcha Helper** (`core/Captcha.php`) - Centralised CloudFlare Turnstile / reCAPTCHA support replacing duplicated loose functions
- **API Response** (`core/ApiResponse.php`) - Standardised JSON API response builder with consistent envelope format
- **Rate Limiter** (`core/RateLimiter.php`) - Database-backed login rate limiting via tblActivityLogs
- **Migrator** (`core/Migrator.php`) - Web-based SQL migration runner for environments without CLI access

#### Authentication
- RS256 JWT verification (`vendor/simplejwt/JWT.php`) using JWKS key fetching, ASN.1 DER key conversion, and standard claim validation
- Session hardening: HttpOnly, Secure, SameSite=Lax cookie parameters
- Session fixation prevention via `session_regenerate_id(true)` after login
- CSRF token rotation after successful verification
- Local account authentication with bcrypt password verification
- Rate limiting integration on login attempts

#### Template System
- Shared header/footer templates eliminating boilerplate duplication
- Responsive navbar with dynamic app links, user avatar dropdown, dark mode toggle
- Breadcrumb support
- Error pages: 404, 403, 500

#### Design System
- Custom CSS (`portal.css`) with CSS custom properties for theming
- Dark mode support via `[data-bs-theme="dark"]`
- `portal-data-list` / `portal-data-row` responsive table replacement
- Status badges, avatar component, file dropzone, empty state
- WCAG-compliant focus indicators
- Print styles

#### JavaScript
- Dark mode toggle with localStorage persistence
- AJAX helper with CSRF token support
- Toast notification system
- File dropzone drag-and-drop feedback

#### Infrastructure
- `.htaccess` URL rewriting for 3 deployment channels (public, alpha, beta)
- SQL migration system with 5 initial migrations (000-004)
- Health check endpoint (`/health`) for CI/CD monitoring
- API routing: `api/{app}/{action}` pattern

#### API Endpoints
- `GET /api/expenses/list` - Paginated expense claim listing with status filtering

### Changed
- `core/Auth.php` - `ensureSession()` made public, `curlPost()` made public, added JWT verification via JWKS, added local login, improved logout with proper cookie deletion
- `core/bootstrap.php` - Integrated App registry, Debug timer, SimpleJWT autoloader, improved error handlers
- All app files refactored to use template system

### Fixed
- Filesystem case-sensitivity bugs (`Core/` -> `core/`, `logger.php` -> `Logger.php`) that would break on Linux
- `vendor/simplejwt/JWT.php` was a copy of Auth.php - replaced with real JWT library
- Inline DDL (`CREATE TABLE IF NOT EXISTS`) in save handlers moved to proper migrations
- Duplicate captcha functions consolidated into Captcha class
