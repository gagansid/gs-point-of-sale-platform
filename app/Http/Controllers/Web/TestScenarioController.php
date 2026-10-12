<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Halaman test scenario QA (`/docs/test-scenarios`) untuk tim penguji: menyajikan
 * docs/testing/test-scenarios.html apa adanya (sumber tunggal, juga dibaca automation).
 * Akses sama dengan dokumentasi API: hanya local/staging (gate `viewApiDocs`). Folder docs/
 * tidak ikut deploy & paket rilis, jadi di server tanpa file ini → 404.
 */
final class TestScenarioController extends Controller
{
    public const PATH = 'docs/testing/test-scenarios.html';

    public function __invoke(): Response
    {
        $file = base_path(self::PATH);

        abort_unless(is_file($file), 404);

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
