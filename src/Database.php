<?php

declare(strict_types=1);

namespace Light\Db;

use Dotenv\Dotenv;
use Light\Entity\{DbContext, DbSet, EntityMapping};
use PDO;

/** Connection and reusable read metadata; writes use isolated units of work. */
final class Database
{
    private static ?self $default = null;
    private ModelMetadata $metadata;
    /** @var array<class-string<Model>, DbContext> */
    private array $contexts = [];

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->metadata = new ModelMetadata($pdo);
    }

    public static function setDefault(?self $database): void { self::$default = $database; }

    public static function getDefault(): self
    {
        return self::$default ??= self::fromEnvironment();
    }

    public static function fromEnvironment(?string $directory = null): self
    {
        Dotenv::createImmutable($directory ?? getcwd())->safeLoad();
        $env = static fn (string $key, string $default = '') => (string)($_ENV[$key] ?? (getenv($key) === false ? $default : getenv($key)));
        $dsn = $env('DATABASE_DSN');
        if ($dsn === '') {
            $name = $env('DATABASE_DATABASE');
            if ($name === '') { throw new \LogicException('Configure Database::setDefault() or DATABASE_DSN/DATABASE_DATABASE.'); }
            $dsn = 'mysql:host='.$env('DATABASE_HOSTNAME', '127.0.0.1').';port='.$env('DATABASE_PORT', '3306')
                .';dbname='.$name.';charset='.$env('DATABASE_CHARSET', 'utf8mb4');
        }
        return new self(new PDO($dsn, $env('DATABASE_USERNAME', 'root'), $env('DATABASE_PASSWORD'), [PDO::ATTR_EMULATE_PREPARES => false]));
    }

    public function connection(): PDO { return $this->pdo; }
    public function metadata(string $class): EntityMapping { return $this->metadata->get($class); }

    /** @internal All reads remain fresh; the context caches schema, not Model instances. */
    public function set(string $class): DbSet
    {
        $mapping = $this->metadata($class);
        $context = $this->contexts[$class] ??= new ModelDbContext($this->pdo, $mapping);
        return $context->set($class)->asNoTracking();
    }

    /** @internal One Model save must not flush changes belonging to another Model. */
    public function writeContext(EntityMapping $mapping): DbContext
    {
        return new ModelDbContext($this->pdo, $mapping);
    }

    public function clearMetadata(): void
    {
        $this->contexts = [];
        $this->metadata->clear();
    }
}
