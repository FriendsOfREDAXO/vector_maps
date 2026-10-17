<?php

namespace FriendsOfRedaxo\VectorMaps;

use rex_path;

/**
 * Kleine HTTP-Hilfe für serverseitige Abrufe (z. B. Wetter auf der Demo-Seite): kurze Zeitlimits, und nach einem
 * Fehlschlag wird derselbe Host einige Minuten lang nicht mehr gefragt – eine Seite darf nie lange hängen.
 */
final class Http
{
    private const PAUSE = 300;

    /** JSON abrufen; null bei Fehler, Zeitüberschreitung oder pausiertem Host */
    public static function getJson(string $url, int $timeout = 5): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $pause = rex_path::addonCache('vector_maps', 'http-pause/' . md5($host));
        if (is_file($pause) && time() - (int) @filemtime($pause) < self::PAUSE) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'REDAXO-VectorMaps/1 (+https://www.redaxo.org/)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if (200 !== $code || !is_string($raw)) {
            if (!is_dir(dirname($pause))) {
                @mkdir(dirname($pause), 0777, true);
            }
            @touch($pause);

            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
