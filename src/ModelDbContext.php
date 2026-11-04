<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{DbContext, EntityMapping, ModelBuilder};
use PDO;

/** Registers Active Record conventions with the native model-building pipeline. @internal */
final class ModelDbContext extends DbContext
{
    private readonly string $modelClass;

    public function __construct(PDO $pdo, EntityMapping $mapping)
    {
        $this->modelClass = $mapping->class;
        parent::__construct($pdo, mappings: [$mapping]);
    }

    protected function onModelCreating(ModelBuilder $builder): void
    {
        parent::onModelCreating($builder);
        $this->modelClass::configureEntity($builder->entity($this->modelClass));
    }
}
