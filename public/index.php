<?php

declare(strict_types=1);

/*
 * Contrôleur frontal unique (Apache : FallbackResource /index.php).
 *   /api/...    API publique du widget
 *   /admin/...  extranet de gestion
 */

// Serveur PHP intégré (développement uniquement) : laisser servir les fichiers statiques existants.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim((string) DeepFind\Config::get('APP_BASE_PATH', ''), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$path = '/' . trim($path, '/');

if (str_starts_with($path, '/api/')) {
    (new DeepFind\Http\ApiController())->handle($method, $path);
    return;
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    (new DeepFind\Admin\App())->handle($method, $path);
    return;
}

if ($path === '/') {
    header('Location: ' . $base . '/admin', true, 302);
    return;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
