<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Logging\Audience;
use DeepFind\Settings;

/** Analyse › Audience : statistiques globales quotidiennes (anonymes) et réglages de la mesure. */
final class AudienceController
{
    public const PERIODS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];

    public function index(): void
    {
        $days = $this->days();
        [$from, $to] = $this->range($days);
        View::render('audience', [
            'active' => 'audience', 'days' => $days, 'from' => $from, 'to' => $to,
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
            App::back('/admin/audience', ['days' => $this->days()]);
            return;
        }
        Settings::set('audience_pages', $pages);
        Settings::set('audience_paths', implode("\n", $paths));
        Settings::set('audience_paths_home', isset($_POST['paths_home']) ? '1' : '0');
        Auth::flash('ok', 'Réglages de la mesure d\'audience enregistrés (pris en compte sur le site sous 5 minutes).');
        App::back('/admin/audience', ['days' => $this->days()]);
    }

    /** Export CSV jour par jour (une ligne par jour et par appareil). Aucune donnée personnelle : totaux uniquement. */
    public function export(): void
    {
        [$from, $to] = $this->range($this->days());
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

    private function days(): int
    {
        $d = (int) ($_GET['days'] ?? $_POST['days'] ?? 30);
        return isset(self::PERIODS[$d]) ? $d : 30;
    }

    /** @return array{0:string,1:string} du … au (aujourd'hui inclus) */
    private function range(int $days): array
    {
        return [date('Y-m-d', strtotime('-' . ($days - 1) . ' days')), date('Y-m-d')];
    }
}
