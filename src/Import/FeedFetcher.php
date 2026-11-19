<?php

declare(strict_types=1);

namespace DeepFind\Import;

use DeepFind\Config;

/**
 * Récupère un flux : URL http(s) (téléchargement en streaming sur disque) ou fichier local autorisé.
 */
final class FeedFetcher
{
    /** @param array<string,mixed> $source */
    /** @param (callable(int $done, int $total): void)|null $progress */
    public static function fetch(array $source, string $type, ?callable $progress = null): string
    {
        $url = trim((string) ($type === 'delta' ? ($source['delta_url'] ?? '') : ($source['url'] ?? '')));
        if ($url === '') {
            throw new \RuntimeException('Aucune URL de flux ' . $type . ' configurée pour la source ' . $source['code']);
        }
        if (preg_match('#^https?://#i', $url)) {
            return self::download($url, self::access($source), self::cachePath($source['code'], $type), $progress);
        }
        return self::local($url);
    }

    /** Pour l'extranet : réutilise le dernier téléchargement de moins d'une heure (évite de retélécharger 300 Mo). */
    public static function cachedOrFetch(array $source, string $type, bool $refresh = false): string
    {
        $url = trim((string) ($type === 'delta' ? ($source['delta_url'] ?? '') : ($source['url'] ?? '')));
        $cache = self::cachePath($source['code'], $type);
        if (!$refresh && preg_match('#^https?://#i', $url) && is_file($cache) && filemtime($cache) > time() - 3600) {
            return $cache;
        }
        return self::fetch($source, $type);
    }

