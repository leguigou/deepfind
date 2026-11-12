<?php

declare(strict_types=1);

/*
 * FAUX serveur Meilisearch, uniquement pour les tests automatisés (php -S 127.0.0.1:7799 tests/fake-meili.php).
 * Il imite les appels utilisés par l'application avec un algorithme de recherche simplifié.
 * Il ne remplace PAS Meilisearch en production.
 */

$dir = getenv('FAKE_MEILI_DIR') ?: sys_get_temp_dir() . '/fake-meili';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode((string) file_get_contents('php://input'), true);

function out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function idxFile(string $uid): string
{
    global $dir;
    return $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $uid) . '.json';
}
function load(string $uid): ?array
{
    $f = idxFile($uid);
    return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
}
function save(string $uid, array $idx): void
{
    file_put_contents(idxFile($uid), json_encode($idx, JSON_UNESCAPED_UNICODE));
}
function task(): array
{
    global $dir;
    $n = (int) @file_get_contents("$dir/_task") + 1;
    file_put_contents("$dir/_task", (string) $n);
    return ['taskUid' => $n, 'status' => 'enqueued'];
}
function notFound(string $uid): void
{
    out(['message' => "Index `$uid` not found.", 'code' => 'index_not_found'], 404);
}
function norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
    return $s;
}
function tokens(string $s): array
{
    return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', norm($s)) ?: [], 'strlen'));
}
function getPath(array $doc, string $path)
{
    $v = $doc;
    foreach (explode('.', $path) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) {
            return null;
        }
        $v = $v[$p];
    }
    return $v;
}
function flat($v): array
{
    if ($v === null) {
        return [];
    }
    if (!is_array($v)) {
        return [$v];
    }
    $out = [];
    foreach ($v as $x) {
        foreach (flat($x) as $y) {
            $out[] = $y;
        }
    }
    return $out;
}
function clause(array $doc, string $c): bool
{
    $c = trim($c);
    if (preg_match('/^([\w.]+)\s+(NOT\s+)?IN\s*\[(.*)\]$/s', $c, $m)) {
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|([^,\s]+)/', $m[3], $vm, PREG_SET_ORDER);
        $vals = array_map(static fn ($x) => mb_strtolower(stripslashes($x[1] !== '' ? $x[1] : ($x[2] ?? ''))), $vm);
        $hit = (bool) array_intersect(array_map(static fn ($x) => mb_strtolower((string) $x), flat(getPath($doc, $m[1]))), $vals);
        return $m[2] !== '' ? !$hit : $hit;
    }
    if (preg_match('/^([\w.]+)\s*(=|!=|>=|<=|>|<)\s*(.+)$/s', $c, $m)) {
        $raw = trim($m[3]);
        $val = preg_match('/^"(.*)"$/s', $raw, $q) ? stripcslashes($q[1]) : $raw;
        foreach (flat(getPath($doc, $m[1])) as $dv) {
            $ok = match ($m[2]) {
                '=' => mb_strtolower((string) $dv) === mb_strtolower((string) $val),
                '!=' => mb_strtolower((string) $dv) !== mb_strtolower((string) $val),
                '>=' => (float) $dv >= (float) $val, '<=' => (float) $dv <= (float) $val,
                '>' => (float) $dv > (float) $val, '<' => (float) $dv < (float) $val,
            };
            if ($ok) {
                return $m[2] !== '!=';
            }
        }
        return $m[2] === '!=';
    }
    throw new RuntimeException('filtre non géré : ' . $c);
}
function filterOk(array $doc, $filter): bool
{
    if ($filter === null || $filter === [] || $filter === '') {
        return true;
    }
    if (is_string($filter)) {
        return clause($doc, $filter);
    }
    foreach ($filter as $f) {
        if (is_array($f)) {
            $any = false;
            foreach ($f as $or) {
                if (clause($doc, $or)) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                return false;
            }
        } elseif (!clause($doc, $f)) {
            return false;
        }
    }
    return true;
}
function osa(string $a, string $b): int
{
    $la = strlen($a); $lb = strlen($b); $d = [];
    for ($i = 0; $i <= $la; $i++) { $d[$i][0] = $i; }
    for ($j = 0; $j <= $lb; $j++) { $d[0][$j] = $j; }
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $c = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $c);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$la][$lb];
}
function wordMatch(string $w, array $docTokens, bool $prefix): ?int
{
    $best = null;
    foreach ($docTokens as $t) {
        if ($t === $w || ($prefix && str_starts_with($t, $w))) {
            return 0;
        }
        $len = mb_strlen($w);
        $max = $len >= 8 ? 2 : ($len >= 4 ? 1 : 0);
        if ($max > 0) {
            $d = osa($w, $prefix ? mb_substr($t, 0, $len) : $t);
            $d2 = osa($w, $t);
            $d = min($d, $d2);
            if ($d <= $max) {
                $best = min($best ?? 9, $d);
            }
        }
    }
    return $best;
}
function search(array $idx, array $q): array
{
    $t0 = microtime(true);
    $s = $idx['settings'];
    $words = tokens((string) ($q['q'] ?? ''));
    $syn = [];
    foreach ($s['synonyms'] ?? [] as $k => $vals) {
        $syn[norm((string) $k)] = array_map('norm', $vals);
    }
    $searchable = $s['searchableAttributes'] ?? ['*'];
    $hits = [];
    foreach ($idx['docs'] as $doc) {
        if (!filterOk($doc, $q['filter'] ?? null)) {
            continue;
        }
        if ($words === []) {
            $hits[] = ['doc' => $doc, 'm' => 0, 'typo' => 0, 'attr' => 0];
            continue;
        }
        $attrTokens = [];
        foreach ($searchable as $ai => $a) {
            $attrTokens[$ai] = tokens(implode(' ', array_map('strval', flat($a === '*' ? $doc : getPath($doc, $a)))));
        }
        $matched = 0;
        $typo = 0;
        $attrRank = 99;
        foreach ($words as $wi => $w) {
            $prefix = $wi === count($words) - 1;
            $found = null;
            foreach ($attrTokens as $ai => $toks) {
                $cands = [[$w]];
                foreach ($syn[$w] ?? [] as $alt) {
                    $cands[] = tokens($alt);
                }
                foreach ($cands as $group) {
                    $sum = 0;
                    foreach ($group as $cw) {
                        $d = wordMatch($cw, $toks, $prefix && count($group) === 1);
                        if ($d === null) { $sum = null; break; }
                        $sum += $d;
                    }
                    if ($sum !== null) {
                        $found = min($found ?? 9, $sum);
                        $attrRank = min($attrRank, $ai);
                    }
                }
                if ($found === 0) {
                    break;
                }
            }
            if ($found !== null) {
                $matched++;
                $typo += $found;
            }
        }
        if ($matched > 0) {
            $hits[] = ['doc' => $doc, 'm' => $matched, 'typo' => $typo, 'attr' => $attrRank];
        }
    }
    // Stratégie « last » : on garde les documents qui ont le plus de mots trouvés en priorité.
    $sort = $q['sort'] ?? [];
    $custom = [];
    foreach ($s['rankingRules'] ?? [] as $r) {
        if (preg_match('/^(\w+):(asc|desc)$/', $r, $m)) {
            $custom[] = [$m[1], $m[2]];
        }
    }
    if ($words !== []) {
        $all = count($words);
        $full = array_filter($hits, static fn ($h) => $h['m'] === $all);
        // matchingStrategy « all » : tous les mots obligatoires ; « last » (défaut) : repli sur les correspondances partielles
        $hits = ($q['matchingStrategy'] ?? 'last') === 'all' ? array_values($full) : ($full ?: $hits);
        foreach ($hits as &$h) {
            $h['score'] = max(0, $h['m'] / $all - 0.1 * $h['typo']);
        }
        unset($h);
        if (isset($q['rankingScoreThreshold'])) {
            $hits = array_values(array_filter($hits, static fn ($h) => $h['score'] >= (float) $q['rankingScoreThreshold']));
        }
    }
    usort($hits, static function ($a, $b) use ($sort, $custom) {
        if ($a['m'] !== $b['m']) {
            return $b['m'] <=> $a['m'];
        }
        foreach ($sort as $sr) {
            [$f, $dir] = explode(':', $sr);
            $c = (getPath($a['doc'], $f) <=> getPath($b['doc'], $f));
            if ($c !== 0) {
                return $dir === 'desc' ? -$c : $c;
            }
        }
        if ($a['typo'] !== $b['typo']) {
            return $a['typo'] <=> $b['typo'];
        }
        if ($a['attr'] !== $b['attr']) {
            return $a['attr'] <=> $b['attr'];
        }
        foreach ($custom as [$f, $dir]) {
            $c = (getPath($a['doc'], $f) <=> getPath($b['doc'], $f));
            if ($c !== 0) {
                return $dir === 'desc' ? -$c : $c;
            }
        }
        return 0;
    });
    $dist = [];
    $stats = [];
    foreach ($q['facets'] ?? [] as $f) {
        $dist[$f] = [];
        foreach ($hits as $h) {
            foreach (array_unique(array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, flat(getPath($h['doc'], $f)))) as $v) {
                $dist[$f][$v] = ($dist[$f][$v] ?? 0) + 1;
                if (is_numeric($v)) {
                    $stats[$f]['min'] = min($stats[$f]['min'] ?? INF, (float) $v);
                    $stats[$f]['max'] = max($stats[$f]['max'] ?? -INF, (float) $v);
                }
            }
        }
        arsort($dist[$f]);
    }
    $offset = (int) ($q['offset'] ?? 0);
    $limit = (int) ($q['limit'] ?? 20);
    $fields = $q['attributesToRetrieve'] ?? null;
    $withScore = !empty($q['showRankingScore']);
    $page = array_map(static function ($h) use ($fields, $withScore) {
        $d = $fields ? array_intersect_key($h['doc'], array_flip($fields)) : $h['doc'];
        if ($withScore) {
            $d['_rankingScore'] = $h['score'] ?? 1.0;
        }
        return $d;
    }, array_slice($hits, $offset, $limit));
    $res = ['indexUid' => $q['indexUid'] ?? '', 'hits' => $page, 'query' => $q['q'] ?? '', 'processingTimeMs' => (int) ((microtime(true) - $t0) * 1000),
        'limit' => $limit, 'offset' => $offset, 'estimatedTotalHits' => count($hits)];
    if (!empty($q['facets'])) {
        $res['facetDistribution'] = $dist;
        $res['facetStats'] = array_filter($stats, static fn ($s) => isset($s['min']));
    }
    return $res;
}

