<?php
// Verversen in een eigen verzoek (POST, beacon vanuit de pagina) voor servers zonder fastcgi_finish_request().
// Veilig om open te laten: er wordt alleen iets opgehaald als de TTL voorbij is, en nooit twee tegelijk (slot).
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;

App::headers();
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$app = new App(__DIR__);
$app->data();
http_response_code(204);
$app->finishThenRefresh(true);
