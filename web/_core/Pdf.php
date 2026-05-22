<?php
// Path: web/core/Pdf.php
/**
 * -----------------------------------------------------------------------------
 * PDF Generator 🖨️ (dompdf wrapper)
 * -----------------------------------------------------------------------------
 * Lightweight helper around self-hosted dompdf (no Composer).  Handles:
 *   • HTML → PDF rendering
 *   • Optional diagonal watermark text (e.g., NOT APPROVED / COMPLETE)
 *   • Saves to given file path, returns bool success.
 *
 * dompdf must be manually uploaded to _libraries/dompdf/ on the server.
 * If it is not present, create() will log an error and return false.
 *
 * @see       https://github.com/dompdf/dompdf
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Pdf
{
    /** @var bool Whether dompdf has been loaded */
    private static bool $loaded = false;

    /**
     * Attempt to load the dompdf autoloader from _libraries/.
     * Returns true if dompdf is available, false otherwise.
     *
     * @return bool
     */
    private static function loadDompdf(): bool
    {
        if (self::$loaded === true) {
            return true;
        }

        // 📂 dompdf is stored in _libraries/dompdf/ (outside web root, manually uploaded)
        $autoloadPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_libraries'
                      . DIRECTORY_SEPARATOR . 'dompdf' . DIRECTORY_SEPARATOR . 'autoload.inc.php';

        if (is_readable($autoloadPath) === false) {
            Logger::errorPlatform(
                'dompdf',
                'Warning',
                'NOT_INSTALLED',
                'dompdf autoloader not found',
                'Expected at: ' . $autoloadPath . ' — Upload dompdf to _libraries/dompdf/'
            );
            return false;
        }

        require_once $autoloadPath;
        self::$loaded = true;
        return true;
    }

    /**
     * Render HTML to a PDF file and save it to the given path.
     * Optionally overlays a diagonal watermark (e.g. "NOT APPROVED", "COMPLETE").
     *
     * @param string $html      The HTML content to render
     * @param string $filePath  Absolute path where the PDF should be saved
     * @param string $watermark Optional diagonal watermark text
     *
     * @return string|false The saved file path on success, or false on failure
     */
    public static function create(string $html, string $filePath, string $watermark = ''): string|false
    {
        // 📦 Ensure dompdf is available
        if (self::loadDompdf() === false) {
            return false;
        }

        // 🏷️ Inject watermark CSS if provided
        if ($watermark !== '') {
            $html .= '<style>'
                . '@page { margin:0 } '
                . 'body::before{'
                . 'content:"' . htmlspecialchars($watermark, ENT_QUOTES, 'UTF-8') . '"; '
                . 'position:fixed; top:45%; left:50%; '
                . 'transform:translate(-50%,-50%) rotate(-45deg); '
                . 'font-size:72px; color:rgba(200,0,0,0.15); z-index:-1;'
                . '}'
                . '</style>';
        }

        // 🖨️ Render the PDF using dompdf
        $opts = new \Dompdf\Options();
        $opts->set('isRemoteEnabled', false); // 🛡️ SSRF prevention
        $dompdf = new \Dompdf\Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // 📁 Ensure the target directory exists
        $dir = dirname($filePath);
        if (is_dir($dir) === false) {
            mkdir($dir, 0755, true);
        }

        // 💾 Write the PDF output to file
        file_put_contents($filePath, $dompdf->output());
        if (is_file($filePath) === true) {
            return $filePath;
        }

        return false;
    }
}
