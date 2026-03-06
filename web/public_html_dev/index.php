<?php
// Path: public_html_dev/index.php  (Development front controller)
/**
 * -----------------------------------------------------------------------------
 * Portal Front Controller -- Development Channel 🧪
 * -----------------------------------------------------------------------------
 * Entry point for the development site (public_html_dev/).
 *
 * Identical to the production front controller except it enforces
 * Gatekeeper::enforce('dev') before dispatching.  Only users with Admin or
 * Root Admin flags -- or those whose roles match the portal.devAccessRoles
 * setting -- will be allowed past the gate.  Everyone else sees a 403.
 *
 * The Gatekeeper automatically allows login/logout/callback routes through
 * so the auth flow works without circular redirects.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Gatekeeper;
use Portal\Core\Router;

// 🚧 Enforce dev-channel access control
Gatekeeper::enforce('dev');

// 🎯 Dispatch the request through the normal router
Router::dispatch($mysqli);
