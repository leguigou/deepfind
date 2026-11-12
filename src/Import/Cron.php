<?php

declare(strict_types=1);

namespace DeepFind\Import;

/**
 * Expressions cron standard à 5 champs (minute heure jour-du-mois mois jour-de-la-semaine), dans le fuseau APP_TIMEZONE.
 * Syntaxe acceptée : *  5  1,15  1-5  * /15  8-18/2  (jour de la semaine : 0 ou 7 = dimanche).
 */
final class Cron
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    public const PRESETS_FULL = [
        '' => 'Désactivé (manuel uniquement)',
        '15 3 * * *' => 'Chaque nuit à 3 h 15',
        '15 3,13 * * *' => 'Deux fois par jour (3 h 15 et 13 h 15)',
        '15 */6 * * *' => 'Toutes les 6 heures',
        '0 * * * *' => 'Toutes les heures',
    ];

    public const PRESETS_DELTA = [
        '' => 'Désactivé (manuel uniquement)',
        '*/15 6-23 * * *' => 'Toutes les 15 min de 6 h à 23 h',
        '*/15 * * * *' => 'Toutes les 15 min',
        '*/5 * * * *' => 'Toutes les 5 min',
        '0 * * * *' => 'Toutes les heures',
    ];

    /** @return list<array<int,true>>|null  null si l'expression est invalide */
    public static function parse(string $expr): ?array
    {
        $fields = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($fields) !== 5) {
            return null;
        }
        $sets = [];
        foreach ($fields as $i => $field) {
            [$min, $max] = self::RANGES[$i];
            $set = [];
            foreach (explode(',', $field) as $part) {
                if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $part, $m)) {
                    return null;
                }
                $step = isset($m[2]) ? (int) $m[2] : 1;
                if ($step < 1) {
                    return null;
                }
                if ($m[1] === '*') {
                    [$a, $b] = [$min, $max];
                } elseif (str_contains($m[1], '-')) {
                    [$a, $b] = array_map('intval', explode('-', $m[1]));
                } else {
                    $a = (int) $m[1];
                    $b = isset($m[2]) ? $max : $a;
                }
                if ($a < $min || $b > $max || $a > $b) {
                    return null;
                }
                for ($v = $a; $v <= $b; $v += $step) {
                    $set[$i === 4 && $v === 7 ? 0 : $v] = true;
                }
            }
            $sets[] = $set;
        }
        // Mémorise si jour du mois / jour de semaine sont restreints (règle cron : OU entre les deux).
        $sets[] = [$fields[2] !== '*', $fields[4] !== '*'];
        return $sets;
    }

    public static function isValid(string $expr): bool
    {
        return self::parse($expr) !== null;
    }

    public static function matches(string $expr, \DateTimeInterface $t): bool
    {
        $s = self::parse($expr);
        return $s !== null && self::matchSets($s, $t);
    }

    private static function matchSets(array $s, \DateTimeInterface $t): bool
    {
        if (!isset($s[0][(int) $t->format('i')], $s[1][(int) $t->format('G')], $s[3][(int) $t->format('n')])) {
            return false;
        }
        return self::dayMatches($s, $t);
    }

    private static function dayMatches(array $s, \DateTimeInterface $t): bool
    {
        $dom = isset($s[2][(int) $t->format('j')]);
        $dow = isset($s[4][(int) $t->format('w')]);
        [$domRestricted, $dowRestricted] = $s[5];
        if ($domRestricted && $dowRestricted) {
            return $dom || $dow;
        }
        return $dom && $dow;
    }

    /** @return list<\DateTimeImmutable> prochaines exécutions (au plus $n, recherche sur 366 jours) */
    public static function nextRuns(string $expr, int $n = 3, ?\DateTimeImmutable $from = null): array
    {
        $s = self::parse($expr);
        if ($s === null) {
            return [];
        }
        $base = $from ?? new \DateTimeImmutable();
        $t = $base->setTime((int) $base->format('G'), (int) $base->format('i'))->modify('+1 minute');
        $out = [];
        $limit = $t->modify('+366 days');
        while ($t < $limit && count($out) < $n) {
            if (!isset($s[3][(int) $t->format('n')])) {
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            if (!self::dayMatches($s, $t)) {
                $t = $t->setTime(0, 0)->modify('+1 day');
                continue;
            }
            if (!isset($s[1][(int) $t->format('G')])) {
                $t = $t->setTime((int) $t->format('G'), 0)->modify('+1 hour');
                continue;
            }
            if (self::matchSets($s, $t)) {
                $out[] = $t;
            }
            $t = $t->modify('+1 minute');
        }
        return $out;
    }

    public static function describe(string $expr, string $kind = 'full'): string
    {
        if ($expr === '') {
            return 'Manuel';
        }
        $presets = $kind === 'delta' ? self::PRESETS_DELTA : self::PRESETS_FULL;
        return $presets[$expr] ?? (self::PRESETS_FULL[$expr] ?? self::PRESETS_DELTA[$expr] ?? 'cron « ' . $expr . ' »');
    }
}
