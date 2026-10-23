<?php
// Router voor `php -S` (lokaal en in de tests): doet wat public/.htaccess op Apache doet.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$map = ['/sitemap.xml' => 'sitemap.php', '/robots.txt' => 'robots.php', '/status.json' => 'status.php'];
if (isset($map[$path])) {
    require $_SERVER['DOCUMENT_ROOT'] . '/' . $map[$path];
    return true;
}
if (preg_match('~^/_app/|\.(lock|tmp|md)$~', $path)) {
    http_response_code(404);
    return true;
}
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false; // statisch bestand of andere .php
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
return true;
