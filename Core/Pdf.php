<?php
// Path: core/Pdf.php
/**
 * -----------------------------------------------------------------------------
 * PDF Generator 🖨️ (dompdf wrapper)
 * -----------------------------------------------------------------------------
 * Lightweight helper around self-hosted dompdf (no Composer).  Handles:
 *   • HTML → PDF rendering
 *   • Optional diagonal watermark text (e.g., NOT APPROVED / COMPLETE)
 *   • Saves to given file path, returns bool success.
 * -----------------------------------------------------------------------------
 * Usage:
 *   \Portal\Core\Pdf::create($html, '/_uploads/pdfs/claim_1.pdf', 'NOT APPROVED');
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

require_once PORTAL_VENDOR . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class Pdf
{
    /**
     * Render and save PDF. Returns file path on success or false.
     */
    public static function create(string $html, string $filePath, string $watermark = ''): string|false
    {
        // Inject watermark if provided
        if ($watermark !== '') {
            $html .= '<style>@page { margin:0 } body::before{content:"' . htmlspecialchars($watermark) . '"; position:fixed; top:45%; left:50%; transform:translate(-50%,-50%) rotate(-45deg); font-size:72px; color:rgba(200,0,0,0.15); z-index:-1;}</style>';
        }

        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Ensure directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $dompdf->output());
        return is_file($filePath) ? $filePath : false;
    }
}
