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
    private ?DbContext $transactionContext = null;
    /** @var list<array{Model, bool}> */
    private array $transactionNotifications = [];

    /**
     * Save Models immediately (including generated IDs), then commit all writes together.
     * On rollback, discard/reload participating Models: PHP state is not rewound.
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionContext !== null || $this->pdo->inTransaction()) {
            throw new \LogicException('Nested or externally opened transactions are not supported.');
        }
        $context = new ModelDbContext($this->pdo);
        $context->database->beginTransaction();
        $this->transactionContext = $context;
        $this->transactionNotifications = [];
        try {
            $result = $callback();
            $context->database->commitTransaction();
            $notifications = $this->persistenceNotifications();
        } catch (\Throwable $error) {
            if ($context->database->inTransaction()) {
                try { $context->database->rollbackTransaction(); }
                catch (\Throwable) { /* Preserve the original callback/commit exception. */ }
            }
            throw $error;
        } finally {
            $this->transactionContext = null;
            $this->transactionNotifications = [];
        }
        // Hook failures happen after commit and must not trigger rollback.
        foreach ($notifications as [$model, $deleted]) { $model->notifyPersistence($deleted); }
        return $result;
    }

    /** @internal Immediate saves accept state now, but notify hooks only after outer commit. */
    public function notifyPersistence(Model $model, bool $deleted): void
    {
        if ($this->transactionContext !== null) {
            $this->transactionNotifications[] = [$model, $deleted];
        } else {
            $model->notifyPersistence($deleted);
        }
    }

    /** @return list<array{Model, bool}> */
    private function persistenceNotifications(): array { return $this->transactionNotifications; }

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

    /** @internal Detach each completed/failed save so later saves cannot flush it again. */
    public function releaseWriteContext(DbContext $context, Model $model, EntityMapping $mapping): void
    {
        $context->changeTracker->setState($mapping, $model, \Light\Entity\EntityState::Detached);
        // Keep fresh, convention-aware relationship reads separate from the shared
        // write context, which discovers each Model mapping as writes are staged.
        $relations = $context === $this->transactionContext ? new ModelDbContext($this->pdo, $mapping) : $context;
        $model->attachRelations($relations, $mapping, \Light\Entity\QueryTrackingBehavior::NoTracking);
    }

    /** @internal One Model save must not flush changes belonging to another Model. */
    public function writeContext(EntityMapping $mapping): DbContext
    {
        return $this->transactionContext ?? new ModelDbContext($this->pdo, $mapping);
    }

    public function clearMetadata(): void
    {
        $this->contexts = [];
        $this->metadata->clear();
    }
}
