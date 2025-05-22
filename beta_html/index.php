<?php
// Path: beta_html/index.php  (Beta environment entry)

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

\Portal\Core\Gatekeeper::enforce('beta');
\Portal\Core\Router::dispatch($mysqli);
