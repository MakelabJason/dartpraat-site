<?php
// Podcastsite uit RSS: rendert de pagina uit de cache en ververst de feed pas ná het antwoord.
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;
use Podsite\View;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!in_array($path, ['/', '/index.php'], true) && PHP_SAPI !== 'cli') {
    http_response_code(404);
}

$app = new App(__DIR__);
$v = View::build($app, $app->data());
$v['beacon'] = !App::canFinishEarly() && $app->needsRefresh();
$v['asset_version'] = substr(md5((string) @filemtime(__DIR__ . '/assets/site.css') . @filemtime(__DIR__ . '/assets/site.js')), 0, 8);
$themeCss = View::themeCss($app->site['theme'] ?? []);

ob_start();
require __DIR__ . '/_app/template.php';
$html = (string) ob_get_clean();

if (PHP_SAPI !== 'cli') {
    App::headers(base64_encode(hash('sha256', $themeCss, true)));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('Content-Length: ' . strlen($html));
}
echo $html;
$app->finishThenRefresh();
