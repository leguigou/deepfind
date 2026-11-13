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

/**
 * Liste déroulante des chemins détectés dans le flux (mapping visuel).
 * @param list<array{value:string, group:string, sample:string, coverage:int}> $options
 */
function select_paths(string $name, array $options, string $selected, string $empty = '— non mappé —', string $extraAttrs = '', bool $interpretBool = false): string
{
    $html = '<select name="' . e($name) . '" data-sample-select ' . $extraAttrs . '><option value="">' . e($empty) . '</option>';
    $values = array_column($options, 'value');
    if ($selected !== '' && !in_array($selected, $values, true)) {
        $html .= '<option value="' . e($selected) . '" selected data-sample="">' . e($selected) . ' (actuel, absent de l\'échantillon)</option>';
    }
    $byGroup = [];
    foreach ($options as $o) {
        $byGroup[$o['group']][] = $o;
    }
    foreach ($byGroup as $group => $opts) {
        $html .= '<optgroup label="' . e($group) . '">';
        foreach ($opts as $o) {
            $label = $o['value'] . ($o['coverage'] >= 0 && $o['coverage'] < 100 ? ' (' . $o['coverage'] . ' %)' : '');
            $interp = '';
            if ($interpretBool && $o['sample'] !== '') {
                // Montre comment chaque valeur d'exemple sera comprise (ex. in_stock → oui, out_of_stock → non)
                $interp = implode(', ', array_map(static fn ($v) => trim($v) . ' = ' . (DeepFind\Import\Mapper::toBool(trim($v)) ? 'oui' : 'non'), explode('|', $o['sample'])));
            }
            $html .= '<option value="' . e($o['value']) . '" data-sample="' . e($o['sample']) . '"' . ($interp !== '' ? ' data-interpret="' . e($interp) . '"' : '') . ($o['value'] === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select>';
}

/** @param list<string> $values */
function select_simple(string $name, array $values, string $selected, string $empty = '— aucun —', string $extraAttrs = ''): string
{
    $html = '<select name="' . e($name) . '" ' . $extraAttrs . '>' . ($empty !== '' ? '<option value="">' . e($empty) . '</option>' : '');
    foreach ($values as $v => $label) {
        $v = is_int($v) ? $label : $v;
        $html .= '<option value="' . e((string) $v) . '"' . ((string) $v === $selected ? ' selected' : '') . '>' . e((string) $label) . '</option>';
    }
    return $html . '</select>';
}

/** Logo DeepFind : loupe à anneaux concentriques (dégradé rose → corail). */
function logo_svg(int $size = 28, string $class = 'brand-mark'): string
{
    $id = 'dfg' . random_int(1000, 9999);
    return '<svg class="' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff4f8b"/><stop offset="1" stop-color="#ff6a5c"/></linearGradient></defs>'
        . '<circle cx="13.5" cy="13.5" r="10" fill="none" stroke="url(#' . $id . ')" stroke-width="3.4"/>'
        . '<circle cx="13.5" cy="13.5" r="5.6" fill="none" stroke="url(#' . $id . ')" stroke-width="1.6" opacity=".6"/>'
        . '<circle cx="13.5" cy="13.5" r="2.4" fill="url(#' . $id . ')"/>'
        . '<path d="M21.2 21.2 27.6 27.6" stroke="url(#' . $id . ')" stroke-width="4.2" stroke-linecap="round"/></svg>';
}

/** URL d'un fichier de public/ avec sa date de modification en version (?v=…) : le navigateur recharge après chaque mise à jour. */
function asset(string $path): string
{
    $file = APP_ROOT . '/public' . $path;
    return url($path) . '?v=' . (is_file($file) ? filemtime($file) : '1');
}

/** Texte échappé avec les mots de la recherche surlignés (<mark>), sans tenir compte des accents ni de la casse. */
function highlight(string $text, array $words): string
{
    if ($words === []) {
        return e($text);
    }
    $html = '';
    foreach (preg_split('/([\p{L}\p{N}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
        $hit = $i % 2 === 1 && DeepFind\Search\Relevance::matchesAny($words, DeepFind\Text::normalize($part));
        $html .= $hit ? '<mark>' . e($part) . '</mark>' : e($part);
    }
    return $html;
}