// ---------------------------------------------------------------- Routage
if ($path === '/health') {
    out(['status' => 'available']);
    return;
}
if (preg_match('#^/tasks/(\d+)$#', $path, $m)) {
    out(['uid' => (int) $m[1], 'status' => 'succeeded']);
    return;
}
if ($path === '/indexes' && $method === 'POST') {
    if (load($body['uid']) !== null) {
        out(task());
        return;
    }
    save($body['uid'], ['uid' => $body['uid'], 'primaryKey' => $body['primaryKey'] ?? 'id', 'settings' => [], 'docs' => []]);
    out(task(), 202);
    return;
}
if ($path === '/swap-indexes' && $method === 'POST') {
    foreach ($body as $sw) {
        [$a, $b] = $sw['indexes'];
        $ia = load($a);
        $ib = load($b);
        if ($ia === null || $ib === null) {
            out(['message' => 'index not found', 'code' => 'index_not_found'], 404);
            return;
        }
        $ia['uid'] = $b;
        $ib['uid'] = $a;
        save($a, $ib);
        save($b, $ia);
    }
    out(task(), 202);
    return;
}
if ($path === '/multi-search' && $method === 'POST') {
    $results = [];
    foreach ($body['queries'] as $q) {
        $idx = load($q['indexUid']);
        if ($idx === null) {
            notFound($q['indexUid']);
            return;
        }
        try {
            $results[] = search($idx, $q);
        } catch (RuntimeException $e) {
            out(['message' => $e->getMessage(), 'code' => 'invalid_search_filter'], 400);
            return;
        }
    }
    out(['results' => $results]);
    return;
}
if (preg_match('#^/indexes/([^/]+)(/.*)?$#', $path, $m)) {
    $uid = urldecode($m[1]);
    $sub = $m[2] ?? '';
    $idx = load($uid);
    if ($idx === null) {
        notFound($uid);
        return;
    }
    if ($sub === '' && $method === 'GET') {
        out(['uid' => $uid, 'primaryKey' => $idx['primaryKey']]);
    } elseif ($sub === '' && $method === 'DELETE') {
        unlink(idxFile($uid));
        out(task(), 202);
    } elseif ($sub === '/settings' && $method === 'PATCH') {
        $idx['settings'] = array_merge($idx['settings'], $body);
        save($uid, $idx);
        out(task(), 202);
    } elseif ($sub === '/settings' && $method === 'GET') {
        out($idx['settings']);
    } elseif ($sub === '/stats') {
        out(['numberOfDocuments' => count($idx['docs']), 'isIndexing' => false]);
    } elseif ($sub === '/documents' && in_array($method, ['POST', 'PUT'], true)) {
        foreach ($body as $doc) {
            $id = (string) $doc['id'];
            $idx['docs'][$id] = $method === 'PUT' && isset($idx['docs'][$id]) ? array_merge($idx['docs'][$id], $doc) : $doc;
        }
        save($uid, $idx);
        out(task(), 202);
    } elseif ($sub === '/documents/delete-batch') {
        foreach ($body as $id) {
            unset($idx['docs'][(string) $id]);
        }
        save($uid, $idx);
        out(task(), 202);
    } elseif ($sub === '/documents/fetch') {
        $res = [];
        foreach ($idx['docs'] as $doc) {
            if (filterOk($doc, $body['filter'] ?? null)) {
                $res[] = $doc;
            }
        }
        out(['results' => array_slice($res, (int) ($body['offset'] ?? 0), (int) ($body['limit'] ?? 20)), 'total' => count($res)]);
    } elseif ($sub === '/search') {
        out(search($idx, $body + ['indexUid' => $uid]));
    } else {
        out(['message' => 'route non gérée par le faux serveur', 'code' => 'not_found'], 404);
    }
    return;
}
out(['message' => 'route non gérée', 'code' => 'not_found'], 404);
