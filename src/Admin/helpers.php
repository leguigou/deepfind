<?php

declare(strict_types=1);

/* Fonctions utilitaires des gabarits de l'extranet. */

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path, array $query = []): string
{
    $base = rtrim((string) DeepFind\Config::get('APP_BASE_PATH', ''), '/');
    return $base . $path . ($query ? '?' . http_build_query($query) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(DeepFind\Admin\Auth::csrf()) . '">';
}

function fmt_dt(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : e($dt);
}

function fmt_n(int|float|string|null $n, int $dec = 0): string
{
    return number_format((float) $n, $dec, ',', "\u{202F}");
}

function badge(string $status): string
{
    $map = ['success' => 'ok', 'running' => 'info', 'queued' => 'info', 'failed' => 'ko', 'aborted' => 'warn', 'auto' => 'warn', 'manual' => 'info'];
    $labels = ['success' => 'Réussi', 'running' => 'En cours', 'queued' => 'En attente', 'failed' => 'Échec', 'aborted' => 'Bloqué', 'auto' => 'Auto', 'manual' => 'Manuel'];
    return '<span class="badge badge-' . ($map[$status] ?? 'info') . '">' . e($labels[$status] ?? $status) . '</span>';
}
