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
    /** @var array<int, array{Model, bool}>|null */
    private ?array $pending = null;

    /** Queue Model writes and commit them with one saveChanges(). Returns the callback result. */
    public function transaction(callable $callback): mixed
    {
        if ($this->pending !== null || $this->pdo->inTransaction()) {
            throw new \LogicException('Nested or externally opened transactions are not supported.');
        }
        $this->pending = [];
        try {
            $result = $callback();
            $pending = $this->pendingWrites();
            if ($pending !== []) {
                $mappings = [];
                foreach ($pending as [$model]) {
                    $mappings[$model::class] = $this->metadata($model::class);
                }
                $context = new ModelDbContext($this->pdo, ...array_values($mappings));
                try {
                    foreach ($pending as [$model, $delete]) {
                        $model->stageWrite($context, $delete);
                    }
                    $context->saveChanges();
                    $notifications = [];
                    foreach ($pending as [$model, $delete]) {
                        if ($model->completeWrite($delete)) $notifications[] = [$model, $delete];
                    }
                    foreach ($notifications as [$model, $delete]) {
                        $model->notifyPersistence($delete);
                    }
                } finally {
                    foreach ($pending as [$model]) {
                        $context->entry($model)->state = \Light\Entity\EntityState::Detached;
                        $model->attachRelations($context, $this->metadata($model::class), \Light\Entity\QueryTrackingBehavior::NoTracking);
                    }
                }
            }
            return $result;
        } finally {
            $this->pending = null;
        }
    }

    /** @return array<int, array{Model, bool}> */
    private function pendingWrites(): array { return $this->pending ?? []; }

    /** @internal Immediate bulk writes cannot participate in deferred Model transactions. */
    public function assertImmediateWritesAllowed(): void
    {
        if ($this->pending !== null) {
            throw new \LogicException('Bulk writes are not supported inside a deferred Model transaction.');
        }
    }

    /** @internal Returns true when the write has been deferred. Last operation per Model wins. */
    public function deferWrite(Model $model, bool $delete): bool
    {
        if ($this->pending === null) { return false; }
        $this->pending[spl_object_id($model)] = [$model, $delete];
        return true;
    }


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
