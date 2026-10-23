<?php
// Bestandscache + verversen: stale-while-revalidate met een slot, zodat de pagina nooit op de feed wacht.
declare(strict_types=1);

namespace Podsite;

defined('PODSITE') || exit;

final class Store
{
    public function __construct(private string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function read(string $name): ?array
    {
        $file = $this->dir . '/' . $name . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** Atomisch schrijven (tmp + rename): een lezer ziet altijd een hele versie. */
    public function write(string $name, array $data): void
    {
        $this->ensure($this->dir);
        $file = $this->dir . '/' . $name . '.json';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('cache niet schrijfbaar: ' . $this->dir);
        }
    }

    public function ensure(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('kan map niet maken: ' . $dir);
        }
    }

    /** Niet-blokkerend slot; null als iemand anders al ververst. */
    public function lock(string $name)
    {
        $this->ensure($this->dir);
        $fh = @fopen($this->dir . '/' . $name . '.lock', 'c');
        if (!$fh) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    public function unlock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

final class Refresher
{
    /** Na een mislukte poging zo lang wachten voor de volgende (anchor.fm niet bestoken als die plat ligt). */
    public const RETRY_AFTER = 60;
    /** Hoeveel afleveringen bovenaan een YouTube-thumbnail krijgen (hero + raster). */
    public const THUMBS = 8;

    public function __construct(private Store $store, private array $cfg, private ?\Closure $http = null)
    {
    }

    /** Is de feedcache ouder dan de TTL (en mag er weer geprobeerd worden)? */
    public function due(?int $now = null): bool
    {
        $now ??= time();
        $state = $this->store->read('state') ?? [];
        $fetched = (int) ($state['feed']['fetched_at'] ?? 0);
        $attempt = (int) ($state['feed']['last_attempt'] ?? 0);
        $failed = !empty($state['feed']['last_error']);
        return $now - $fetched >= $this->cfg['ttl']
            && (!$failed || $now - $attempt >= min(self::RETRY_AFTER, $this->cfg['ttl']));
    }

    /**
     * Ververst feed, YouTube en thumbnails onder een slot. Geeft false als een ander proces al bezig was.
     * Fouten komen in state.json; de laatste goede cache blijft staan.
     */
    public function run(bool $force = false, bool $withThumbs = true): bool
    {
        $lock = $this->store->lock('refresh');
        if (!$lock) {
            return false;
        }
        try {
            $state = $this->store->read('state') ?? [];
            $now = time();
            if (!$force && $now - (int) ($state['feed']['fetched_at'] ?? 0) < $this->cfg['ttl']) {
                return true; // een ander proces was ons net voor
            }
            $state['feed'] = $this->fetchInto('feed', $this->cfg['feed_url'], $state['feed'] ?? [],
                fn (string $body) => Feed::parsePodcast($body));
            $this->store->write('state', $state);
            if ($this->cfg['youtube_url'] !== '') {
                $state['youtube'] = $this->fetchInto('youtube', $this->cfg['youtube_url'], $state['youtube'] ?? [],
                    fn (string $body) => Feed::parseYouTube($body));
                $this->store->write('state', $state);
                if ($withThumbs) {
                    $this->thumbs();
                }
            }
            return true;
        } finally {
            $this->store->unlock($lock);
        }
    }

    /** Alleen ontbrekende thumbnails ophalen (na een koude start, buiten het antwoord om). */
    public function thumbsOnly(): void
    {
        $lock = $this->store->lock('refresh');
        if ($lock) {
            try {
                $this->thumbs();
            } finally {
                $this->store->unlock($lock);
            }
        }
    }

    private function fetchInto(string $name, string $url, array $st, \Closure $parse): array
    {
        $st['last_attempt'] = time();
        try {
            $headers = [];
            if (!empty($st['etag']) && $this->store->read($name)) {
                $headers[] = 'If-None-Match: ' . $st['etag'];
            }
            [$code, $body, $etag] = $this->get($url, $headers);
            if ($code === 304) {
                $st['fetched_at'] = time();
            } elseif ($code === 200) {
                $data = $parse($body);
                $this->store->write($name, $data);
                $st['fetched_at'] = time();
                $st['etag'] = $etag;
            } else {
                throw new \RuntimeException('HTTP ' . $code);
            }
            $st['last_error'] = null;
        } catch (\Throwable $e) {
            $st['last_error'] = ['at' => time(), 'msg' => substr($e->getMessage(), 0, 200)];
        }
        return $st;
    }

    /** Thumbnails van de bovenste afleveringen lokaal zetten (geen hotlink naar YouTube). */
    private function thumbs(): void
    {
        $feed = $this->store->read('feed');
        $yt = $this->store->read('youtube');
        if (!$feed || !$yt) {
            return;
        }
        $dir = $this->store->dir() . '/thumbs';
        $this->store->ensure($dir);
        foreach (array_slice($feed['episodes'], 0, self::THUMBS) as $ep) {
            $id = $yt['videos'][$ep['code'] ?? '']['id'] ?? null;
            if (!$id || Thumbs::find($this->store->dir(), $id, 960)) {
                continue;
            }
            foreach (['maxresdefault', 'hqdefault'] as $variant) {
                try {
                    [$code, $body] = $this->get($this->cfg['thumb_base'] . $id . '/' . $variant . '.jpg', []);
                } catch (\Throwable $e) {
                    break;
                }
                if ($code === 200 && strlen($body) > 2000 && Thumbs::save($dir, $id, $body)) {
                    break;
                }
            }
        }
    }

    /** @return array{0:int,1:string,2:string} status, body, etag */
    private function get(string $url, array $headers): array
    {
        if ($this->http) {
            return ($this->http)($url, $headers);
        }
        $timeout = (int) $this->cfg['timeout'];
        $etag = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'podsite/1 (+' . $this->cfg['site_url'] . ')',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$etag) {
                if (stripos($line, 'etag:') === 0) {
                    $etag = trim(substr($line, 5));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('ophalen mislukt: ' . $err);
        }
        if (strlen($body) > 8 * 1024 * 1024) {
            throw new \RuntimeException('feed te groot');
        }
        return [$code, $body, $etag];
    }
}

/** Lokale kopieën van YouTube-thumbnails: 960×540 en 480×270 webp (of de originele jpg zonder GD). */
final class Thumbs
{
    public const SIZES = [960, 480];

    public static function save(string $dir, string $id, string $jpg): bool
    {
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $src = @imagecreatefromstring($jpg);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                // Bijsnijden naar 16:9 (hqdefault heeft zwarte balken boven en onder).
                $ch = (int) round($w * 9 / 16);
                $y = max(0, (int) (($h - $ch) / 2));
                foreach (self::SIZES as $tw) {
                    $th = (int) ($tw * 9 / 16);
                    $dst = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($dst, $src, 0, 0, 0, $y, $tw, $th, $w, min($ch, $h));
                    imagewebp($dst, $dir . "/$id-$tw.webp.tmp", 78);
                    rename($dir . "/$id-$tw.webp.tmp", $dir . "/$id-$tw.webp");
                    imagedestroy($dst);
                }
                imagedestroy($src);
                return true;
            }
        }
        return @file_put_contents($dir . "/$id.jpg", $jpg) !== false;
    }

    /** Pad naar de thumbnail van een video in de gevraagde breedte, of null. */
    public static function find(string $cacheDir, string $id, int $w): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }
        foreach (["$id-$w.webp", "$id.jpg"] as $f) {
            if (is_file("$cacheDir/thumbs/$f")) {
                return "$cacheDir/thumbs/$f";
            }
        }
        return null;
    }
}
