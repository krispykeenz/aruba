<?php

define('ARUBA_IG_DEFAULT_PROFILE_URL', 'https://www.instagram.com/aruba_ct/');
define('ARUBA_IG_DEFAULT_CACHE_PATH', __DIR__ . '/cache/instagram-feed.json');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$configPath = __DIR__ . '/config.php';

try {
    if (!is_file($configPath)) {
        $cached = read_cache(ARUBA_IG_DEFAULT_CACHE_PATH);

        if ($cached !== null) {
            $cached['stale'] = true;
            send_json($cached, 200, true);
        }

        send_json(error_payload('Instagram feed is not configured.'), 503, false);
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException('Instagram feed config must return an array.');
    }

    $settings = normalize_config($config);
    $cached = read_cache($settings['cache_path']);

    if ($cached !== null && is_cache_fresh($settings['cache_path'], $settings['cache_ttl'])) {
        $cached['stale'] = false;
        send_json($cached, 200, true);
    }

    if ($settings['access_token'] === '') {
        if ($cached !== null) {
            $cached['stale'] = true;
            send_json($cached, 200, true);
        }

        send_json(error_payload('Instagram feed is not configured.', $settings['profile_url']), 503, false);
    }

    $fresh = fetch_instagram_feed($settings);
    write_cache($settings['cache_path'], $fresh);
    send_json($fresh, 200, true);
} catch (Throwable $error) {
    $profileUrl = isset($settings['profile_url']) ? $settings['profile_url'] : ARUBA_IG_DEFAULT_PROFILE_URL;
    $cachePath = isset($settings['cache_path']) ? $settings['cache_path'] : ARUBA_IG_DEFAULT_CACHE_PATH;
    $cached = read_cache($cachePath);

    if ($cached !== null) {
        $cached['stale'] = true;
        send_json($cached, 200, true);
    }

    send_json(error_payload('Instagram feed is temporarily unavailable.', $profileUrl), 502, false);
}

function normalize_config(array $config)
{
    $limit = isset($config['limit']) ? (int) $config['limit'] : 6;
    $cacheTtl = isset($config['cache_ttl']) ? (int) $config['cache_ttl'] : 3600;
    $requestTimeout = isset($config['request_timeout']) ? (int) $config['request_timeout'] : 10;
    $cachePath = trim((string) ($config['cache_path'] ?? ARUBA_IG_DEFAULT_CACHE_PATH));

    return [
        'access_token' => trim((string) ($config['access_token'] ?? '')),
        'api_url' => normalize_url((string) ($config['api_url'] ?? 'https://graph.instagram.com/v25.0/me/media'), 'https://graph.instagram.com/v25.0/me/media'),
        'profile_url' => normalize_url((string) ($config['profile_url'] ?? ARUBA_IG_DEFAULT_PROFILE_URL), ARUBA_IG_DEFAULT_PROFILE_URL),
        'limit' => max(1, min(12, $limit)),
        'cache_ttl' => max(60, $cacheTtl),
        'cache_path' => $cachePath === '' ? ARUBA_IG_DEFAULT_CACHE_PATH : $cachePath,
        'request_timeout' => max(2, min(30, $requestTimeout)),
    ];
}

function fetch_instagram_feed(array $settings)
{
    $url = append_query($settings['api_url'], [
        'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
        'limit' => $settings['limit'],
        'access_token' => $settings['access_token'],
    ]);

    $response = decode_json(fetch_url($url, $settings['request_timeout']));

    if (isset($response['error'])) {
        throw new RuntimeException('Instagram API returned an error.');
    }

    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException('Instagram API response did not include media data.');
    }

    $items = [];

    foreach ($response['data'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $type = strtoupper((string) ($entry['media_type'] ?? 'IMAGE'));
        $imageUrl = '';

        if ($type === 'VIDEO' && !empty($entry['thumbnail_url'])) {
            $imageUrl = normalize_url((string) $entry['thumbnail_url'], '');
        }

        if ($imageUrl === '' && !empty($entry['media_url'])) {
            $imageUrl = normalize_url((string) $entry['media_url'], '');
        }

        if ($imageUrl === '') {
            continue;
        }

        $items[] = [
            'id' => (string) ($entry['id'] ?? ''),
            'type' => $type,
            'imageUrl' => $imageUrl,
            'permalink' => normalize_url((string) ($entry['permalink'] ?? ''), $settings['profile_url']),
            'caption' => sanitize_caption((string) ($entry['caption'] ?? '')),
            'timestamp' => (string) ($entry['timestamp'] ?? ''),
        ];
    }

    return [
        'profileUrl' => $settings['profile_url'],
        'updatedAt' => date(DATE_ATOM),
        'stale' => false,
        'items' => array_slice($items, 0, $settings['limit']),
    ];
}

function fetch_url($url, $timeout)
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ArubaInstagramFeed/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($body === false || $errorNumber !== 0 || $status < 200 || $status >= 300) {
            throw new RuntimeException('Instagram API request failed.');
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "Accept: application/json\r\nUser-Agent: ArubaInstagramFeed/1.0\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('Instagram API request failed.');
    }

    return $body;
}

function append_query($url, array $params)
{
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . http_build_query($params);
}

function read_cache($path)
{
    if (!is_file($path)) {
        return null;
    }

    $contents = @file_get_contents($path);

    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return null;
    }

    return $decoded;
}

function write_cache($path, array $payload)
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }

    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function is_cache_fresh($path, $ttl)
{
    return is_file($path) && (time() - filemtime($path)) < $ttl;
}

function decode_json($body)
{
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response.');
    }

    return $decoded;
}

function normalize_url($url, $fallback)
{
    $url = trim($url);

    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return $fallback;
    }

    $parts = parse_url($url);
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

    if ($scheme !== 'https' && $scheme !== 'http') {
        return $fallback;
    }

    return $url;
}

function sanitize_caption($caption)
{
    $caption = trim(strip_tags($caption));

    if (function_exists('mb_substr')) {
        return mb_substr($caption, 0, 500);
    }

    return substr($caption, 0, 500);
}

function error_payload($message, $profileUrl = ARUBA_IG_DEFAULT_PROFILE_URL)
{
    return [
        'profileUrl' => $profileUrl,
        'updatedAt' => date(DATE_ATOM),
        'stale' => true,
        'items' => [],
        'error' => $message,
    ];
}

function send_json(array $payload, $statusCode, $cacheable)
{
    http_response_code($statusCode);
    header($cacheable ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
