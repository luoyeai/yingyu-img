<?php

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~\A/(?:assets/[a-z0-9.-]+\.(?:css|js)|media/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.webp)\z~D', $uri) && is_file(__DIR__.'/../public'.$uri)) {
    return false;
}
require __DIR__.'/../public/index.php';
