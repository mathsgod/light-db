<?php

declare(strict_types=1);

namespace Light\Db;

use Light\Entity\{DbContext, EntityMapping, ModelBuilder};
use PDO;

/** Registers Active Record conventions with the native model-building pipeline. @internal */
final class ModelDbContext extends DbContext
{
    private readonly array $modelClasses;

    public function __construct(PDO $pdo, EntityMapping ...$mappings)
    {
        $this->modelClasses = array_map(static fn (EntityMapping $mapping) => $mapping->class, $mappings);
        parent::__construct($pdo, mappings: $mappings);
    }

    protected function onModelCreating(ModelBuilder $builder): void
    {
        parent::onModelCreating($builder);
        foreach ($this->modelClasses as $class) { $class::configureEntity($builder->entity($class)); }
    }
}
