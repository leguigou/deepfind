<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Db;
use DeepFind\Security\ApiKeys;

final class KeysController
{
    public function index(): void
    {
        View::render('keys', [
            'active' => 'keys',
            'keys' => Db::all('SELECT * FROM api_keys ORDER BY id'),
            'appUrl' => rtrim((string) Config::get('APP_URL', ''), '/'),
        ], 'Clés & intégration');
    }

    public function create(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Auth::flash('error', 'Nom obligatoire.');
        } else {
            $key = ApiKeys::create($name, (string) ($_POST['origins'] ?? ''), (string) ($_POST['langs'] ?? 'fr'));
            Auth::flash('ok', 'Clé créée : ' . $key);
        }
        App::back('/admin/keys');
    }

    public function update(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $row = Db::one('SELECT public_key FROM api_keys WHERE id = ?', [$id]);
        if ($row !== null) {
            Db::update('api_keys', [
                'allowed_origins' => ApiKeys::cleanOrigins((string) ($_POST['origins'] ?? '')),
                'langs' => preg_replace('/[^a-z,]/', '', strtolower((string) ($_POST['langs'] ?? 'fr'))) ?: 'fr',
                'active' => isset($_POST['active']) ? 1 : 0,
            ], 'id = ?', [$id]);
            ApiKeys::forget($row['public_key']);
            Auth::flash('ok', 'Clé mise à jour (prise en compte sous 1 minute).');
        }
        App::back('/admin/keys');
    }

    public function delete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $row = Db::one('SELECT public_key FROM api_keys WHERE id = ?', [$id]);
        if ($row !== null) {
            Db::exec('DELETE FROM api_keys WHERE id = ?', [$id]);
            ApiKeys::forget($row['public_key']);
            Auth::flash('ok', 'Clé supprimée.');
        }
        App::back('/admin/keys');
    }
}
