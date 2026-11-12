<?php

declare(strict_types=1);

namespace DeepFind\Meili;

use DeepFind\Config;
use DeepFind\Text;

/**
 * Client HTTP minimal pour l'API Meilisearch v1 (pas de SDK, pas de Composer).
 * La connexion cURL est réutilisée (keep-alive) au sein d'un même processus.
 */
final class MeiliClient
{
    private ?\CurlHandle $curl = null;

    public function __construct(
        private readonly string $url,
        private readonly string $key,
        private readonly float $timeout = 3.0,
    ) {
    }

    public static function admin(): self
    {
        return new self(Config::get('MEILI_URL', 'http://127.0.0.1:7700'), Config::get('MEILI_MASTER_KEY', ''), 30.0);
    }

    public static function search(): self
    {
        return new self(
            Config::get('MEILI_URL', 'http://127.0.0.1:7700'),
            Config::get('MEILI_SEARCH_KEY') ?? Config::get('MEILI_MASTER_KEY', ''),
            (float) Config::get('MEILI_TIMEOUT', '2')
        );
    }

    /** @return array<mixed> */
    public function request(string $method, string $path, ?array $body = null, ?float $timeout = null): array
    {
        $this->curl ??= curl_init();
        $ch = $this->curl;
        curl_reset($ch);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->key !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->url, '/') . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => (int) (($timeout ?? $this->timeout) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_NOPROXY => '*',
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, Text::json($body));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new MeiliException('Meilisearch injoignable : ' . curl_error($ch), 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($status >= 400) {
            throw new MeiliException(($data['message'] ?? 'Erreur Meilisearch') . ' [' . ($data['code'] ?? $status) . ']', $status, $data['code'] ?? null);
        }
        return $data;
    }

    public function health(): bool
    {
        try {
            return ($this->request('GET', '/health', null, 1.0)['status'] ?? '') === 'available';
        } catch (MeiliException) {
            return false;
        }
    }

    /** @return array<mixed>|null */
    public function getIndex(string $uid): ?array
    {
        try {
            return $this->request('GET', '/indexes/' . rawurlencode($uid));
        } catch (MeiliException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function createIndex(string $uid, string $primaryKey = 'id'): int
    {
        return $this->task($this->request('POST', '/indexes', ['uid' => $uid, 'primaryKey' => $primaryKey]));
    }

    public function deleteIndex(string $uid): ?int
    {
        try {
            return $this->task($this->request('DELETE', '/indexes/' . rawurlencode($uid)));
        } catch (MeiliException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function updateSettings(string $uid, array $settings): int
    {
        return $this->task($this->request('PATCH', '/indexes/' . rawurlencode($uid) . '/settings', $settings));
    }

    /** @return array<mixed> */
    public function getSettings(string $uid): array
    {
        return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/settings');
    }

    /** Ajout/remplacement de documents (tâche asynchrone). */
    public function addDocuments(string $uid, array $docs): int
    {
        return $this->task($this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents?primaryKey=id', array_values($docs), 120.0));
    }

    public function deleteDocuments(string $uid, array $ids): int
    {
        return $this->task($this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents/delete-batch', array_values($ids)));
    }

    /** @return list<array<string,mixed>> */
    public function fetchDocuments(string $uid, string $filter, int $limit = 1000, int $offset = 0): array
    {
        $body = ['limit' => $limit, 'offset' => $offset];
        if ($filter !== '') {
            $body['filter'] = $filter;
        }
        $res = $this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents/fetch', $body);
        return $res['results'] ?? [];
    }

    /** @return array<mixed> */
    public function stats(string $uid): array
    {
        return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/stats');
    }

    public function swap(string $a, string $b): int
    {
        return $this->task($this->request('POST', '/swap-indexes', [['indexes' => [$a, $b]]]));
    }

    /** @param list<array<string,mixed>> $queries
     *  @return list<array<string,mixed>> */
    public function multiSearch(array $queries): array
    {
        return $this->request('POST', '/multi-search', ['queries' => $queries])['results'] ?? [];
    }

    /** Attend la fin d'une tâche asynchrone ; lève une exception si elle a échoué. */
    public function waitTask(int $taskUid, int $timeoutSec = 600): array
    {
        $start = microtime(true);
        $sleep = 50_000;
        while (true) {
            $t = $this->request('GET', '/tasks/' . $taskUid);
            $status = $t['status'] ?? '';
            if ($status === 'succeeded') {
                return $t;
            }
            if ($status === 'failed' || $status === 'canceled') {
                throw new MeiliException('Tâche Meilisearch ' . $taskUid . ' en échec : ' . ($t['error']['message'] ?? $status), 500);
            }
            if (microtime(true) - $start > $timeoutSec) {
                throw new MeiliException('Délai dépassé en attendant la tâche Meilisearch ' . $taskUid, 504);
            }
            usleep($sleep);
            $sleep = min($sleep * 2, 1_000_000);
        }
    }

    /** @param list<int> $uids */
    public function waitTasks(array $uids, int $timeoutSec = 1800): void
    {
        foreach ($uids as $uid) {
            $this->waitTask($uid, $timeoutSec);
        }
    }

    private function task(array $res): int
    {
        return (int) ($res['taskUid'] ?? $res['uid'] ?? 0);
    }
}
