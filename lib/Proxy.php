<?php

namespace KLXM\VectorMaps;

use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use rex_request;
use rex_response;

class Proxy
{
    /** @var array<string,string> Extension → MIME-Type */
    private const MIME_MAP = [
        'pbf'  => 'application/vnd.mapbox-vector-tile',
        'mvt'  => 'application/vnd.mapbox-vector-tile',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** @var array<string,string> MIME-Type → Extension (für Content-Type-Erkennung ohne URL-Extension) */
    private const CT_TO_EXT = [
        'application/vnd.mapbox-vector-tile' => 'pbf',
        'application/x-protobuf'             => 'pbf',
        'application/json'                   => 'json',
        'text/javascript'                    => 'json',
        'application/javascript'             => 'json',
        'image/png'                          => 'png',
        'image/jpeg'                         => 'jpg',
        'image/webp'                         => 'webp',
        'image/gif'                          => 'gif',
    ];

    /** Upstream gilt nach einem Fehler so lange als gestört (Sekunden) – Anfragen gehen dann nicht mehr hinaus */
    private const FAIL_PAUSE = 30;
    /** Höchstgröße einer Antwort (Bytes) – größere Antworten werden abgebrochen */
    private const MAX_BYTES = 25 * 1024 * 1024;

    public static function intercept(): void
    {
        // Nie die Session sperren: Karten laden viele Kacheln parallel, mit offener Session warten sie aufeinander
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }
        $targetUrl = rex_request('target_url', 'string', '');

        if (!$targetUrl) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendContent('Missing target_url.');
            exit;
        }

        $allowedDomains = [
            // OpenFreeMap – Vektorkacheln, Styles, Fonts (Hauptanbieter)
            'https://tiles.openfreemap.org/',
            // OSM Nominatim – Adresssuche im Picker
            'https://nominatim.openstreetmap.org/',
            // Raw GitHub – statische Länder-Landflächen (GeoJSON)
            'https://raw.githubusercontent.com/',
            // GitHub raw-Redirects (u. a. aus geoBoundaries-Metadaten)
            'https://github.com/',
            // geoBoundaries API – liefert Download-Links für ADM0-Landflächen
            'https://www.geoboundaries.org/',
            // OSRM – Open Source Routing Machine (driving/walking/cycling)
            'https://router.project-osrm.org/',
            // OSM-Routing-Server: Fahrrad- und Fußgänger-Profile (via OSRM)
            'https://routing.openstreetmap.de/',
            // Transitous – ÖPNV-Routing (Motis2-API, Europa-weit)
            'https://api.transitous.org/',
            // MapTiler Cloud – Tiles, Styles, Georeferenzierung
            'https://api.maptiler.com/',
            // Stadia Maps (ex-Stamen) – Vektor- und Raster-Stile
            'https://tiles.stadiamaps.com/',
            // Protomaps CDN – Schriftarten (über PMTiles-Setup)
            'https://cdn.protomaps.com/',
            // OpenSTreetMap Community-Raster-Tiles
            'https://tile.openstreetmap.org/',
            // OSM-Tile-Spiegel
            'https://a.tile.openstreetmap.org/',
            'https://b.tile.openstreetmap.org/',
            'https://c.tile.openstreetmap.org/',
            // Overpass API – OSM-Datenabfragen (POI, Umgebungssuche)
            'https://overpass-api.de/',
            'https://lz4.overpass-api.de/',
            'https://z.overpass-api.de/',
            // Bright Sky – amtliche DWD-Wetterdaten (kostenlos, kein API-Key, Server in Deutschland)
            'https://api.brightsky.dev/',
            // Open-Meteo – weltweite Wettervorhersage (kostenlos, kein API-Key, Open-Source)
            'https://api.open-meteo.com/',
            // Esri ArcGIS Online – Satellitenbilder (World Imagery)
            'https://server.arcgisonline.com/',
            'https://services.arcgisonline.com/',
        ];

        // Manuell konfigurierte Domains aus den Einstellungen
        $configDomains = rex_addon::get('vector_maps')->getConfig('proxy_extra_domains', []);
        if (is_array($configDomains) && !empty($configDomains)) {
            $allowedDomains = array_merge($allowedDomains, $configDomains);
        }

