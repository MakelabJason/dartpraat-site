<?php
// /status.json (via .htaccess) voor de bewaking: laatste feeditem, cache-leeftijd, laatste fout.
declare(strict_types=1);

define('PODSITE', 1);
require __DIR__ . '/_app/boot.php';

use Podsite\App;

$app = new App(__DIR__);
$now = time();
$feed = $app->store->read('feed');
$state = $app->store->read('state') ?? [];
$latest = $feed['episodes'][0] ?? null;
$age = isset($state['feed']['fetched_at']) ? $now - (int) $state['feed']['fetched_at'] : null;
$ttl = $app->cfg['ttl'];
$out = [
    // ok = er is een feed in de cache en die is niet veel ouder dan de TTL (één mislukte ronde mag).
    'ok' => $latest !== null && $age !== null && $age < 3 * $ttl + 300,
    'now' => date('c', $now),
    'latest' => $latest ? [
        'code' => $latest['code'],
        'title' => $latest['title'],
        'published' => date('c', $latest['ts']),
        'guid' => $latest['guid'],
    ] : null,
    'episodes' => $feed ? count($feed['episodes']) : 0,
    'cache_age_s' => $age,
    'ttl_s' => $ttl,
    'last_attempt' => isset($state['feed']['last_attempt']) ? date('c', (int) $state['feed']['last_attempt']) : null,
    'last_error' => !empty($state['feed']['last_error'])
        ? ['at' => date('c', (int) $state['feed']['last_error']['at']), 'msg' => $state['feed']['last_error']['msg']] : null,
    'youtube' => [
        'cache_age_s' => isset($state['youtube']['fetched_at']) ? $now - (int) $state['youtube']['fetched_at'] : null,
        'last_error' => $state['youtube']['last_error']['msg'] ?? null,
        'latest_video_code' => array_key_first(($app->store->read('youtube') ?? [])['videos'] ?? []),
    ],
];
App::headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
header('Content-Length: ' . strlen($json));
echo $json;
// Met PHP-FPM houdt ook een statuscheck de cache vers als er even geen bezoekers zijn.
$app->finishThenRefresh();
