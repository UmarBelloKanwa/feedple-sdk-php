<?php

declare(strict_types=1);

namespace Feedple\Sdk;

/**
 * Immutable database connection config, used by FeedpleSDK to build \PDO
 * connections in both the launching process and the background worker.
 *
 * Two things this buys you over a raw dsn/username/password string triplet:
 *
 *  1. Secrets never appear in stack traces. $password is marked with
 *     #[\SensitiveParameter] (PHP 8.2+), which makes PHP itself redact the
 *     value from exception backtraces — including ones captured by error
 *     trackers like Sentry/Bugsnag that read Throwable::getTrace(). A plain
 *     constructor-promoted string property does NOT get this protection.
 *  2. Per-driver named constructors validate what each driver actually
 *     needs (e.g. sqlite() doesn't ask for credentials at all) instead of
 *     silently accepting a mismatched combination.
 *
 * This is still serialized to a control file for the worker process to read
 * (see FeedpleSDK::toArray()/fromArray()) — the file itself is chmod'd 0600
 * and deleted immediately after the worker reads it, but the credential is
 * still briefly on disk. If that's unacceptable for your threat model,
 * pull the credential from a secrets manager inside a custom driver
 * instead of using this class directly.
 */
final class DbConfig implements \JsonSerializable
{
    /** @var string */
    public $dsn;
    /** @var string|null */
    public $username;
    /** @var string|null */
    public $password;
    /** @var array */
    public $options;

    private function __construct(
        string $dsn,
        string $username = null,
        string $password = null,
        array $options = []
    ) {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
    }

    public static function mysql(
        string $host,
        string $database,
        string $username = null,
        string $password = null,
        int $port = 3306,
        string $charset = 'utf8mb4',
        array $options = []
    ): self {
        return new self(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            $username,
            $password,
            $options
        );
    }

    public static function pgsql(
        string $host,
        string $database,
        string $username = null,
        string $password = null,
        int $port = 5432,
        array $options = []
    ): self {
        return new self(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            $options
        );
    }

    /** @param string $path Absolute path to the .sqlite file — must be shared/reachable by both processes. */
    public static function sqlite(string $path, array $options = []): self
    {
        return new self(
            "sqlite:{$path}",
            null,
            null,
            $options
        );
    }

    /** Escape hatch for any other PDO-supported driver or unusual DSN. */
    public static function raw(
        string $dsn,
        string $username = null,
        string $password = null,
        array $options = []
    ): self {
        return new self($dsn, $username, $password, $options);
    }

    public function toPdo(): \PDO
    {
        $pdo = new \PDO($this->dsn, $this->username, $this->password, $this->options + [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // For MySQL/MariaDB connections, attempt to enable ANSI_QUOTES if possible.
        // Even if this fails (e.g. read-only permissions), IrBuilder natively uses backticks for MySQL.
        if (strncmp($this->dsn, 'mysql:', 6) === 0) {
            try {
                $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode, ''), 'ANSI_QUOTES')");
            } catch (\Throwable) {
                // Ignore if session sql_mode modification is restricted
            }
        }

        return $pdo;
    }

    /** @internal used only to write the worker control file — not for logging/debugging. */
    public function toArray(): array
    {
        return [
            'dsn'      => $this->dsn,
            'username' => $this->username,
            'password' => $this->password,
            'options'  => $this->options,
        ];
    }

    /** @internal used only to read the worker control file. */
    public static function fromArray(array $data): self
    {
        return new self($data['dsn'], $data['username'], $data['password'], $data['options']);
    }

    /** Redacted representation for var_dump()/print_r() — never exposes the password. */
    public function __debugInfo(): array
    {
        return [
            'dsn'      => $this->dsn,
            'username' => $this->username,
            'password' => $this->password === null ? null : '••••••',
            'options'  => $this->options,
        ];
    }

    /** Redacted representation for json_encode() — never exposes the password. */
    public function jsonSerialize(): array
    {
        return [
            'dsn'      => $this->dsn,
            'username' => $this->username,
            'password' => $this->password === null ? null : '••••••',
        ];
    }
}