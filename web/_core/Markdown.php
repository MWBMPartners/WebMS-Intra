<?php
// Path: _core/Markdown.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Markdown Renderer ✍️
 * -----------------------------------------------------------------------------
 * Safe, minimal Markdown → HTML renderer for user-generated content
 * (announcements, prayer requests, tasks, care notes, project descriptions).
 *
 * Design constraints:
 *   • Input is escaped FIRST. No raw HTML passes through, ever.
 *   • Supports the common subset:
 *       **bold**, *italic*, ~~strike~~
 *       # h1 - ###### h6
 *       > blockquote (single level)
 *       - / * unordered list, 1. ordered list
 *       `inline code`, ```fenced code blocks```
 *       [link](url), bare http(s)://… autolinks
 *       --- horizontal rule
 *       paragraphs from blank lines
 *   • No images by default (set `allow_images=true` per call site).
 *   • No tables (anti-abuse default).
 *
 * For richer features (footnotes, GFM tables, definition lists), vendor
 * Parsedown later — `Markdown::render()` is the single call site to swap.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/270
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Markdown
{
    /**
     * Convert Markdown source to safe HTML.
     *
     * @param array{allow_images?: bool, allow_links?: bool, max_length?: int} $opts
     */
    public static function render(string $source, array $opts = []): string
    {
        $allowImages = (bool) ($opts['allow_images'] ?? false);
        $allowLinks  = (bool) ($opts['allow_links']  ?? true);
        $maxLength   = (int)  ($opts['max_length']   ?? 50000);

        if (strlen($source) > $maxLength) {
            $source = substr($source, 0, $maxLength) . "\n\n…";
        }

        // 🛡️ Escape EVERYTHING first. Subsequent transforms inject HTML
        //    intentionally; this is the only path raw user content can
        //    enter the output, and it's HTML-escaped at the gate.
        $text = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');

        // 🔁 Normalise line endings.
        $text = (string) preg_replace('/\r\n?/', "\n", $text);

        // 🪞 Block-level transforms operate line-by-line. We extract
        //    fenced code blocks first so their contents are preserved
        //    verbatim regardless of other patterns.
        $codeBlocks = [];
        $text = (string) preg_replace_callback(
            '/```(\w*)\n(.*?)\n```/s',
            static function (array $m) use (&$codeBlocks): string {
                $idx = count($codeBlocks);
                $codeBlocks[$idx] = '<pre><code class="language-'
                    . preg_replace('/[^A-Za-z0-9_\-]/', '', $m[1])
                    . '">' . $m[2] . '</code></pre>';
                return "\x01CODEBLOCK_{$idx}\x01";
            },
            $text
        );

        // 🪞 Split into "blocks" separated by blank lines.
        $blocks = preg_split('/\n{2,}/', $text);
        $out = [];
        foreach ((array) $blocks as $block) {
            $block = trim((string) $block, "\n");
            if ($block === '') {
                continue;
            }

            // Code block sentinel — restore as-is.
            if (preg_match('/^\x01CODEBLOCK_\d+\x01$/', $block) === 1) {
                $out[] = $block;
                continue;
            }

            // Headings: # / ## / ### / #### / ##### / ######
            if (preg_match('/^(#{1,6})\s+(.+)$/', $block, $m) === 1) {
                $level = strlen($m[1]);
                $out[] = '<h' . $level . '>' . self::inline(trim($m[2]), $allowLinks, $allowImages) . '</h' . $level . '>';
                continue;
            }

            // Horizontal rule
            if (preg_match('/^-{3,}$/', $block) === 1) {
                $out[] = '<hr>';
                continue;
            }

            // Blockquote (single level)
            if (str_starts_with($block, '&gt; ') || str_starts_with($block, '&gt;')) {
                $lines = explode("\n", $block);
                $inner = [];
                foreach ($lines as $ln) {
                    $inner[] = (string) preg_replace('/^&gt;\s?/', '', $ln);
                }
                $out[] = '<blockquote>' . self::inline(implode("\n", $inner), $allowLinks, $allowImages) . '</blockquote>';
                continue;
            }

            // Unordered list
            if (preg_match('/^[\-\*]\s+/m', $block) === 1) {
                $items = preg_split('/^[\-\*]\s+/m', $block);
                $html = '<ul>';
                foreach ((array) $items as $it) {
                    $it = trim((string) $it);
                    if ($it === '') {
                        continue;
                    }
                    $html .= '<li>' . self::inline($it, $allowLinks, $allowImages) . '</li>';
                }
                $html .= '</ul>';
                $out[] = $html;
                continue;
            }

            // Ordered list (only matches `1. ` style at line start)
            if (preg_match('/^\d+\.\s+/m', $block) === 1) {
                $items = preg_split('/^\d+\.\s+/m', $block);
                $html = '<ol>';
                foreach ((array) $items as $it) {
                    $it = trim((string) $it);
                    if ($it === '') {
                        continue;
                    }
                    $html .= '<li>' . self::inline($it, $allowLinks, $allowImages) . '</li>';
                }
                $html .= '</ol>';
                $out[] = $html;
                continue;
            }

            // Default: paragraph
            $out[] = '<p>' . self::inline($block, $allowLinks, $allowImages) . '</p>';
        }

        $html = implode("\n", $out);

        // 🔁 Restore fenced code blocks.
        $html = (string) preg_replace_callback(
            '/\x01CODEBLOCK_(\d+)\x01/',
            static function (array $m) use ($codeBlocks): string {
                return $codeBlocks[(int) $m[1]] ?? '';
            },
            $html
        );

        return $html;
    }

    /**
     * Inline transforms within a block (bold/italic/code/links/etc.).
     */
    private static function inline(string $text, bool $allowLinks, bool $allowImages): string
    {
        // 🪞 Single newline → <br> for readability.
        $text = str_replace("\n", "<br>", $text);

        // Inline code first (so its contents are not further transformed).
        $text = (string) preg_replace_callback(
            '/`([^`]+)`/',
            static fn (array $m): string => '<code>' . $m[1] . '</code>',
            $text
        );

        // Bold + italic + strike — order matters; bold (** **) before italic (* *).
        $text = (string) preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/\*([^\*]+)\*/',     '<em>$1</em>',         $text);
        $text = (string) preg_replace('/~~([^~]+)~~/',      '<del>$1</del>',       $text);

        // Images — only if explicitly enabled.
        if ($allowImages === true) {
            $text = (string) preg_replace_callback(
                '/!\[([^\]]*)\]\(([^)\s]+)\)/',
                static function (array $m): string {
                    $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    $src = self::sanitiseUrl($m[2]);
                    if ($src === null) {
                        return '';
                    }
                    return '<img src="' . $src . '" alt="' . $alt . '" loading="lazy">';
                },
                $text
            );
        }

        // Links — only if enabled.
        if ($allowLinks === true) {
            // [text](url)
            $text = (string) preg_replace_callback(
                '/\[([^\]]+)\]\(([^)\s]+)\)/',
                static function (array $m): string {
                    $url = self::sanitiseUrl($m[2]);
                    if ($url === null) {
                        return $m[1];
                    }
                    return '<a href="' . $url . '" rel="noopener nofollow ugc">' . $m[1] . '</a>';
                },
                $text
            );

            // Bare URLs — only http(s)://. Avoid matching inside an
            // already-emitted href attribute.
            $text = (string) preg_replace_callback(
                '/(?<!href=")\b(https?:\/\/[^\s<]+)/',
                static function (array $m): string {
                    $url = self::sanitiseUrl($m[1]);
                    if ($url === null) {
                        return $m[1];
                    }
                    return '<a href="' . $url . '" rel="noopener nofollow ugc">' . $m[1] . '</a>';
                },
                $text
            );
        }

        return $text;
    }

    /**
     * Allow only http(s) URLs and same-origin relative URLs. Returns the
     * sanitised value or null if rejected.
     */
    private static function sanitiseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        // Reject javascript:, data:, file:, etc.
        if (preg_match('/^[a-z]+:/i', $url) === 1) {
            if (preg_match('/^(https?|mailto):/i', $url) !== 1) {
                return null;
            }
        }
        // Allow #fragments and /paths.
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
