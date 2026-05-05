<?php

return [
    // Copy this file to config.php on the server and paste the access token there.
    // Never commit config.php.
    'access_token' => 'PASTE_META_INSTAGRAM_ACCESS_TOKEN_HERE',

    // Instagram API with Instagram Login media endpoint.
    'api_url' => 'https://graph.instagram.com/v25.0/me/media',

    'profile_url' => 'https://www.instagram.com/aruba_ct/',
    'limit' => 6,
    'cache_ttl' => 3600,
    'cache_path' => __DIR__ . '/cache/instagram-feed.json',
    'request_timeout' => 10,
];
