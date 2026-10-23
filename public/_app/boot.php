<?php
// Opstart: site.json laden, cache kiezen, data klaarzetten. Gedeeld door index.php, img.php, sitemap/status.
declare(strict_types=1);

namespace Podsite;

defined('PODSITE') || exit;

require __DIR__ . '/feed.php';
require __DIR__ . '/store.php';
require __DIR__ . '/view.php';

final class App
{
    public array $site;
    public array $cfg;
    public Store $store;
    private bool $thumbsPending = false;

    public function __construct(string $publicDir)
    {
        $json = json_decode((string) file_get_contents($publicDir . '/site.json'), true);
        if (!is_array($json)) {
            throw new \RuntimeException('site.json is geen geldige JSON');
        }
        $this->site = $json;
        // Omgevingsvariabelen alleen voor tests en lokaal draaien; productie gebruikt site.json.
        $env = fn (string $k, $default) => getenv($k) !== false && getenv($k) !== '' ? getenv($k) : $default;
        $channel = $json['youtube']['channel_id'] ?? '';
        $this->cfg = [
            'site_url' => rtrim($json['site']['url'] ?? '', '/'),
            'feed_url' => $env('PODSITE_FEED_URL', $json['feed']['url']),
            'youtube_url' => $env('PODSITE_YOUTUBE_URL',
                $channel !== '' ? 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channel) : ''),
            'ttl' => (int) $env('PODSITE_TTL', $json['feed']['ttl'] ?? 900),
            'timeout' => (int) ($json['feed']['timeout'] ?? 3),
            'recent' => (int) ($json['feed']['recent'] ?? 6),
            'thumb_base' => $env('PODSITE_THUMB_BASE', 'https://i.ytimg.com/vi/'),
        ];
        $this->store = new Store($env('PODSITE_CACHE_DIR', $publicDir . '/../cache'));
        date_default_timezone_set($json['site']['timezone'] ?? 'Europe/Amsterdam');
    }

    public function refresher(): Refresher
    {
        return new Refresher($this->store, $this->cfg);
    }

    /**
     * Data voor de pagina. Alleen bij een volledig lege cache (eerste uitrol ooit) wordt hier gewacht, met de
     * feed-timeout als bovengrens; daarna altijd uit de cache.
     */
    public function data(): array
    {
        $feed = $this->store->read('feed');
        if ($feed === null && $this->refresher()->due()) {
            try {
                $this->refresher()->run(true, false);
                $this->thumbsPending = true;
            } catch (\Throwable $e) {
                // cache niet schrijfbaar: pagina toont de vaste inhoud zonder afleveringen
            }
            $feed = $this->store->read('feed');
        }
        $yt = $this->store->read('youtube') ?? ['videos' => []];
        return ['feed' => $feed, 'videos' => $yt['videos'] ?? []];
    }

    /** Moet er na dit verzoek ververst worden (TTL voorbij, of thumbnails na een koude start)? */
    public function needsRefresh(): bool
    {
        return $this->thumbsPending || $this->refresher()->due();
    }

    /** Kan deze server het antwoord afsluiten en daarna doorwerken (PHP-FPM)? Anders doet de pagina een beacon. */
    public static function canFinishEarly(): bool
    {
        return function_exists('fastcgi_finish_request');
    }

    /**
     * Ververs ná het antwoord, zodat de bezoeker nooit op de feed wacht. Zonder PHP-FPM gebeurt hier niets: de
     * pagina stuurt dan zelf een beacon naar refresh.php (zie site.js), dat in een eigen verzoek ververst.
     */
    public function finishThenRefresh(bool $force = false): void
    {
        if (!$force && (!self::canFinishEarly() || !$this->needsRefresh())) {
            return;
        }
        if (self::canFinishEarly()) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(60);
        try {
            $this->refresher()->due() ? $this->refresher()->run() : $this->refresher()->thumbsOnly();
        } catch (\Throwable $e) {
            error_log('podsite refresh: ' . $e->getMessage());
        }
    }

    /** Security-headers voor elke PHP-response; $styleHash = sha256 van de inline thema-stijl. */
    public static function headers(string $styleHash = ''): void
    {
        $style = "'self'" . ($styleHash !== '' ? " 'sha256-$styleHash'" : '');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src $style; img-src 'self' data:; "
            . "font-src 'self'; media-src https:; frame-src https://www.youtube-nocookie.com; connect-src 'self'; "
            . "object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    }
}
