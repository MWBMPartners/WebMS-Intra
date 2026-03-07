<?php
// Path: public_html/offline/index.php
/**
 * -----------------------------------------------------------------------------
 * Offline Fallback Page 📵
 * -----------------------------------------------------------------------------
 * Displayed by the service worker when the user is offline and the requested
 * page is not cached. Uses a self-contained layout (no DB-dependent templates)
 * to ensure it works without network access.
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.7.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline — Portal</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f8f9fa;
            color: #212529;
            text-align: center;
            padding: 2rem;
        }
        .offline-card {
            max-width: 420px;
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 2.5rem 2rem;
        }
        .offline-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        p { color: #6c757d; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .retry-btn {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
        }
        .retry-btn:hover { background: #0b5ed7; }
    </style>
    <script>
    (function(){
        var t = localStorage.getItem('portal-theme');
        if (t === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', t);
            document.body.style.background = '#1a1d21';
            document.body.style.color = '#e9ecef';
            var card = document.querySelector('.offline-card');
            if (card) { card.style.background = '#212529'; }
        }
    })();
    </script>
</head>
<body>
<div class="offline-card">
    <div class="offline-icon">&#128268;</div>
    <h1>You're Offline</h1>
    <p>
        It looks like you've lost your internet connection.
        Please check your connection and try again.
    </p>
    <button class="retry-btn" onclick="window.location.reload();">
        Try Again
    </button>
</div>
</body>
</html>
