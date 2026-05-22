<?php
// Path: public_html/admin/release-notes/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Release Notes Viewer 📜
 * -----------------------------------------------------------------------------
 * Renders the project CHANGELOG.md as nicely-formatted HTML so admins can see
 * what's shipped without leaving the portal. Resolves the relative path to
 * CHANGELOG.md from the deploy root (one level above PORTAL_ROOT on the
 * server, since CHANGELOG.md lives at the repo root, NOT inside web/).
 *
 * The file is uploaded as part of the shared deploy under <REMOTE_SHARED>/
 * — same directory as core/, vendor/, sql/. Falls back gracefully if the
 * file isn't found in the expected location.
 *
 * Renderer:
 *   - We don't have a Markdown lib vendored, so we ship a very small,
 *     paranoid renderer inline. It handles only the subset of Markdown
 *     our CHANGELOG actually uses (H1-H4, paragraphs, unordered lists,
 *     ordered lists, inline code, bold, italic, links, hr). Anything
 *     else is HTML-escaped and shown as-is.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

$pageTitle   = 'Release Notes';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Release Notes' => ''];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

/**
 * 🔍 Locate CHANGELOG.md. It lives one level above PORTAL_ROOT (web/) at
 * the repo root, which after deploy is the shared SFTP directory.
 */
$candidatePaths = [
    dirname(PORTAL_ROOT) . DIRECTORY_SEPARATOR . 'CHANGELOG.md',
    PORTAL_ROOT . DIRECTORY_SEPARATOR . 'CHANGELOG.md',
    PORTAL_ROOT . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'CHANGELOG.md',
];
$changelogPath = null;
foreach ($candidatePaths as $p) {
    if (is_readable($p) === true) {
        $changelogPath = $p;
        break;
    }
}

$changelogMd = $changelogPath !== null ? (string) file_get_contents($changelogPath) : '';

/**
 * 🪶 Mini Markdown -> HTML renderer.
 *
 * Intentionally minimal and HTML-escape-first. Handles:
 *   - # / ## / ### / #### headings
 *   - --- horizontal rules
 *   - * / - / + unordered list items
 *   - 1. ordered list items
 *   - **bold**, *italic*, `inline code`
 *   - [text](href) links (href whitelisted to http/https/mailto/relative)
 *   - paragraphs separated by blank lines
 *
 * Anything fancier (code fences, tables, blockquotes) renders as the
 * literal escaped text — that's intentional, we'd rather show the source
 * than execute a complex parser on user-derived input.
 *
 * @param string $markdown
 *
 * @return string HTML-safe rendered output
 */
function render_changelog_markdown(string $markdown): string
{
    $lines = preg_split("/\r\n|\n|\r/", $markdown);
    if ($lines === false) {
        return '';
    }
    $html = '';
    $listType = null;   // 'ul' | 'ol' | null
    $paragraph = '';

    $flushParagraph = function () use (&$paragraph, &$html): void {
        if ($paragraph !== '') {
            $html .= '<p>' . inline_md(trim($paragraph)) . '</p>' . "\n";
            $paragraph = '';
        }
    };
    $closeList = function () use (&$listType, &$html): void {
        if ($listType !== null) {
            $html .= '</' . $listType . '>' . "\n";
            $listType = null;
        }
    };

    foreach ($lines as $raw) {
        $line = $raw;

        // 🔚 Blank line — ends paragraph + list
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        // 📏 Horizontal rule
        if (preg_match('/^\s*---+\s*$/', $line) === 1) {
            $flushParagraph();
            $closeList();
            $html .= '<hr>' . "\n";
            continue;
        }

        // 🪧 Headings
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m) === 1) {
            $flushParagraph();
            $closeList();
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . inline_md(trim($m[2])) . '</h' . $level . '>' . "\n";
            continue;
        }

        // 📌 Unordered list item — accept *, -, or +
        if (preg_match('/^\s*[\*\-\+]\s+(.+)$/', $line, $m) === 1) {
            $flushParagraph();
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>' . "\n";
                $listType = 'ul';
            }
            $html .= '  <li>' . inline_md(trim($m[1])) . '</li>' . "\n";
            continue;
        }

        // 🔢 Ordered list item
        if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $m) === 1) {
            $flushParagraph();
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>' . "\n";
                $listType = 'ol';
            }
            $html .= '  <li>' . inline_md(trim($m[1])) . '</li>' . "\n";
            continue;
        }

        // 📰 Regular paragraph line — close any active list, accumulate
        $closeList();
        $paragraph .= ($paragraph === '' ? '' : ' ') . $line;
    }

    $flushParagraph();
    $closeList();
    return $html;
}

/**
 * Inline Markdown — applied to the contents of headings, list items,
 * paragraphs. Escapes HTML first, then re-introduces the small set of
 * inline elements we recognise.
 */
function inline_md(string $text): string
{
    // 🛡️ HTML-escape everything first; we'll re-introduce safe markup below.
    $out = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 🔗 Links: [text](href) — href whitelisted to http/https/mailto/relative.
    $out = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        static function (array $m): string {
            $label = $m[1];                                    // already escaped
            $href  = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $safe  = false;
            if (preg_match('#^(https?|mailto):#i', $href) === 1) {
                $safe = true;
            } elseif ($href !== '' && ($href[0] === '/' || $href[0] === '#')) {
                $safe = true;
            }
            if ($safe === false) {
                return '[' . $label . ']';
            }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                . (preg_match('#^https?:#i', $href) === 1 ? ' target="_blank" rel="noopener noreferrer"' : '')
                . '>' . $label . '</a>';
        },
        $out
    );

    // ✨ `code`
    $out = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $out);
    // ✨ **bold**
    $out = (string) preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $out);
    // ✨ *italic* (single asterisks) — but only if not adjacent to ** above
    $out = (string) preg_replace('/(?<![\*])\*([^\*\n]+)\*(?![\*])/', '<em>$1</em>', $out);

    return $out;
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-clipboard-list me-2"></i>Release Notes</h1>
        <p class="text-secondary mb-0">Rendered from <code>CHANGELOG.md</code>.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<?php if ($changelogPath === null): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>CHANGELOG.md not found.</strong> Looked in:
        <ul class="mb-0 mt-2 small">
            <?php foreach ($candidatePaths as $p): ?>
                <li><code><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></code></li>
            <?php endforeach; ?>
        </ul>
        <p class="mb-0 mt-2 small">
            On the server, the file lives at the shared SFTP root alongside <code>core/</code>.
        </p>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body portal-release-notes">
            <?php echo render_changelog_markdown($changelogMd); ?>
        </div>
        <div class="card-footer text-muted small">
            Source: <code><?php echo htmlspecialchars($changelogPath, ENT_QUOTES, 'UTF-8'); ?></code>
            &middot;
            Size:
            <?php echo number_format(strlen($changelogMd)); ?> bytes
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
