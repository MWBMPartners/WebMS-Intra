<?php
// Path: _core/ApiRouter.php
/**
 * -----------------------------------------------------------------------------
 * API Request Router 🔌
 * -----------------------------------------------------------------------------
 * Handles dispatching of API requests (api/{appName}/{action}) to their
 * handler files. Separated from the main Router for single-responsibility.
 *
 * Since #478 it also refuses an endpoint whose app is switched off in
 * Admin → Apps: see requireAppSwitchedOn().
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/80
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class ApiRouter
{
    /**
     * 🔀 #323 Phase 2 — v1 resource segment → app directory. Identity today,
     * but the map lets a public resource name diverge from an internal app dir
     * later without breaking the URL. A segment absent from this list is an
     * unknown resource (404) — the ONLY resources the RESTful facade exposes.
     *
     * @var array<string, string>
     */
    private const V1_RESOURCES = [
        'events'          => 'events',
        'announcements'   => 'announcements',
        'attendance'      => 'attendance',
        'prayer-requests' => 'prayer-requests',
        'documents'       => 'documents',
        'expenses'        => 'expenses',
        'leadership'      => 'leadership',
        'tasks'           => 'tasks',
        'noticeboard'     => 'noticeboard',
        'users'           => 'users',
        // 📦 Asset Tracker REST API (#406, Phase 2 Pass 4) — pure CRUD
        // (list/detail/create/update/delete all use the default action
        // names below), so no V1_CREATE_ACTION/V1_UPDATE_ACTION/
        // V1_DELETE_ACTION override is needed. `qr.php` (label image
        // streaming) is deliberately NOT part of this map — it isn't a
        // CRUD verb, so it stays reachable only at the legacy
        // `/api/assets/qr` path, same as e.g. `noticeboard/api/qr.php`.
        'assets'          => 'assets',
    ];

    /**
     * 🔀 Per-resource action-name overrides where a handler file is named
     * differently from pure CRUD. Everything not listed uses the CRUD default
     * (create/update/delete). Documented in api-spec.json so the mapping is
     * public. (POST → create default; noticeboard writes via save.php;
     * leadership create=assign, delete=unassign; prayer-requests update via
     * moderate.php; tasks update via complete.php — v1.0 has no tasks/update.php.)
     *
     * @var array<string, string>
     */
    private const V1_CREATE_ACTION = [
        'noticeboard' => 'save',
        'leadership'  => 'assign',
    ];
    private const V1_UPDATE_ACTION = [
        'prayer-requests' => 'moderate',
        'tasks'           => 'complete',
    ];
    private const V1_DELETE_ACTION = [
        'leadership' => 'unassign',
    ];

    /**
     * 🔌 Dispatch an API request to the appropriate handler.
     *
     * API routes follow the pattern: api/{appName}/{action}
     * which maps to: _apps/{appName}/api/{action}.php  (since #159)
     *
     * @param string $path The full API path (e.g. "api/expenses/list")
     * @return void
     */
    public static function dispatch(string $path): void
    {
        // 🔍 Parse the API path into components
        $parts = explode('/', $path);

        // 🔀 #323 Phase 2 — /api/v1/{resource}[/{id}] RESTful facade. Additive
        //    early return on the `v1` segment ONLY; the legacy flow below is
        //    byte-identical. `v1` was never a valid {appName} (no _apps/v1/ dir
        //    exists), so this shadows nothing that used to route.
        if (($parts[1] ?? '') === 'v1') {
            self::dispatchV1($parts);
        }

        // 📋 EXACTLY three parts: api / appName / action.
        //
        //    This said "at least" (`< 3`) until 27 October 2026, and then used
        //    only parts 1 and 2 — so `/api/events/list/extra` ran the events
        //    list handler as though the extra part were not there. Nothing
        //    escaped the api folder (appName and action are each checked against
        //    `^[a-z0-9\-]+$` below) and no switch was bypassed, so this was
        //    untidiness rather than a hole: an address outside the documented
        //    shape was answered instead of refused. Found by the Codex review.
        //
        //    Exactly three is safe to require: every legacy api address seeded
        //    in full_schema.sql has exactly three parts, and the `api/v1/...`
        //    addresses, which do have more, were already handed to dispatchV1()
        //    above and never reach this line.
        if (count($parts) !== 3) {
            ApiResponse::error('Invalid API path', 400);
        }

        $appName = $parts[1];
        $action  = $parts[2];

        // 🛡️ Sanitise to prevent directory traversal
        if (preg_match('/^[a-z0-9\-]+$/', $appName) !== 1
            || preg_match('/^[a-z0-9\-]+$/', $action) !== 1) {
            ApiResponse::error('Invalid API path characters', 400);
        }

        // 📂 Build the path to the API handler file
        $apiFile = PORTAL_APPS . DIRECTORY_SEPARATOR
                 . $appName . DIRECTORY_SEPARATOR
                 . 'api' . DIRECTORY_SEPARATOR
                 . $action . '.php';

        // ✅ Check the file exists
        if (is_readable($apiFile) === false) {
            ApiResponse::error('API endpoint not found', 404);
        }

        // 🚪 The app's own off switch (#478), before the per-endpoint one.
        self::requireAppSwitchedOn($appName);

        // 🔍 Check if the API endpoint is enabled in settings. For a bearer
        //    request the authoritative site is the KEY's site, not the
        //    host-detected site the bootstrap $SETTINGS snapshot was frozen for
        //    — resolve a per-site override against the key's site so a site's
        //    own kill-switch can't be bypassed via the Host header (#323 Phase 2
        //    review finding 1). Session/anonymous requests keep the snapshot.
        $enabled = self::resolveEnabledFlag('api.' . $appName . '.' . $action . '.enabled');

        if ($enabled !== 'true') {
            ApiResponse::error('This API endpoint is disabled', 403);
        }

        // 🚀 Include the API handler
        //    This require runs inside dispatch()'s METHOD scope, so — same as
        //    Router::dispatch() — legacy handlers referencing bare $mysqli /
        //    $SETTINGS would see them undefined without an explicit import.
        //    No-op for handlers already using App::db()/App::settings().
        global $mysqli, $SETTINGS; // #373 — expose bootstrap globals to controllers
        require $apiFile;
    }

    /**
     * 🚪 Refuse the request when the app this `api` folder belongs to is
     * switched off (#478). Used by BOTH ways in, dispatch() and
     * dispatchV1(), after the "does the handler file exist" test and before
     * the per-endpoint switch, so neither can drift from the other.
     *
     * WHAT WAS WRONG: nothing in this file asked the app registry anything.
     * Switching an app off in Admin → Apps hid its pages (the gate in
     * Router::dispatch()) while every one of its data endpoints went on
     * answering, for a signed-in person and for an API key alike. Each
     * endpoint has its own `api.{app}.{action}.enabled` switch, but those
     * are seeded on and are not what an administrator turns off.
     *
     * WHOSE SWITCH: for a request carrying a valid API key, the key's own
     * organisation's, whatever address the key was sent to, because that is
     * the organisation whose data the handler will answer with. For
     * everybody else, the organisation the address points at. This is the
     * same choice resolveEnabledFlag() below makes for the per-endpoint
     * switch, for the same reason.
     *
     * Which app a folder belongs to is the hand-written list
     * AppRegistry::API_FOLDER_APPS. A folder mapped to null there belongs
     * to the portal itself (`push`, `tours`, `users`) and is never refused
     * here. A folder with NO line is refused: this method cannot tell which
     * switch applies to it, and guessing "none" would bring back the fault
     * this fixes for every folder added later. That answer is the same
     * sentence as an endpoint whose own switch is missing, and the reason
     * is written to PHP's error log for whoever added the folder.
     *
     * WHAT THIS CANNOT DO: it does not look at who is asking. A person with
     * no right to the data is still refused by the handler, as before; and
     * the answer here does tell any caller that the app is switched off,
     * which the "app is disabled" page already tells anybody who opens one
     * of the app's pages (a signed-in person, or anybody at all on a page
     * that needs no sign-in).
     *
     * @param string $folder The folder under `_apps/` (already checked to
     *                       be lower-case letters, digits and hyphens).
     *
     * @return void Terminates with 403 when the app is switched off.
     */
    private static function requireAppSwitchedOn(string $folder): void
    {
        if (array_key_exists($folder, AppRegistry::API_FOLDER_APPS) === false) {
            error_log(
                'ApiRouter: the api folder "' . $folder . '" has no line in AppRegistry::API_FOLDER_APPS, '
                . 'so its endpoints are refused. Add a line saying which app it belongs to (or null for none).'
            );
            ApiResponse::error('This API endpoint is disabled', 403);
        }

        $slug = AppRegistry::API_FOLDER_APPS[$folder];
        if ($slug === null) {
            return; // belongs to the portal itself, not to an app
        }

        $keyRow     = ApiAuth::bearerKeyRow();
        $switchedOn = $keyRow !== null
            ? AppRegistry::isEnabledForSite($slug, (int) ($keyRow['siteID'] ?? 0))
            : AppRegistry::isEnabled($slug);

        if ($switchedOn === false) {
            $appName = (string) (AppRegistry::all()[$slug]['name'] ?? $slug);
            ApiResponse::error('The ' . $appName . ' app is switched off for this organisation.', 403);
        }
    }

    /**
     * Resolve an `api.*.enabled` flag against the correct tenant: the bearer
     * key's own site when the request carries a valid `wbms_` token, else the
     * bootstrap $SETTINGS snapshot (host-detected site). See #323 Phase 2
     * review finding 1.
     *
     * @param string $flagKey Flat setting key (e.g. 'api.events.create.enabled').
     *
     * @return string|null The effective value, or null if unset.
     */
    private static function resolveEnabledFlag(string $flagKey): ?string
    {
        $keyRow = ApiAuth::bearerKeyRow();
        if ($keyRow !== null) {
            return App::settingForSite($flagKey, (int) ($keyRow['siteID'] ?? 0));
        }
        global $SETTINGS;
        $resolved = self::resolveSetting($SETTINGS, $flagKey);
        return $resolved !== null ? (string) $resolved : null;
    }

    /**
     * 🔌 Dispatch `/api/v1/{resource}[/{id}]` by translating the (HTTP verb,
     * resource, id) triple to the legacy (app, action) pair, then reusing the
     * IDENTICAL gating + include path as legacy `/api/{app}/{action}`: same
     * handler file at `_apps/{app}/api/{action}.php`, same
     * `api.{app}.{action}.enabled` flag. No new gating vocabulary, no tblRoutes
     * rows, no duplicated handlers — this is the only shape that honours the
     * "ApiRouter routing trap" contract by construction.
     *
     * @param array<int, string> $parts The exploded path: ['api','v1',resource,id?].
     *
     * @return never Always terminates (include + exit, or an ApiResponse error).
     */
    private static function dispatchV1(array $parts): never
    {
        // 1️⃣ Resource — must be a known, charset-safe segment.
        $resource = (string) ($parts[2] ?? '');
        if (preg_match('/^[a-z0-9\-]+$/', $resource) !== 1
            || array_key_exists($resource, self::V1_RESOURCES) === false
        ) {
            ApiResponse::error('Unknown API resource', 404);
        }
        $app = self::V1_RESOURCES[$resource];

        // 2️⃣ Optional numeric id segment; any further segment is a 404.
        $idSeg = $parts[3] ?? null;
        if ($idSeg !== null && $idSeg !== '') {
            if (ctype_digit($idSeg) === false) {
                ApiResponse::error('Invalid resource id', 400);
            }
        } else {
            $idSeg = null;
        }
        if (isset($parts[4]) === true && $parts[4] !== '') {
            ApiResponse::error('Unknown API resource', 404);
        }

        // 3️⃣ Verb + id-shape → action (405 / 400 terminate inside).
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = self::v1Action($resource, $app, $method, $idSeg !== null);

        // 4️⃣ Hand the id to the handler exactly as the legacy path would carry
        //    it (write handlers read `$_GET['id'] ?? $body['…ID']`).
        if ($idSeg !== null) {
            $_GET['id'] = $idSeg;
        }

        // 5️⃣ Signal handlers (via ApiAuth::requireMethod) that the verb was
        //    already validated for shape here, so a legacy `POST`-only guard
        //    doesn't reject a valid PUT/PATCH/DELETE.
        if (defined('PORTAL_API_V1') === false) {
            define('PORTAL_API_V1', true);
        }

        // 6️⃣ Gate + include EXACTLY like legacy (same file convention, same flag).
        $apiFile = PORTAL_APPS . DIRECTORY_SEPARATOR
                 . $app . DIRECTORY_SEPARATOR
                 . 'api' . DIRECTORY_SEPARATOR
                 . $action . '.php';
        if (is_readable($apiFile) === false) {
            ApiResponse::error('API endpoint not found', 404);
        }

        // 🚪 The app's own off switch (#478), exactly as in dispatch().
        self::requireAppSwitchedOn($app);

        $enabled = self::resolveEnabledFlag('api.' . $app . '.' . $action . '.enabled');
        if ($enabled !== 'true') {
            ApiResponse::error('This API endpoint is disabled', 403);
        }

        global $mysqli, $SETTINGS; // #373 — expose bootstrap globals to controllers
        require $apiFile;
        exit();
    }

    /**
     * 🔁 Map an HTTP verb + id-shape to a handler action for a v1 resource,
     * applying the per-resource naming overrides. Terminates with 400 (bad id
     * shape) or 405 (+ Allow header) rather than returning on an invalid combo.
     *
     * @param string $resource Public resource segment (e.g. 'noticeboard').
     * @param string $app      Resolved app directory.
     * @param string $method   Upper-cased HTTP verb.
     * @param bool   $hasId    Whether a numeric id segment was supplied.
     *
     * @return string The action file base name (without .php).
     */
    private static function v1Action(string $resource, string $app, string $method, bool $hasId): string
    {
        $create = self::V1_CREATE_ACTION[$resource] ?? 'create';
        $update = self::V1_UPDATE_ACTION[$resource] ?? 'update';
        $delete = self::V1_DELETE_ACTION[$resource] ?? 'delete';

        switch ($method) {
            case 'GET':
                // Collection → list; member → detail (404s later if no detail.php).
                return $hasId === true ? 'detail' : 'list';
            case 'POST':
                if ($hasId === true) {
                    ApiResponse::error('POST does not take a resource id', 400);
                }
                return $create;
            case 'PUT':
            case 'PATCH':
                if ($hasId === false) {
                    ApiResponse::error('A resource id is required for update', 400);
                }
                return $update;
            case 'DELETE':
                if ($hasId === false) {
                    ApiResponse::error('A resource id is required for delete', 400);
                }
                return $delete;
            default:
                header('Allow: ' . self::v1AllowHeader($resource, $app));
                ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * 🧾 Build the `Allow` header for a resource from the handler files that
     * actually exist on disk (so it reflects real capability per resource).
     *
     * @param string $resource Public resource segment.
     * @param string $app      Resolved app directory.
     *
     * @return string Comma-separated verb list (e.g. "GET, POST, PUT, PATCH, DELETE").
     */
    private static function v1AllowHeader(string $resource, string $app): string
    {
        $base = PORTAL_APPS . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR;
        $verbs = [];
        if (is_readable($base . 'list.php') === true) {
            $verbs[] = 'GET';
        }
        if (is_readable($base . (self::V1_CREATE_ACTION[$resource] ?? 'create') . '.php') === true) {
            $verbs[] = 'POST';
        }
        if (is_readable($base . (self::V1_UPDATE_ACTION[$resource] ?? 'update') . '.php') === true) {
            $verbs[] = 'PUT';
            $verbs[] = 'PATCH';
        }
        if (is_readable($base . (self::V1_DELETE_ACTION[$resource] ?? 'delete') . '.php') === true) {
            $verbs[] = 'DELETE';
        }
        return implode(', ', array_values(array_unique($verbs)));
    }

    /**
     * 📋 Resolve a dot-notation setting key against the $SETTINGS array.
     *
     * @param array  $settings The multidimensional settings array
     * @param string $dotKey   Dot-separated key (e.g. "api.expenses.list.enabled")
     * @return mixed|null The setting value, or null if not found
     */
    public static function resolveSetting(array $settings, string $dotKey): mixed
    {
        $parts = explode('.', $dotKey);
        $node  = $settings;
        foreach ($parts as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
