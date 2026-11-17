<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Logging\Audience;
use DeepFind\Settings;

/** Analyse › Audience : statistiques globales quotidiennes (anonymes) et réglages de la mesure. */
final class AudienceController
{
    public const PERIODS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];

    /** Période personnalisée : 2 ans au plus. */
    public const MAX_CUSTOM_DAYS = 731;

    public function index(): void
    {
        $p = $this->period();
        $days = $p['days'];
        [$from, $to] = [$p['from'], $p['to']];
        View::render('audience', [
            'active' => 'audience', 'days' => $days, 'from' => $from, 'to' => $to, 'custom' => $p['custom'], 'periodQuery' => $p['query'],
            'data' => Audience::summary($from, $to),
            'enabled' => Audience::enabled(),
            'tracks' => array_map(static fn ($k) => Settings::int('audience_' . $k) === 1, array_combine(array_keys(Audience::KINDS), array_keys(Audience::KINDS))),
            'pages' => Settings::get('audience_pages'),
            'paths' => Settings::get('audience_paths'),
            'pathsHome' => Settings::int('audience_paths_home') === 1,
        ], 'Audience');
    }

    public function settings(): void
    {
        Settings::set('audience_enabled', isset($_POST['enabled']) ? '1' : '0');
        foreach (array_keys(Audience::KINDS) as $k) {
            Settings::set('audience_' . $k, isset($_POST[$k]) ? '1' : '0');
        }
        $pages = (string) ($_POST['pages'] ?? 'all');
        $pages = isset(Audience::PAGES[$pages]) ? $pages : 'all';
        $paths = Audience::paths((string) ($_POST['paths'] ?? ''));
        if ($pages === 'paths' && $paths === [] && !isset($_POST['paths_home'])) {
            Auth::flash('error', 'Pages choisies : indiquez au moins un début d\'adresse (ex. /fr/plongee), ou cochez la page d\'accueil.');
            App::back('/admin/audience', $this->period()['query']);
            return;
        }
        Settings::set('audience_pages', $pages);
        Settings::set('audience_paths', implode("\n", $paths));
        Settings::set('audience_paths_home', isset($_POST['paths_home']) ? '1' : '0');
        Auth::flash('ok', 'Réglages de la mesure d\'audience enregistrés (pris en compte sur le site sous 5 minutes).');
        App::back('/admin/audience', $this->period()['query']);
    }

    /** Export CSV jour par jour (une ligne par jour et par appareil). Aucune donnée personnelle : totaux uniquement. */
    public function export(): void
    {
        ['from' => $from, 'to' => $to] = $this->period();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audience-' . $from . '-' . $to . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['jour', 'appareil', 'visiteurs', 'pages_vues', 'visiteurs_ayant_cherche', 'recherches', 'visiteurs_ayant_clique', 'clics',
            'visiteurs_1_recherche', 'visiteurs_2_3_recherches', 'visiteurs_4_recherches_et_plus'], ';', '"', '');
        foreach (Audience::series($from, $to) as $day => $byDevice) {
            foreach (Audience::DEVICES as $dev => $label) {
                if (!isset($byDevice[$dev])) {
                    continue;
                }
                $m = $byDevice[$dev];
                fputcsv($out, [$day, $label, ...array_map(static fn ($k) => $m[$k] ?? 0, Audience::METRICS)], ';', '"', '');
            }
        }
        fclose($out);
    }

    /**
     * Période affichée : un bouton (7 jours… 12 mois, jusqu'à aujourd'hui inclus) ou des dates choisies (from / to).
     * Dates personnalisées : remises dans l'ordre, jamais après aujourd'hui, 2 ans au plus.
     * @return array{days:int, from:string, to:string, custom:bool, query:array<string,string|int>}
     */
    public static function period(?array $in = null): array
    {
        $in ??= $_GET + $_POST;
        $today = date('Y-m-d');
        $valid = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $from = $in['from'] ?? null;
        $to = $in['to'] ?? null;
        if ($valid($from) && $valid($to)) {
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $to = min($to, $today);
            $from = min($from, $to);
            $min = date('Y-m-d', strtotime($to . ' -' . (self::MAX_CUSTOM_DAYS - 1) . ' days'));
            $from = max($from, $min);
            return ['days' => 0, 'from' => $from, 'to' => $to, 'custom' => true, 'query' => ['from' => $from, 'to' => $to]];
        }
        $d = (int) ($in['days'] ?? 30);
        $d = isset(self::PERIODS[$d]) ? $d : 30;
        return ['days' => $d, 'from' => date('Y-m-d', strtotime('-' . ($d - 1) . ' days')), 'to' => $today, 'custom' => false, 'query' => ['days' => $d]];
    }
}
