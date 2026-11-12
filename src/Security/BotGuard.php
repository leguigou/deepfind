<?php

declare(strict_types=1);

namespace DeepFind\Security;

use DeepFind\Db;
use DeepFind\Settings;
use DeepFind\Store\KvStore;
use DeepFind\Text;

/**
 * Protection anti-robots de l'API publique :
 *  1. IP bloquées (manuellement ou automatiquement) => 403
 *  2. User-agents de robots / vides => 403
 *  3. Seuils par IP : requêtes/minute, requêtes/heure, même requête/minute => 429
 *  4. Récidive (N minutes en dépassement sur 1 h) ou rafale massive => blocage automatique temporaire
 * Les compteurs sont en mémoire (APCu/Redis) : un robot bloqué ne génère aucune écriture en base.
 */
final class BotGuard
{
    public function __construct(private readonly KvStore $store)
    {
    }

    /**
     * @return array{status:int, reason:string, retry_after:int}|null  null = requête autorisée
     */
    public function check(string $ip, string $userAgent, string $queryNorm, string $bucket = 'search'): ?array
    {
        $allow = array_filter(array_map('trim', explode(',', Settings::get('ip_allowlist'))));
        if ($allow !== [] && ClientIp::inList($ip, $allow)) {
            return null;
        }
        $hash = ClientIp::hash($ip);
        $now = time();

        if ($this->isBlocked($hash)) {
            return ['status' => 403, 'reason' => 'ip_blocked', 'retry_after' => 3600];
        }

        if (Settings::int('block_bad_user_agents') === 1) {
            $patterns = trim(Settings::get('bad_user_agents'));
            $ua = trim($userAgent);
            if ($ua === '' || ($patterns !== '' && @preg_match('~' . str_replace('~', '\~', $patterns) . '~i', $ua) === 1)) {
                $this->event($hash, $ip, $userAgent, 'user_agent');
                return ['status' => 403, 'reason' => 'bad_user_agent', 'retry_after' => 3600];
            }
        }

        $minute = intdiv($now, 60);
        $hour = intdiv($now, 3600);
        $perMin = max(1, Settings::int('rate_per_minute'));
        $perHour = max(1, Settings::int('rate_per_hour'));
        $samePerMin = max(1, Settings::int('same_query_per_minute'));

        $m = $this->store->incr("rl:m:$bucket:$hash:$minute", 120);
        $h = $this->store->incr("rl:h:$bucket:$hash:$hour", 3700);
        $same = $queryNorm !== '' ? $this->store->incr("rl:q:$hash:" . md5($queryNorm) . ":$minute", 120) : 0;

        $reason = null;
        if ($m > $perMin) {
            $reason = 'rate_minute';
        } elseif ($h > $perHour) {
            $reason = 'rate_hour';
        } elseif ($same > $samePerMin) {
            $reason = 'same_query';
        }
        if ($reason === null) {
            return null;
        }

        // Rafale massive : blocage immédiat.
        if ($m > $perMin * max(2, Settings::int('burst_multiplier'))) {
            $this->block($hash, $ip, 'auto', 'Rafale : ' . $m . ' requêtes/minute', Settings::int('block_minutes'));
            return ['status' => 403, 'reason' => 'ip_blocked', 'retry_after' => Settings::int('block_minutes') * 60];
        }

        // Une violation comptée au maximum une fois par minute.
        if ($this->store->incr("rl:vflag:$hash:$minute", 120) === 1) {
            $this->event($hash, $ip, $userAgent, $reason);
            $violations = $this->store->incr("rl:viol:$hash:$hour", 3700);
            if ($violations >= max(1, Settings::int('violations_before_block'))) {
                $this->block($hash, $ip, 'auto', 'Dépassements répétés (' . $reason . ')', Settings::int('block_minutes'));
            }
        }
        $retry = $reason === 'rate_hour' ? 3600 - ($now % 3600) : 60 - ($now % 60);
        return ['status' => 429, 'reason' => $reason, 'retry_after' => $retry];
    }

    public function isBlocked(string $hash): bool
    {
        $cached = $this->store->get('blk:' . $hash);
        if ($cached !== null) {
            return (bool) $cached;
        }
        $blocked = Db::value(
            'SELECT 1 FROM ip_blocks WHERE ip_hash = ? AND (blocked_until IS NULL OR blocked_until > NOW())',
            [$hash]
        ) !== null;
        $this->store->set('blk:' . $hash, $blocked ? 1 : 0, 60);
        return $blocked;
    }

    /** @param int $minutes 0 = permanent */
    public function block(string $hash, string $ip, string $source, string $reason, int $minutes, ?string $masked = null): void
    {
        $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        $reason = Text::truncate($reason, 250);
        Db::exec(
            'INSERT INTO ip_blocks (ip_hash, ip_masked, reason, source, blocked_until, created_at) VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE reason = ?, source = ?, blocked_until = ?, created_at = NOW()',
            [$hash, $masked ?? ($ip === '' ? '' : ClientIp::mask($ip)), $reason, $source, $until, $reason, $source, $until]
        );
        $this->store->set('blk:' . $hash, 1, 60);
    }

    public function unblock(string $hash): void
    {
        Db::exec('DELETE FROM ip_blocks WHERE ip_hash = ?', [$hash]);
        $this->store->set('blk:' . $hash, 0, 60);
    }

    /** Journal des événements robots, limité à 1 ligne par IP, motif et minute. */
    private function event(string $hash, string $ip, string $ua, string $reason): void
    {
        $minute = intdiv(time(), 60);
        if ($this->store->incr("rl:ev:$hash:$reason:$minute", 120) !== 1) {
            return;
        }
        Db::insert('bot_events', [
            'ip_hash' => $hash,
            'ip_masked' => ClientIp::mask($ip),
            'user_agent' => Text::truncate($ua, 250),
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
