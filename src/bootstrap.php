<?php

declare(strict_types=1);

/*
 * Point d'entrée commun (web + CLI) : autoload sans Composer + chargement du .env.
 * Aucune dépendance externe : seul PHP et ses extensions sont nécessaires.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'DeepFind\\')) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

DeepFind\Config::load(getenv('DEEPFIND_ENV_FILE') ?: APP_ROOT . '/.env');
date_default_timezone_set(DeepFind\Config::get('APP_TIMEZONE', 'Europe/Paris'));
