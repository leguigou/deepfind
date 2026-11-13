<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Settings;
use DeepFind\Store\Store;

final class SettingsController
{
    public const GROUPS = [
        'Anti-robots (commun à tous les projets)' => ['rate_per_minute', 'rate_per_hour', 'same_query_per_minute', 'burst_multiplier', 'violations_before_block', 'block_minutes', 'block_bad_user_agents', 'bad_user_agents', 'ip_allowlist'],
        'Recherche' => ['posts_limit', 'max_limit', 'cache_ttl', 'facet_values_limit'],
        'Widget sur le site' => ['widget_enter_submit'],
        'Journaux & import' => ['log_searches', 'max_drop_percent', 'log_retention_days'],
    ];

    public function index(): void
    {
        View::render('settings', ['active' => 'settings', 'groups' => self::GROUPS, 'values' => Settings::all()], 'Paramètres');
    }

    public function save(): void
    {
        foreach (self::GROUPS as $names) {
            foreach ($names as $name) {
                if (!array_key_exists($name, $_POST)) {
                    continue;
                }
                $v = trim((string) $_POST[$name]);
                if (in_array($name, Settings::BOOLEANS, true)) {
                    $v = $v === '1' ? '1' : '0';
                } elseif (!in_array($name, ['bad_user_agents', 'ip_allowlist'], true)) {
                    $v = (string) max(0, (float) str_replace(',', '.', $v));
                }
                if ($name === 'bad_user_agents' && $v !== '' && @preg_match('~' . str_replace('~', '\~', $v) . '~i', '') === false) {
                    Auth::flash('error', 'Motifs de user-agents invalides (expression régulière incorrecte) : non enregistrés.');
                    continue;
                }
                Settings::set($name, $v);
            }
        }
        Auth::flash('ok', 'Paramètres enregistrés (pris en compte immédiatement).');
        App::back('/admin/settings');
    }
}
