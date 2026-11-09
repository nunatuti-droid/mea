<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Sitzung ohne Cookies vor jeder Ausgabe starten, damit CSRF in Tests prüfbar ist.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();
