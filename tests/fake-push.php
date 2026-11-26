<?php
/**
 * Faux service d'envoi Web Push (tests) : enregistre chaque requête (en-têtes utiles et corps chiffré, en base64) dans le fichier
 * FAKE_PUSH_LOG, une ligne JSON par requête. Réponse : 410 si l'adresse contient « /gone », 500 si « /err », sinon 201.
 */
$log = getenv('FAKE_PUSH_LOG') ?: sys_get_temp_dir() . '/fake-push.log';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents($log, json_encode([
    'path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
    'ttl' => $_SERVER['HTTP_TTL'] ?? '', 'body' => base64_encode((string) file_get_contents('php://input')),
]) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(str_contains($path, '/gone') ? 410 : (str_contains($path, '/err') ? 500 : 201));
