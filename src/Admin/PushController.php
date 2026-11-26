<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Push\Push;
use DeepFind\Push\WebPush;
use DeepFind\Settings;

/** Paramètres › Notifications (invitation, installation) et Recommandations › Envoi de notifications (messages). */
final class PushController
{
    /** Réglages modifiables : nom => type (bool, int, text, choice). */
    private const FIELDS = [
        'push_enabled' => 'bool', 'push_form' => 'choice', 'push_devices' => 'choice', 'push_exclude_ios' => 'bool', 'push_delay' => 'int',
        'push_min_pages' => 'int', 'push_exclude_paths' => 'text', 'push_title' => 'text', 'push_text' => 'text', 'push_accept' => 'text',
        'push_later' => 'text', 'push_thanks' => 'text', 'push_image' => 'url', 'push_icon' => 'url', 'push_color_bg' => 'color',
        'push_color_text' => 'color', 'push_color_button' => 'color', 'push_css' => 'css', 'push_remember_days' => 'int', 'push_selector' => 'text',
        'push_position' => 'choice', 'push_sw_path' => 'sw',
    ];

    public function settings(): void
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $values[$k] = Settings::get($k);
        }
        View::render('push_settings', [
            'active' => 'push_settings', 'v' => $values, 'vapid' => WebPush::vapid()['public'], 'counts' => Push::counts(Auth::lang()), 'stats' => Push::stats(30),
            'base' => rtrim((string) \DeepFind\Config::get('APP_URL', ''), '/'),
        ], 'Notifications');
    }

    public function saveSettings(): void
    {
        $errors = [];
        foreach (self::FIELDS as $k => $type) {
            $raw = (string) ($_POST[$k] ?? '');
            $v = match ($type) {
                'bool' => isset($_POST[$k]) ? '1' : '0',
                'int' => (string) max(0, min($k === 'push_remember_days' ? 365 : ($k === 'push_min_pages' ? 20 : 600), (int) $raw)),
                'choice' => $raw,
                'url' => Push::url($raw),
                'color' => Push::color($raw, Settings::DEFAULTS[$k]),
                'css' => Push::css($raw),
                'sw' => Push::swPath($raw),
                default => mb_substr(trim($raw), 0, $k === 'push_exclude_paths' ? 2000 : (Push::TEXT_FIELDS[$k] ?? 300), 'UTF-8'),
            };
            if ($type === 'choice') {
                $choices = ['push_form' => Push::FORMS, 'push_devices' => Push::DEVICES, 'push_position' => Push::POSITIONS][$k];
                $v = isset($choices[$v]) ? $v : (string) Settings::DEFAULTS[$k];
            }
            if ($type === 'url' && trim($raw) !== '' && $v === '') {
                $errors[] = ($k === 'push_icon' ? 'Icône' : 'Image') . ' : adresse complète (https://…) attendue.';
            }
            Settings::set($k, $v);
        }
        if (($_POST['push_form'] ?? '') === 'block' && trim((string) ($_POST['push_selector'] ?? '')) === '') {
            $errors[] = 'Bloc dans la page : indiquez le sélecteur CSS du bloc (ex. #deepfind-notifications).';
        }
        if (Settings::get('push_title') === '' && Settings::get('push_text') === '') {
            $errors[] = 'Indiquez au moins un titre ou un texte d\'invitation.';
        }
        Auth::flash($errors !== [] ? 'error' : 'ok', $errors !== [] ? 'Réglages enregistrés, à vérifier : ' . implode(' ', $errors)
            : 'Réglages des notifications enregistrés (pris en compte sur le site sous 5 minutes).');
        App::back('/admin/push/settings');
    }

    public function index(): void
    {
        $lang = Auth::lang();
        View::render('push', ['active' => 'push', 'list' => Push::campaigns($lang), 'counts' => Push::counts($lang), 'stats' => Push::stats(30),
            'enabled' => Push::enabled()], 'Envoi de notifications');
    }

    public function edit(): void
    {
        $lang = Auth::lang();
        $id = (int) ($_GET['id'] ?? 0);
        $c = $id > 0 ? Push::find($id, $lang) : null;
        if ($id > 0 && $c === null) {
            Auth::flash('error', 'Message introuvable.');
            App::back('/admin/push');
            return;
        }
        $c ??= Push::blank();
        $audience = [];
        foreach (array_keys(Push::DEVICES) as $d) {
            $audience[$d] = Push::audience(['devices' => $d, 'channels' => []], $lang);
        }
        View::render('push_edit', ['active' => 'push', 'c' => $c, 'audience' => $audience, 'counts' => Push::counts($lang),
            'icon' => Settings::get('push_icon'), 'host' => (string) parse_url((string) \DeepFind\Config::get('APP_URL', ''), PHP_URL_HOST)],
            $c['id'] ? 'Message « ' . $c['title'] . ' »' : 'Nouveau message');
    }

    public function save(): void
    {
        $lang = Auth::lang();
        [$c, $errors] = Push::sanitize($_POST);
        $id = (int) ($_POST['id'] ?? 0);
        if ($c === null) {
            Auth::flash('error', implode(' ', $errors));
            App::back('/admin/push/edit', $id ? ['id' => $id] : []);
            return;
        }
        $id = Push::save($lang, $c, $id > 0 ? $id : null);
        $n = Push::audience($c, $lang);
        Auth::flash('ok', match (true) {
            $c['status'] === 'draft' => 'Brouillon enregistré.',
            $c['scheduled_at'] <= date('Y-m-d H:i:s') => sprintf('Message programmé : envoi dans la minute à %d abonné(s).', $n),
            default => sprintf('Message programmé le %s pour %d abonné(s) (nombre actuel).', date('d/m/Y à H:i', strtotime((string) $c['scheduled_at'])), $n),
        });
        App::back('/admin/push');
    }

    public function delete(): void
    {
        Push::delete((int) ($_POST['id'] ?? 0), Auth::lang());
        Auth::flash('ok', 'Message supprimé.');
        App::back('/admin/push');
    }
}
