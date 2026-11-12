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
    public static function fetch(array $source, string $type): string
    {
        $url = trim((string) ($type === 'delta' ? ($source['delta_url'] ?? '') : ($source['url'] ?? '')));
        if ($url === '') {
            throw new \RuntimeException('Aucune URL de flux ' . $type . ' configurée pour la source ' . $source['code']);
        }
        if (preg_match('#^https?://#i', $url)) {
            return self::download($url, (string) ($source['auth_header'] ?? ''), self::cachePath($source['code'], $type));
        }
        return self::local($url);
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

    private static function download(string $url, string $authHeader, string $target): string
    {
        $tmp = $target . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Impossible d\'écrire ' . $tmp);
        }
        $headers = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $authHeader) ?: [])));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => Config::int('FEED_DOWNLOAD_TIMEOUT', 900),
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DeepFind-Importer/1.0',
            CURLOPT_ENCODING => '',
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($fh);
        if ($ok === false) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Téléchargement du flux en échec (HTTP %d) : %s', $code, $err));
        }
        if (filesize($tmp) < 20) {
            @unlink($tmp);
            throw new \RuntimeException('Flux téléchargé vide');
        }
        rename($tmp, $target);
        return $target;
    }
}
