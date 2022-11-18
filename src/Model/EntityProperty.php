<?php

declare(strict_types=1);

namespace Brokalia\DoctrineEntityGenerator\Model;

class EntityProperty
{
    /**
     * @param array<int, EntityProperty> $properties
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string|null $accessMethod,
        public readonly array $properties,
    ) {
    }
}
