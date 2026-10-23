<?php
// Lokale YouTube-thumbnails uit de cache (img.php?v=<video-id>&w=960|480); geen hotlink naar YouTube.
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;
use Podsite\Thumbs;

$app = new App(__DIR__);
$id = (string) ($_GET['v'] ?? '');
$w = (int) ($_GET['w'] ?? 960);
$w = in_array($w, Thumbs::SIZES, true) ? $w : 960;
$file = Thumbs::find($app->store->dir(), $id, $w) ?? __DIR__ . '/' . $app->site['images']['fallback'];

App::headers();
$etag = '"' . substr(md5($file . filemtime($file) . filesize($file)), 0, 16) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=604800');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
$ext = pathinfo($file, PATHINFO_EXTENSION);
header('Content-Type: ' . ($ext === 'jpg' ? 'image/jpeg' : 'image/webp'));
header('Content-Length: ' . filesize($file));
readfile($file);
