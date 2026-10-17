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
    /** Größte JSON-Datei, die als Style geprüft und korrigiert wird */
    private const STYLE_MAX_BYTES = 3 * 1024 * 1024;
    /** Ausdrücke ohne Zahlenwerte als Argumente (Vergleiche, Bedingungen, Text) */
    private const GUARD_SKIP = ['==', '!=', '<', '<=', '>', '>=', '!', 'all', 'any', 'none', 'has', '!has', 'in', '!in',
        'to-boolean', 'to-string', 'typeof', 'geometry-type', 'id', 'properties', 'feature-state', 'zoom', 'literal',
        'within', 'distance', 'is-supported-script', 'collator', 'format', 'image', 'number-format', 'concat',
        'downcase', 'upcase', 'resolved-locale', 'at', 'index-of', 'slice', 'length'];

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

        if (!self::isAllowed($targetUrl, $allowedDomains)) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendContent('URL not allowed by proxy.');
            exit;
        }

        $hash = md5($targetUrl);
        $sub1 = substr($hash, 0, 2);
        $sub2 = substr($hash, 2, 2);
        $cacheBase = rex_path::addonCache('vector_maps', "proxy/$sub1/$sub2/$hash");

        // Gelegentlich aufräumen: ein zufälliger Unterordner, alte Dateien weg (begrenzter Aufwand je Anfrage)
        if (1 === random_int(1, 200)) {
            self::cleanupRandomDir();
        }

        $ttl = self::ttlFor($targetUrl);

        // Wildcard-Suche: Extension erst nach dem Fetch bekannt (OpenFreeMap hat keine Extensions in URLs)
        $cached = glob($cacheBase . '.*');
        $stale = null;
        if (is_array($cached) && !empty($cached) && is_file($cached[0])) {
            $ext = pathinfo($cached[0], PATHINFO_EXTENSION);
            if ('json' === strtolower($ext)) {
                self::sanitizeCachedStyleJsonFile($cached[0]);
            }
            if (time() - (int) @filemtime($cached[0]) < $ttl) {
                self::serveFile($cached[0], $ext, $ttl);
            }
            // abgelaufen: neu holen – scheitert das, die alte Fassung ausliefern
            $stale = $cached[0];
        }

        self::fetchAndCache($targetUrl, $cacheBase, $allowedDomains, $ttl, $stale);
    }

    /** Darf der Proxy diese Adresse abrufen? Nur https/http-Adressen, die mit einer erlaubten Domain beginnen. */
    private static function isAllowed(string $url, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $domain) {
            if (is_string($domain) && '' !== $domain && str_starts_with($url, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wie lange eine Antwort im Cache gilt (Sekunden): Kacheln, Schriften, Styles lange; Suche, Routen, Wetter kurz.
     */
    private static function ttlFor(string $url): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            in_array($host, ['api.brightsky.dev', 'api.open-meteo.com'], true) => 900,
            in_array($host, ['router.project-osrm.org', 'routing.openstreetmap.de', 'api.transitous.org'], true),
            str_contains($host, 'overpass-api.de') => 3600,
            'nominatim.openstreetmap.org' === $host => 86400,
            in_array($host, ['raw.githubusercontent.com', 'github.com', 'www.geoboundaries.org'], true) => 7 * 86400,
            in_array($host, ['tiles.openfreemap.org', 'api.maptiler.com', 'tiles.stadiamaps.com', 'cdn.protomaps.com',
                'server.arcgisonline.com', 'services.arcgisonline.com'], true),
            str_ends_with($host, 'tile.openstreetmap.org') => 30 * 86400,
            default => 86400,
        };
    }

    /** Einen zufälligen Cache-Unterordner aufräumen: Dateien älter als 60 Tage, liegengebliebene Temp-Dateien */
    private static function cleanupRandomDir(): void
    {
        $dir = rex_path::addonCache('vector_maps', 'proxy/' . bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(1)));
        if (!is_dir($dir)) {
            return;
        }
        $now = time();
        foreach ((array) @scandir($dir) as $name) {
            if (!is_string($name) || '.' === $name || '..' === $name) {
                continue;
            }
            $file = $dir . '/' . $name;
            $age = $now - (int) @filemtime($file);
            if (is_file($file) && ($age > 60 * 86400 || (str_ends_with($name, '.tmp') && $age > 3600))) {
                @unlink($file);
            }
        }
    }

    private static function fetchAndCache(string $url, string $cacheBase, array $allowedDomains, int $ttl, ?string $stale): void
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
            if (null !== $stale) {
                self::serveFile($stale, pathinfo($stale, PATHINFO_EXTENSION), 300);
            }
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
        // Weiterleitungen selbst verfolgen: jedes Ziel muss wieder auf der Positivliste stehen (kein Umweg auf fremde Hosts)
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // CURLOPT_ENCODING = '' lässt cURL gzip/brotli automatisch dekomprimieren
        // ohne das schickt CloudFlare komprimierte Daten, MapLibre kann sie nicht parsen
        curl_setopt($ch, CURLOPT_ENCODING, '');
        // Kurze Zeitlimits: ein langsamer Upstream darf keine PHP-Prozesse blockieren (Overpass rechnet länger)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $isOverpassInterpreter ? 25 : 10);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn ($r, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_BYTES ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        for ($hop = 0; $hop < 3 && $httpCode >= 300 && $httpCode < 400; ++$hop) {
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if ('' === $next || !str_starts_with($next, 'https://') || !self::isAllowed($next, $allowedDomains)) {
                break;
            }
            curl_setopt($ch, CURLOPT_URL, $next);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            $content = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        $rawContentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        // curl_close() hat seit PHP 8.0 keine Wirkung und ist ab PHP 8.5 deprecated – Handle wird automatisch freigegeben
        unset($ch);

        if ($httpCode !== 200 || false === $content) {
            // Zeitüberschreitung, Verbindungsfehler oder Serverfehler: Upstream kurz pausieren
            // (Overpass nicht: dort heißt ein Timeout meist nur „zu große Abfrage“)
            if (!$isOverpassInterpreter && (0 === $httpCode || 429 === $httpCode || $httpCode >= 500)) {
                self::pauseUpstream($host);
            }
            // abgelaufene Fassung ist besser als ein Fehler
            if (null !== $stale) {
                self::serveFile($stale, pathinfo($stale, PATHINFO_EXTENSION), 300);
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

        // andere Endung als die abgelaufene Fassung: alte Datei entfernen
        if (null !== $stale && $stale !== $cacheBase . '.' . $ext) {
            @unlink($stale);
        }
        $cacheFile = $cacheBase . '.' . $ext;
        self::writeAtomic($cacheFile, (string) $content);

        self::serveFile($cacheFile, $ext, $ttl);
    }

    private static function serveFile(string $filePath, string $ext, int $maxAge = 604800): void
    {
        $contentType = self::MIME_MAP[$ext] ?? 'application/octet-stream';

        rex_response::cleanOutputBuffers();
        // Browser-Cache wie Server-Cache (höchstens 7 Tage): Suche, Routen und Wetter bleiben aktuell
        rex_response::sendCacheControl('public, max-age=' . min($maxAge, 604800));
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
        // Nur kleine Dateien prüfen (Styles sind ein paar hundert KB, GeoJSON kann groß sein)
        if ((int) @filesize($filePath) > self::STYLE_MAX_BYTES) {
            return;
        }
        $raw = rex_file::get($filePath);
        // Reparatur nur nötig, wenn die Datei noch mehrfach eingewickelte Ausdrücke enthält (ältere Fassungen)
        if (!is_string($raw) || !str_contains($raw, '["coalesce",["coalesce"')) {
            return;
        }
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
        // Nur echte Style-Dokumente: klein genug und mit layers/sources – große GeoJSON-Antworten gar nicht dekodieren
        if (strlen($raw) > self::STYLE_MAX_BYTES || !str_contains($raw, '"layers"') || !str_contains($raw, '"sources"')) {
            return $raw;
        }
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
        $op = $value[0];
        if ('get' === $op) {
            return ['coalesce', $value, 0];
        }
        // Vergleiche, Bedingungen, Text: keine Zahlenwerte – nichts absichern
        if (in_array($op, self::GUARD_SKIP, true)) {
            return $value;
        }
        // nur Ausgabewerte absichern – Eingaben, Bedingungen und Stufen bleiben (wie vmGuardGets() in vectormaps.js)
        $last = count($value) - 1;
        $isOutput = match ($op) {
            'match' => static fn (int $i): bool => $i >= 3 && (1 === $i % 2 || $i === $last),
            'case' => static fn (int $i): bool => $i >= 2 && (0 === $i % 2 || $i === $last),
            'interpolate', 'interpolate-hcl', 'interpolate-lab' => static fn (int $i): bool => $i >= 4 && 0 === $i % 2,
            'step' => static fn (int $i): bool => $i >= 2 && 0 === $i % 2,
            'coalesce' => null,
            default => static fn (int $i): bool => $i >= 1,
        };
        if (null !== $isOutput) {
            $out = [];
            foreach ($value as $i => $entry) {
                $out[] = $isOutput($i) ? self::sanitizeNumericStyleExpression($entry) : $entry;
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