    /** Dernier fichier téléchargé (utilisé pour le test de mapping sans retélécharger). */
    public static function cachePath(string $code, string $type): string
    {
        return Config::varDir('feeds') . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $code) . '_' . $type . '.xml';
    }

    public static function local(string $path): string
    {
        $path = preg_replace('#^file://#', '', $path) ?? $path;
        if ($path !== '' && $path[0] !== '/') {
            $path = APP_ROOT . '/' . $path;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('Fichier de flux introuvable : ' . $path);
        }
        $allowed = array_filter(array_map('trim', explode(',', Config::get('FEED_LOCAL_DIRS', 'var/feeds-local,samples'))));
        foreach ($allowed as $dir) {
            $dirReal = realpath($dir[0] === '/' ? $dir : APP_ROOT . '/' . $dir);
            if ($dirReal !== false && str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        throw new \RuntimeException('Chemin local non autorisé (voir FEED_LOCAL_DIRS) : ' . $path);
    }

    /**
     * Accès au flux : en-têtes HTTP libres et protection par mot de passe du serveur (.htaccess / .htpasswd).
     * @return array{headers: list<string>, user: string, password: string}
     */
    public static function access(array $source): array
    {
        return [
            'headers' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($source['auth_header'] ?? '')) ?: []))),
            'user' => trim((string) ($source['auth_user'] ?? '')),
            // déjà en clair (bouton « Tester » avec un mot de passe tout juste saisi) ou chiffré en base
            'password' => array_key_exists('auth_password_plain', $source)
                ? (string) $source['auth_password_plain']
                : (string) (\DeepFind\Security\Secrets::decrypt($source['auth_password'] ?? null) ?? ''),
        ];
    }

    /** Options cURL communes : redirections limitées, http(s) seulement, identifiants (Basic ou Digest, négociés). */
    private static function curlOptions(array $access): array
    {
        $o = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $access['headers'],
            CURLOPT_USERAGENT => 'DeepFind-Importer/1.0',
            CURLOPT_ENCODING => '',
        ];
        if ($access['user'] !== '') {
            // le mot de passe n'est jamais renvoyé à un autre domaine en cas de redirection (CURLOPT_UNRESTRICTED_AUTH désactivé)
            $o[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC | CURLAUTH_DIGEST;
            $o[CURLOPT_USERNAME] = $access['user'];
            $o[CURLOPT_PASSWORD] = $access['password'];
        }
        return $o;
    }

    private static function download(string $url, array $access, string $target, ?callable $progress = null): string
    {
        $tmp = $target . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Impossible d\'écrire ' . $tmp);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, self::curlOptions($access) + [
            CURLOPT_FILE => $fh,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => Config::int('FEED_DOWNLOAD_TIMEOUT', 900),
            CURLOPT_FAILONERROR => true,
        ]);
        if ($progress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($c, $dlTotal, $dlNow) use ($progress): int {
                $progress((int) $dlNow, (int) $dlTotal);
                return 0;
            });
        }
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($fh);
        if ($ok === false) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Téléchargement du flux en échec (HTTP %d) : %s', $code, $err)
                . ($code === 401 ? ' — flux protégé par mot de passe : vérifiez l\'utilisateur et le mot de passe de la source.' : ''));
        }
        if (filesize($tmp) < 20) {
            @unlink($tmp);
            throw new \RuntimeException('Flux téléchargé vide');
        }
        rename($tmp, $target);
        return $target;
    }

    /**
     * Test de connexion (bouton « Tester la connexion ») : demande les premiers Ko du fichier, sans le télécharger en entier.
     * @return array{ok:bool, status:int, message:string, details:list<string>, protected:bool}
     */
    public static function check(array $source, string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'Aucune adresse saisie.', 'details' => [], 'protected' => false];
        }
        if (!preg_match('#^https?://#i', $url)) {
            try {
                $path = self::local($url);
                return ['ok' => true, 'status' => 0, 'message' => 'Fichier local trouvé.', 'protected' => false,
                    'details' => ['Taille : ' . self::size((int) filesize($path)), 'Modifié le ' . date('d/m/Y à H:i', (int) filemtime($path)), self::sniff((string) file_get_contents($path, false, null, 0, 512))]];
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'status' => 0, 'message' => $e->getMessage(), 'details' => [], 'protected' => false];
            }
        }
        $access = self::access($source);
        $statuses = [];
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, self::curlOptions($access) + [
            CURLOPT_RANGE => '0-4095',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$statuses, &$headers): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $statuses[] = (int) $m[1];
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            // quelques Ko suffisent : on arrête ensuite (le serveur peut ignorer la demande partielle)
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) >= 4096 ? -1 : strlen($chunk);
            },
        ]);
        $t0 = microtime(true);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        // le serveur a demandé un mot de passe (401) avant de répondre
        $protected = in_array(401, $statuses, true) || isset($headers['www-authenticate']);
        $withUser = $access['user'] !== '';

        if ($code === 0) {
            return ['ok' => false, 'status' => 0, 'protected' => false, 'details' => [],
                'message' => 'Serveur injoignable : ' . ($errno === CURLE_OPERATION_TIMEDOUT ? 'délai dépassé (20 s)' : $err) . '.'];
        }
        $details = ['Réponse en ' . $ms . ' ms'];
        if ($finalUrl !== '' && $finalUrl !== $url) {
            $details[] = 'Redirigé vers ' . $finalUrl;
        }
        if (in_array($code, [200, 206], true)) {
            $total = isset($headers['content-range']) && preg_match('#/(\d+)$#', $headers['content-range'], $m) ? (int) $m[1]
                : ($code === 200 && isset($headers['content-length']) ? (int) $headers['content-length'] : 0);
            if ($total > 0) {
                $details[] = 'Taille : ' . self::size($total);
            }
            if (!empty($headers['last-modified']) && ($t = strtotime($headers['last-modified']))) {
                $details[] = 'Modifié le ' . date('d/m/Y à H:i', $t);
            }
            if (!empty($headers['content-type'])) {
                $details[] = 'Type : ' . $headers['content-type'];
            }
            $sniff = self::sniff($body);
            $details[] = $sniff;
            $isHtml = str_starts_with($sniff, 'Attention');
            $msg = 'Connexion réussie : fichier trouvé (HTTP 200)';
            $msg .= $protected ? ', protection par mot de passe acceptée.' : ($withUser ? ' — le serveur n\'a pas demandé de mot de passe (flux non protégé).' : '.');
            return ['ok' => !$isHtml, 'status' => 200, 'message' => $msg, 'details' => $details, 'protected' => $protected];
        }
        $msg = match (true) {
            $code === 401 && $withUser => 'Identifiant ou mot de passe refusé (HTTP 401).',
            $code === 401 => 'Flux protégé par mot de passe (HTTP 401) : renseignez l\'utilisateur et le mot de passe.',
            $code === 403 => 'Accès refusé (HTTP 403) : le serveur bloque la demande (filtrage d\'adresse IP, droits du fichier…).',
            $code === 404 => 'Fichier introuvable (HTTP 404) : vérifiez l\'adresse.',
            $code === 416 => 'Fichier vide (HTTP 416).',
            $code >= 500 => 'Erreur du serveur du flux (HTTP ' . $code . ').',
            default => 'Réponse inattendue (HTTP ' . $code . ').',
        };
        return ['ok' => false, 'status' => $code, 'message' => $msg, 'details' => $details, 'protected' => $protected || $code === 401];
    }

    /** Nature du fichier d'après ses premiers octets. */
    private static function sniff(string $head): string
    {
        $h = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);
        return match (true) {
            str_starts_with($head, "\x1f\x8b") => 'Contenu : fichier compressé (.gz), décompressé à l\'import.',
            $h === '' => 'Attention : fichier vide.',
            (bool) preg_match('/^(<!doctype html|<html)/i', $h) => 'Attention : le serveur renvoie une page HTML, pas un flux XML (page de connexion ou d\'erreur ?).',
            str_starts_with($h, '<') => 'Contenu : XML.',
            default => 'Attention : le début du fichier ne ressemble pas à du XML.',
        };
    }

    private static function size(int $bytes): string
    {
        return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)) . ' Ko' : number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }
}
