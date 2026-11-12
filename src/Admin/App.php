<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Http\Response;

require_once __DIR__ . '/helpers.php';

/** Routeur de l'extranet. */
final class App
{
    private const ROUTES = [
        'GET /admin' => [DashboardController::class, 'index'],
        'GET /admin/sources' => [SourcesController::class, 'index'],
        'GET /admin/sources/edit' => [SourcesController::class, 'edit'],
        'POST /admin/sources/save' => [SourcesController::class, 'save'],
        'POST /admin/sources/delete' => [SourcesController::class, 'delete'],
        'POST /admin/sources/import' => [SourcesController::class, 'import'],
        'GET /admin/sources/test' => [SourcesController::class, 'test'],
        'POST /admin/sources/test' => [SourcesController::class, 'test'],
        'GET /admin/imports' => [SourcesController::class, 'imports'],
        'GET /admin/imports/view' => [SourcesController::class, 'importView'],
        'GET /admin/synonyms' => [RulesController::class, 'synonyms'],
        'POST /admin/synonyms/add' => [RulesController::class, 'synonymAdd'],
        'POST /admin/synonyms/delete' => [RulesController::class, 'synonymDelete'],
        'POST /admin/synonyms/import' => [RulesController::class, 'synonymImport'],
        'POST /admin/synonyms/sync' => [RulesController::class, 'synonymSync'],
        'GET /admin/rules' => [RulesController::class, 'rules'],
        'POST /admin/rules/add' => [RulesController::class, 'ruleAdd'],
        'POST /admin/rules/delete' => [RulesController::class, 'ruleDelete'],
        'POST /admin/rules/import' => [RulesController::class, 'ruleImport'],
        'GET /admin/redirects' => [RulesController::class, 'redirects'],
        'POST /admin/redirects/add' => [RulesController::class, 'redirectAdd'],
        'POST /admin/redirects/delete' => [RulesController::class, 'redirectDelete'],
        'POST /admin/redirects/import' => [RulesController::class, 'redirectImport'],
        'GET /admin/keys' => [KeysController::class, 'index'],
        'POST /admin/keys/create' => [KeysController::class, 'create'],
        'POST /admin/keys/update' => [KeysController::class, 'update'],
        'POST /admin/keys/delete' => [KeysController::class, 'delete'],
        'GET /admin/stats' => [StatsController::class, 'stats'],
        'GET /admin/logs' => [StatsController::class, 'logs'],
        'GET /admin/logs/export' => [StatsController::class, 'export'],
        'GET /admin/bots' => [BotsController::class, 'index'],
        'POST /admin/bots/block' => [BotsController::class, 'block'],
        'POST /admin/bots/unblock' => [BotsController::class, 'unblock'],
        'GET /admin/settings' => [SettingsController::class, 'index'],
        'POST /admin/settings/save' => [SettingsController::class, 'save'],
        'GET /admin/preview' => [PreviewController::class, 'preview'],
        'GET /admin/demo' => [PreviewController::class, 'demo'],
    ];

    public function handle(string $method, string $path): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src * data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
        header('Cache-Control: no-store');

        Auth::start();
        if ($method === 'POST' && !Auth::checkCsrf()) {
            http_response_code(400);
            echo 'Jeton de formulaire expiré : revenez en arrière et rechargez la page.';
            return;
        }

        if ($path === '/admin/login') {
            $this->login($method);
            return;
        }
        if (!Auth::check()) {
            Response::redirect(url('/admin/login'));
            return;
        }
        if ($path === '/admin/logout' && $method === 'POST') {
            Auth::logout();
            Response::redirect(url('/admin/login'));
            return;
        }
        if ($path === '/admin/lang' && $method === 'POST') {
            $l = (string) ($_POST['lang'] ?? '');
            if (in_array($l, Auth::langs(), true)) {
                $_SESSION['lang'] = $l;
            }
            $back = (string) ($_POST['back'] ?? '');
            Response::redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : url('/admin'));
            return;
        }

        $route = self::ROUTES[$method . ' ' . $path] ?? null;
        if ($route === null) {
            http_response_code(404);
            View::render('error', ['message' => 'Page introuvable.'], 'Introuvable');
            return;
        }
        try {
            [$class, $action] = $route;
            (new $class())->$action();
        } catch (\Throwable $e) {
            error_log('[deepfind admin] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            View::render('error', ['message' => $e->getMessage()], 'Erreur');
        }
    }

    private function login(string $method): void
    {
        $error = null;
        if ($method === 'POST') {
            $res = Auth::attempt((string) ($_POST['token'] ?? ''));
            if ($res === true) {
                Response::redirect(url('/admin'));
                return;
            }
            $error = $res;
        } elseif (Auth::check()) {
            Response::redirect(url('/admin'));
            return;
        }
        View::render('login', ['error' => $error], 'Connexion', false);
    }

    public static function back(string $path, array $query = []): void
    {
        Response::redirect(url($path, $query));
    }
}
