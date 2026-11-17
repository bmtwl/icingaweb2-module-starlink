<?php

namespace Icinga\Module\Starlink;

use Icinga\Application\Icinga;
use Icinga\Data\ResourceFactory;
use ipl\Sql\Config as SqlConfig;
use ipl\Sql\Connection;
use PDO;
use RuntimeException;

/**
 * Thin helper around ipl\Sql\Connection. All queries are plain SQL with
 * positional parameters; rows come back as associative arrays.
 */
class Db
{
    /** @var ?Connection */
    protected static $connection;

    public static function get(): Connection
    {
        if (static::$connection === null) {
            $resource = Settings::dbResource();
            if (! $resource) {
                throw new RuntimeException(
                    'No database resource configured (Configuration > Modules > starlink > Backend)'
                );
            }
            $config = new SqlConfig(ResourceFactory::getResourceConfig($resource));
            if ($config->db !== 'pgsql') {
                throw new RuntimeException("Resource '$resource' is not PostgreSQL; only pgsql is supported");
            }
            static::$connection = new Connection($config);
        }

        return static::$connection;
    }

    /** Drop the cached connection (used by the daemon after DB errors) */
    public static function reset(): void
    {
        static::$connection = null;
    }

    public static function all(string $sql, array $params = []): array
    {
        return static::get()->prepexec($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = static::get()->prepexec($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $value = static::get()->prepexec($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return static::get()->prepexec($sql, $params)->rowCount();
    }

    public static function hasSchema(): bool
    {
        return (int) static::value(
            "SELECT CASE WHEN to_regclass('public.starlink_schema') IS NULL THEN 0 ELSE 1 END"
        ) === 1;
    }

    /** Create the schema if missing. Returns true if it was created. */
    public static function ensureSchema(): bool
    {
        if (static::hasSchema()) {
            return false;
        }
        $file = Icinga::app()->getModuleManager()->getModuleDir('starlink', '/schema/pgsql.sql');
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read $file");
        }
        // ipl prepares every query and Postgres rejects multi-command prepared
        // statements: strip comments and run one statement at a time
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        static::transaction(function () use ($statements) {
            foreach ($statements as $statement) {
                static::exec($statement);
            }
        });

        return true;
    }

    /** Run $fn inside an ipl transaction, rolling back on any error */
    public static function transaction(callable $fn)
    {
        $db = static::get();
        $db->beginTransaction();
        try {
            $result = $fn();
            $db->commitTransaction();

            return $result;
        } catch (\Throwable $e) {
            $db->rollBackTransaction();
            throw $e;
        }
    }

    /** ISO-8601 UTC timestamp from epoch seconds (float) */
    public static function ts(float $epoch): string
    {
        $sec = (int) floor($epoch);
        $ms = (int) round(($epoch - $sec) * 1000);
        if ($ms >= 1000) {
            $sec++;
            $ms -= 1000;
        }

        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
    }

    public static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