        // Extension Point: andere AddOns können weitere Domains hinzufügen
        // Beispiel: rex_extension::register('VECTOR_MAPS_PROXY_DOMAINS', function($ep) {
        //     $list = $ep->getSubject();
        //     $list[] = 'https://my-tiles.example.com/';
        //     return $list;
        // });
        $allowedDomains = rex_extension::registerPoint(new rex_extension_point(
            'VECTOR_MAPS_PROXY_DOMAINS',
            $allowedDomains
        ));

        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            if (str_starts_with($targetUrl, $domain)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendContent('URL not allowed by proxy.');
            exit;
        }

        $hash = md5($targetUrl);
        $sub1 = substr($hash, 0, 2);
        $sub2 = substr($hash, 2, 2);
        $cacheBase = rex_path::addonCache('vector_maps', "proxy/$sub1/$sub2/$hash");

        // Wildcard-Suche: Extension erst nach dem Fetch bekannt (OpenFreeMap hat keine Extensions in URLs)
        $cached = glob($cacheBase . '.*');
        if (is_array($cached) && !empty($cached) && file_exists($cached[0])) {
            $ext = pathinfo($cached[0], PATHINFO_EXTENSION);
            if ('json' === strtolower($ext)) {
                self::sanitizeCachedStyleJsonFile($cached[0]);
            }
            self::serveFile($cached[0], $ext);
            exit;
        }

