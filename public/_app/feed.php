<?php
// Parsen van de podcast-RSS en de YouTube-kanaalfeed naar platte arrays (die in de cache gaan).
declare(strict_types=1);

namespace Podsite;

defined('PODSITE') || exit;

final class Feed
{
    /**
     * RSS (Anchor/Spotify for Creators, maar elke itunes-feed werkt) → ['channel' => [...], 'episodes' => [...]].
     * Gooit een \RuntimeException als de feed niet bruikbaar is; de aanroeper houdt dan de oude cache.
     */
    public static function parsePodcast(string $xml): array
    {
        $doc = self::load($xml);
        $channel = $doc->channel ?? null;
        if (!$channel || !isset($channel->item)) {
            throw new \RuntimeException('feed zonder channel/item');
        }
        $it = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
        $chIt = $channel->children($it);
        $out = [
            'channel' => [
                'title' => self::text($channel->title),
                'description' => self::plain(self::text($channel->description)),
                'link' => self::url(self::text($channel->link)),
                'image' => self::url((string) ($chIt->image ? $chIt->image->attributes()->href : '')),
                'build' => self::text($channel->lastBuildDate),
            ],
            'episodes' => [],
        ];
        foreach ($channel->item as $item) {
            $ii = $item->children($it);
            $type = strtolower(self::text($ii->episodeType));
            if ($type === 'trailer') {
                continue;
            }
            $title = self::text($item->title);
            $ts = strtotime(self::text($item->pubDate));
            $audio = '';
            if (isset($item->enclosure)) {
                $audio = self::url((string) $item->enclosure->attributes()->url);
            }
            if ($title === '' || $ts === false) {
                continue;
            }
            $t = Titles::parse($title);
            if ($t['code'] === null && ctype_digit(self::text($ii->season)) && ctype_digit(self::text($ii->episode))) {
                $t['season'] = (int) self::text($ii->season);
                $t['number'] = (int) self::text($ii->episode);
                $t['code'] = sprintf('S%02dE%02d', $t['season'], $t['number']);
            }
            $out['episodes'][] = [
                'guid' => self::text($item->guid) ?: $title,
                'title' => $title,
                'code' => $t['code'],
                'season' => $t['season'],
                'number' => $t['number'],
                'rest' => $t['rest'],
                'description' => self::plain(self::text($item->description)),
                'ts' => $ts,
                'duration' => self::duration(self::text($ii->duration)),
                'audio' => $audio,
                'link' => self::url(self::text($item->link)),
            ];
        }
        if (!$out['episodes']) {
            throw new \RuntimeException('feed zonder afleveringen');
        }
        usort($out['episodes'], fn ($a, $b) => $b['ts'] <=> $a['ts']);
        return $out;
    }

    /** YouTube-kanaalfeed (Atom) → ['videos' => [code => ['id' =>, 'title' =>]]], alleen video's met een S..E..-code. */
    public static function parseYouTube(string $xml): array
    {
        $doc = self::load($xml);
        $videos = [];
        foreach ($doc->entry ?? [] as $entry) {
            $id = (string) $entry->children('http://www.youtube.com/xml/schemas/2015')->videoId;
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                continue;
            }
            $t = Titles::parse((string) $entry->title);
            // De feed staat nieuwste eerst: de eerste video met een code wint (herupload, korte versie).
            if ($t['code'] !== null && !isset($videos[$t['code']])) {
                $videos[$t['code']] = ['id' => $id, 'title' => (string) $entry->title];
            }
        }
        if (!isset($doc->entry)) {
            throw new \RuntimeException('YouTube-feed zonder entries');
        }
        return ['videos' => $videos];
    }

    private static function load(string $xml): \SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw new \RuntimeException('lege feed');
        }
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: geen externe entiteiten/DTD's ophalen.
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new \RuntimeException('feed is geen geldige XML');
        }
        return $doc;
    }

    private static function text($node): string
    {
        return trim((string) ($node ?? ''));
    }

    /** HTML uit de feed → platte tekst (escapen gebeurt pas bij het renderen). */
    public static function plain(string $html): string
    {
        $html = preg_replace('~<(br|/p|/li|/div)\b[^>]*>~i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? '';
        $text = preg_replace("/\s*\n\s*/", "\n", $text) ?? '';
        return trim($text);
    }

    /** Alleen http(s)-URL's; al het andere (javascript:, data:) wordt leeg. */
    public static function url(string $u): string
    {
        $u = trim($u);
        return preg_match('~^https?://[^\s<>"]+$~i', $u) ? $u : '';
    }

    /** itunes:duration ("01:09:55", "50:08" of seconden) → seconden. */
    public static function duration(string $d): int
    {
        if ($d === '') {
            return 0;
        }
        if (ctype_digit($d)) {
            return (int) $d;
        }
        $s = 0;
        foreach (explode(':', $d) as $part) {
            $s = $s * 60 + (int) $part;
        }
        return $s;
    }
}

