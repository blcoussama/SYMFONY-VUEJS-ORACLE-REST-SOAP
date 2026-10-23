<?php

declare(strict_types=1);

// Routeur du serveur PHP intégré (tests e2e uniquement) : fichiers de public/ servis tels quels,
// tout le reste confié au contrôleur frontal Symfony.
$public = dirname(__DIR__).'/public';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
if ('/' !== $path && is_file($public.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require $public.'/index.php';
