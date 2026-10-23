<?php
// /robots.txt (via .htaccess).
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;

$app = new App(__DIR__);
App::headers();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo "User-agent: *\nDisallow: /_app/\nDisallow: /status.json\n\nSitemap: ", $app->cfg['site_url'], "/sitemap.xml\n";
