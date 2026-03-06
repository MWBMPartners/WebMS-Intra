<?php
// Path: public_html/index.php  (front-controller)
/**
 * -----------------------------------------------------------------------------
 * Portal Front Controller 🎯
 * -----------------------------------------------------------------------------
 * Single entry point for all live traffic.  Loads bootstrap then hands the
 * request to Core\Router.  Default route keys come from tblRoutes; if URL is
 * empty, Router maps it to "dashboard" (see Router::extractPath).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Router;

Router::dispatch($mysqli);