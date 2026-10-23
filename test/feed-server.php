<?php
// Nagebootste anchor.fm/YouTube voor de tests: serveert $FEED_DIR/feed.xml en youtube.xml, en thumbnails.
// $FEED_DIR/mode: "ok" (standaard), "500" of "garbage" om een kapotte feed na te bootsen.
$dir = getenv('FEED_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = is_file("$dir/mode") ? trim(file_get_contents("$dir/mode")) : 'ok';
@file_put_contents("$dir/hits.log", $path . "\n", FILE_APPEND);
if (preg_match('~^/vi/[A-Za-z0-9_-]{11}/(maxresdefault|hqdefault)\.jpg$~', $path)) {
    header('Content-Type: image/jpeg');
    readfile(__DIR__ . '/fixtures/thumb.jpg');
    return true;
}
if ($path === '/feed.xml' || $path === '/youtube.xml') {
    if ($path === '/feed.xml' && $mode === '500') {
        http_response_code(500);
        echo 'stuk';
        return true;
    }
    if ($path === '/feed.xml' && $mode === 'garbage') {
        header('Content-Type: application/rss+xml');
        echo '<html><body>Onderhoud</body>';
        return true;
    }
    if ($path === '/feed.xml' && $mode === 'slow') {
        sleep(6);
    }
    header('Content-Type: application/xml');
    readfile($dir . $path);
    return true;
}
http_response_code(404);
return true;