        self::fetchAndCache($targetUrl, $cacheBase);
    }

    private static function fetchAndCache(string $url, string $cacheBase): void
    {
        // PHP dekodiert GET-Parameter automatisch – Leerzeichen und Sonderzeichen
        // müssen vor dem cURL-Aufruf re-enkodiert werden, sonst lehnt der Server ab.
        // Robuster Ansatz: Leerzeichen mit preg_replace_callback gezielt enkodieren,
        // ohne andere bereits korrekt enkodierte Sequenzen zu doppelt-enkodieren.
        // OFM nutzt Komma als Fontstack-Trennzeichen: /fonts/Font A,Font B/0-255.pbf
        // Komma ist gültiges Sub-Delimiter (RFC 3986) und darf im Pfad bleiben.
        $safeUrl = preg_replace_callback(
            '/ /',  // nur echte Leerzeichen (kommen durch PHP-Decodierung von %20)
            static fn () => '%20',
            $url
        );
        // Zusätzlich den Pfad-Teil mit parse_url normalisieren, falls vorhanden
        $parts = parse_url($safeUrl);
        if (is_array($parts) && isset($parts['path'])) {
            $encodedPath = implode('/', array_map(static function (string $s): string {
                // Nur noch verbliebene Nicht-ASCII und unsafe Zeichen enkodieren.
                // %XX bereits kodierte Sequenzen bleiben unberührt (rawurlencode würde sie doppelt kodieren).
                return preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@%]/', static fn ($m) => rawurlencode($m[0]), $s) ?? $s;
            }, explode('/', $parts['path'])));
            $safeUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
                . $encodedPath
                . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        $parts = parse_url($safeUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        $isOverpassInterpreter = in_array($host, ['overpass-api.de', 'lz4.overpass-api.de', 'z.overpass-api.de'], true)
            && $path === '/api/interpreter';

        $overpassQueryData = null;
        if ($isOverpassInterpreter && '' !== $query) {
            $queryParams = [];
            parse_str($query, $queryParams);
            if (isset($queryParams['data']) && is_string($queryParams['data']) && '' !== $queryParams['data']) {
                $overpassQueryData = $queryParams['data'];
            }
        }

        // Upstream gerade gestört: sofort antworten statt einen PHP-Prozess bis zum Timeout zu belegen
        if (self::upstreamPaused($host)) {
            rex_response::setStatus(rex_response::HTTP_SERVICE_UNAVAILABLE);
            rex_response::setHeader('Retry-After', (string) self::FAIL_PAUSE);
            rex_response::sendContent('Proxy upstream temporarily unavailable.');
            exit;
        }

        $ch = curl_init();
        $userAgent = 'REDAXO-VectorMaps-Proxy/1.1 (+https://www.redaxo.org/)';
        $commonHeaders = ['Accept: */*'];
        if ($host === 'nominatim.openstreetmap.org') {
            $server = (string) \rex::getServer();
            if ('' !== $server) {
                $commonHeaders[] = 'Referer: ' . rtrim($server, '/') . '/';
            }
        }

        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        if (null !== $overpassQueryData) {
            $overpassUrl = ($parts['scheme'] ?? 'https') . '://' . $host . $path;
            curl_setopt($ch, CURLOPT_URL, $overpassUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . $overpassQueryData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($commonHeaders, [
                'Content-Type: application/x-www-form-urlencoded',
            ]));
        } else {
            curl_setopt($ch, CURLOPT_URL, $safeUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $commonHeaders);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // CURLOPT_ENCODING = '' lässt cURL gzip/brotli automatisch dekomprimieren
        // ohne das schickt CloudFlare komprimierte Daten, MapLibre kann sie nicht parsen
        curl_setopt($ch, CURLOPT_ENCODING, '');
        // Kurze Zeitlimits: ein langsamer Upstream darf keine PHP-Prozesse blockieren (Overpass rechnet länger)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $isOverpassInterpreter ? 20 : 10);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn ($r, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_BYTES ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawContentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        // curl_close() hat seit PHP 8.0 keine Wirkung und ist ab PHP 8.5 deprecated – Handle wird automatisch freigegeben
        unset($ch);

        if ($httpCode !== 200 || false === $content) {
            // Zeitüberschreitung, Verbindungsfehler oder Serverfehler: Upstream kurz pausieren
            if (0 === $httpCode || 429 === $httpCode || $httpCode >= 500) {
                self::pauseUpstream($host);
            }
            $status = ($httpCode >= 400 && $httpCode <= 599) ? $httpCode : 502;
            rex_response::setStatus($status);
            rex_response::sendContent("Proxy upstream failed (HTTP $httpCode).");
            exit;
        }

        // Content-Type ohne Charset etc. extrahieren
        $contentType = strtolower(trim((string) strtok($rawContentType, ';')));

        // Extension aus Content-Type ableiten
        $ext = self::CT_TO_EXT[$contentType] ?? '';

        // Fallback: Extension aus URL-Pfad
        if ('' === $ext) {
            $urlExt = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if ('' !== $urlExt && isset(self::MIME_MAP[$urlExt])) {
                $ext = $urlExt;
            }
        }

        // Letzter Fallback
        if ('' === $ext) {
            $ext = 'bin';
        }

        if ('json' === $ext) {
            $content = self::sanitizeStyleJsonString((string) $content);
        }

        $cacheFile = $cacheBase . '.' . $ext;
        self::writeAtomic($cacheFile, (string) $content);

        self::serveFile($cacheFile, $ext);
    }

    private static function serveFile(string $filePath, string $ext): void
    {
        $contentType = self::MIME_MAP[$ext] ?? 'application/octet-stream';

        rex_response::cleanOutputBuffers();
        rex_response::sendCacheControl('public, max-age=604800');
        rex_response::setHeader('Access-Control-Allow-Origin', '*');
        rex_response::sendFile($filePath, $contentType);
        exit;
    }

    /** Datei atomar schreiben (temporär + umbenennen) – parallele Leser bekommen nie eine halbe Datei */
    private static function writeAtomic(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // Punkt vorn: passt nicht auf das Suchmuster des Caches (hash.*)
        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (false === @file_put_contents($tmp, $content) || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    private static function pauseFile(string $host): string
    {
        return rex_path::addonCache('vector_maps', 'proxy/_paused/' . md5($host));
    }

    private static function upstreamPaused(string $host): bool
    {
        $file = self::pauseFile($host);

        return is_file($file) && (time() - (int) @filemtime($file)) < self::FAIL_PAUSE;
    }

    private static function pauseUpstream(string $host): void
    {
        self::writeAtomic(self::pauseFile($host), (string) time());
    }

    private static function sanitizeCachedStyleJsonFile(string $filePath): void
    {
        $raw = rex_file::get($filePath);
        if (!is_string($raw) || '' === $raw) {
            return;
        }

        // Korrektur ist idempotent: alte, mehrfach eingewickelte Einträge werden einmal repariert, danach bleibt die Datei
        $sanitized = self::sanitizeStyleJsonString($raw);
        if ($sanitized !== $raw) {
            self::writeAtomic($filePath, $sanitized);
        }
    }

    private static function sanitizeStyleJsonString(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $raw;
        }

        // Nur echte Style-Dokumente anpassen (nicht Geocoder-/Routing-JSON etc.).
        if (!isset($data['version'], $data['layers'], $data['sources']) || !is_array($data['layers'])) {
            return $raw;
        }

        $changed = false;

        foreach ($data['layers'] as $layerIndex => $layer) {
            if (!is_array($layer)) {
                continue;
            }

            foreach (['paint', 'layout'] as $section) {
                if (!isset($layer[$section]) || !is_array($layer[$section])) {
                    continue;
                }

                foreach ($layer[$section] as $prop => $value) {
                    if (!self::isLikelyNumericStyleProperty((string) $prop)) {
                        continue;
                    }

                    $patched = self::sanitizeNumericStyleExpression($value);
                    if ($patched !== $value) {
                        $data['layers'][$layerIndex][$section][$prop] = $patched;
                        $changed = true;
                    }
                }
            }
        }

        if (!$changed) {
            return $raw;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : $raw;
    }

    private static function isLikelyNumericStyleProperty(string $prop): bool
    {
        return (bool) preg_match('/(width|radius|opacity|size|height|base|blur|offset|padding|gap|spacing|max-width|line-height|letter-spacing|rotate)$/i', $prop);
    }

    /**
     * Rekursiv: null → 0 und ['get', …] → ['coalesce', ['get', …], 0] für numerische Style-Expressions.
     *
     * Idempotent (wie vmGuardGets() in vectormaps.js): Ein get direkt in einem coalesce ist schon abgesichert und
     * bleibt; verschachtelte coalesce werden eingeebnet, ein früher angehängter Fallback 0 mitten in der Kette
     * entfällt, Argumente hinter dem ersten festen Wert ebenso. Mehrfacher Aufruf ändert nichts mehr, und früher
     * mehrfach eingewickelte Styles im Cache werden wieder gerade.
     */
    public static function sanitizeNumericStyleExpression(mixed $value): mixed
    {
        if (null === $value) {
            return 0;
        }
        if (!is_array($value)) {
            return $value;
        }
        // Objekte (z. B. alte Stops-Funktionen) und Listen ohne Operator: nur die Werte bearbeiten
        if (!array_is_list($value) || [] === $value || !is_string($value[0])) {
            return array_map(self::sanitizeNumericStyleExpression(...), $value);
        }
        if ('literal' === $value[0]) {
            return $value;
        }
        if ('get' === $value[0]) {
            return ['coalesce', $value, 0];
        }
        if ('coalesce' !== $value[0]) {
            $out = [$value[0]];
            foreach (array_slice($value, 1) as $entry) {
                $out[] = self::sanitizeNumericStyleExpression($entry);
            }

            return $out;
        }

        $isGet = static fn (mixed $e): bool => is_array($e) && array_is_list($e) && 'get' === ($e[0] ?? null);
        $rest = array_slice($value, 1);
        $args = [];
        foreach ($rest as $i => $arg) {
            $done = $isGet($arg) ? $arg : self::sanitizeNumericStyleExpression($arg);
            if (is_array($done) && array_is_list($done) && 'coalesce' === ($done[0] ?? null)) {
                $inner = array_slice($done, 1);
                if ($i < count($rest) - 1 && count($inner) > 1 && 0 === end($inner)) {
                    array_pop($inner);
                }
                array_push($args, ...$inner);
            } else {
                $args[] = $done;
            }
        }
        // hinter dem ersten festen Wert wird nichts mehr ausgewertet
        foreach ($args as $i => $arg) {
            if (!is_array($arg)) {
                $args = array_slice($args, 0, $i + 1);
                break;
            }
        }
        if ($isGet(end($args))) {
            $args[] = 0;
        }

        return ['coalesce', ...$args];
    }
}