/** Titels als "S04E11 - Gian van Veen over Winmau" ontleden: code, kop, ondertitel en gasten. */
final class Titles
{
    private const PARTICLES = ['van', 'de', 'der', 'den', 'het', "'t", 'ter', 'ten', 'te', 'la', 'le', 'von', 'di', 'da', 'du', 'el'];

    public static function parse(string $title): array
    {
        $out = ['code' => null, 'season' => null, 'number' => null, 'rest' => trim($title)];
        if (preg_match('/\bS(\d{1,2})\s*E(\d{1,3})\b/i', $title, $m, PREG_OFFSET_CAPTURE)) {
            $out['season'] = (int) $m[1][0];
            $out['number'] = (int) $m[2][0];
            $out['code'] = sprintf('S%02dE%02d', $out['season'], $out['number']);
            $after = substr($title, $m[0][1] + strlen($m[0][0]));
            $rest = trim(preg_replace('/^\s*[-–—:|]\s*/u', '', $after) ?? '');
            $out['rest'] = $rest !== '' ? $rest : trim($title);
        }
        return $out;
    }

    /**
     * Kop + ondertitel voor de hero: "Gian van Veen" + "over Winmau, …". Begint de titel niet met een naam, dan
     * is de hele titel de kop.
     */
    public static function headline(string $rest, array $stopwords): array
    {
        if (preg_match('/^(.+?)(\s+(?:over|bij|na|in|op)\s+.+|\s*\(.+\)\s*|\s+[-–—]\s+.+)$/u', $rest, $m)) {
            if (self::isName(self::stripNick($m[1]), $stopwords)) {
                $sub = trim($m[2]);
                $sub = trim(preg_replace('/^[-–—]\s*|^\(|\)$/u', '', $sub) ?? $sub);
                return [self::smartQuotes(trim($m[1])), $sub];
            }
        }
        return [self::smartQuotes($rest), ''];
    }

    /** Gastnamen uit een titelrest; leeg als er geen herkenbare naam in staat. */
    public static function guests(string $rest, array $stopwords): array
    {
        $found = [];
        if (preg_match('/\bmet\s+(.+?)(?:\)|\(|\s[-–—]\s|$)/iu', $rest, $m)) {
            foreach (preg_split('/\s*(?:&|,|\sen\s)\s*/u', $m[1]) ?: [] as $part) {
                // "bondscoach Ray de Vries" → "Ray de Vries"
                $part = preg_replace('/^(?:\p{Ll}[\p{L}-]*\s+)+(?=\p{Lu})/u', '', trim($part)) ?? '';
                $part = self::stripNick($part);
                if (self::isName($part, $stopwords)) {
                    $found[] = $part;
                }
            }
            return $found;
        }
        $candidate = preg_split('/\s+(?:over|bij|na|in|op)\s+|\s*\(|\s[-–—]\s|[:!?]/u', $rest)[0] ?? '';
        $candidate = self::stripNick(trim($candidate));
        return self::isName($candidate, $stopwords) ? [$candidate] : [];
    }

    public static function isName(string $s, array $stopwords): bool
    {
        $tokens = preg_split('/\s+/u', trim($s)) ?: [];
        $n = count($tokens);
        if ($n < 2 || $n > 5) {
            return false;
        }
        $stop = array_map('mb_strtolower', $stopwords);
        foreach ($tokens as $i => $tok) {
            if (in_array(mb_strtolower($tok), $stop, true)) {
                return false;
            }
            $cap = (bool) preg_match("/^\p{Lu}\p{Ll}+(?:['’-]\p{Lu}?\p{Ll}+)*$/u", $tok);
            $particle = in_array(mb_strtolower($tok), self::PARTICLES, true);
            if (($i === 0 || $i === $n - 1) ? !$cap : !($cap || $particle)) {
                return false;
            }
        }
        return true;
    }

    /** "Danny 'The Mullet' Jansen" → "Danny Jansen" (voor de gastenmuur). */
    private static function stripNick(string $s): string
    {
        return trim(preg_replace('/\s*[\'‘’"“”][^\'‘’"“”]+[\'‘’"“”]\s*/u', ' ', $s) ?? $s);
    }

    public static function smartQuotes(string $s): string
    {
        return preg_replace("/'([^']+)'/u", '‘$1’', $s) ?? $s;
    }
}
