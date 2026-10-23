<?php
// /sitemap.xml (via .htaccess): één pagina, lastmod = nieuwste aflevering.
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;

$app = new App(__DIR__);
$feed = $app->store->read('feed');
$ts = $feed['episodes'][0]['ts'] ?? filemtime(__DIR__ . '/site.json');
App::headers();
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n",
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n",
    '  <url><loc>', htmlspecialchars($app->cfg['site_url'] . '/', ENT_XML1), '</loc><lastmod>', date('c', (int) $ts),
    '</lastmod><changefreq>weekly</changefreq></url>', "\n",
    '</urlset>', "\n";
