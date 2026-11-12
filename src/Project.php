<?php

declare(strict_types=1);

namespace DeepFind;

/**
 * Projet courant (ex. « Cabesto Prod », « Cabesto Test ») : tout ce qui est propre à un projet
 * (flux, index Meilisearch, règles, boosts, paramètres de pertinence, clés, journaux, cache) est filtré par project_id.
 * Partagé entre projets : protection anti-robots, jeton d'accès à l'extranet.
 *
 * Le projet courant vient : de la session (extranet), de la clé publique (API), de la source importée (CLI / worker).
 */
final class Project
{
    private static ?array $current = null;
    /** @var array<int,array>|null */
    private static ?array $all = null;

    /** @return array<int, array{id:int, code:string, name:string, index_prefix:string}> */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (Db::all('SELECT * FROM projects ORDER BY id') as $p) {
                $p['id'] = (int) $p['id'];
                self::$all[$p['id']] = $p;
            }
        }
        return self::$all;
    }

    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function byCode(string $code): ?array
    {
        foreach (self::all() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }
        return null;
    }

    public static function defaultId(): int
    {
        $all = self::all();
        if ($all === []) {
            throw new \RuntimeException('Aucun projet : lancez « php bin/console install ».');
        }
        return (int) array_key_first($all);
    }

    /** Change de projet courant (vide les caches mémoire liés au projet). */
    public static function use(int $id): void
    {
        $p = self::find($id) ?? self::find(self::defaultId());
        if (self::$current !== null && self::$current['id'] === $p['id']) {
            return;
        }
        self::$current = $p;
        Settings::reset();
        CacheVersion::reset();
    }

    public static function current(): array
    {
        if (self::$current === null) {
            self::use(self::defaultId());
        }
        return self::$current;
    }

    public static function id(): int
    {
        return (int) self::current()['id'];
    }

    public static function indexPrefix(): string
    {
        return (string) self::current()['index_prefix'];
    }

    /** Exécute $fn dans le contexte d'un autre projet puis revient au projet précédent. */
    public static function with(int $id, callable $fn): mixed
    {
        $prev = self::$current['id'] ?? null;
        self::use($id);
        try {
            return $fn();
        } finally {
            if ($prev !== null) {
                self::use((int) $prev);
            }
        }
    }

    public static function validCode(string $code): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9\-]{1,19}$/', $code) === 1;
    }

    public static function create(string $code, string $name): int
    {
        if (!self::validCode($code)) {
            throw new \InvalidArgumentException('Code de projet invalide (2 à 20 caractères : minuscules, chiffres, tirets).');
        }
        if (self::byCode($code) !== null) {
            throw new \InvalidArgumentException('Ce code de projet existe déjà.');
        }
        $id = Db::insert('projects', [
            'code' => $code,
            'name' => Text::truncate(trim($name) ?: $code, 100),
            'index_prefix' => Config::indexPrefix() . str_replace('-', '_', $code) . '_',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        self::$all = null;
        return $id;
    }

    /** Premier projet (« Cabesto Prod ») : garde le préfixe d'index historique, aucune réindexation nécessaire. */
    public static function ensureDefault(): ?int
    {
        if ((int) Db::value('SELECT COUNT(*) FROM projects') > 0) {
            return null;
        }
        $id = Db::insert('projects', [
            'code' => 'prod',
            'name' => (string) Config::get('DEFAULT_PROJECT_NAME', 'Production'),
            'index_prefix' => Config::indexPrefix(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        self::$all = null;
        return $id;
    }

    public static function reload(): void
    {
        self::$all = null;
        if (self::$current !== null) {
            self::$current = self::find((int) self::$current['id']);
        }
    }
}
