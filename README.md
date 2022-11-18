Doctrine Entity Generator
=========================
This Symfony bundle provides a console command to generate doctrine entities with mapping attributes and a mapper class
from domain entity class.

Then you can use domain entities in your domain and application layers and map it to doctrine entities for persistence.

Usage
-----

```bash
bin/console doctrine-generator:entity "App\Domain\MyDomainEntity"
```

Conventions
-----------

The primary key of the doctrine entity must be the "id" property of domain entity. If there are not an "id" property in 
the domain entity, the doctrine entity will not have primary key.

```php
class MyDomainEntity {
    private string $id; // Will be the doctrine primary key
}

class DoctrineMyDomainEntity {
    #[ORM\Column(type: 'string')]
    #[ORM\Id]
    public string $id;
}
```

Complete Example
----------------

Given MyDomainEntity with some value objects:

```php
// src/Domain/MyDomainEntity.php
class MyDomainEntity {
    public function __construct(
        private MyDomainEntityId $id,
        private SampleValueObject $valueObject,
    ) {
    }

    public function getId(): MyDomainEntityId
    {
        return $this->id;
    }

    public function getValueObject(): SampleValueObject
    {
        return $this->valueObject;
    }
}
```

```php
// src/Domain/MyDomainEntityId.php
class MyDomainEntityId
{
    public function __construct(private string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
```

```php
// src/Domain/EntityId.php
class SampleValueObject
{
    public function __construct(
        private string $firstAttribute, 
        private int $secondAttribute
    ) {
    }

    public function getFirstAttribute(): string
    {
        return $this->firstAttribute;
    }

    public function getSecondAttribute(): int
    {
        return $this->secondAttribute;
    }
}
```

Generates a doctrine entity and a mapper to map between domain and doctrine entities:

```php
// src/Infrastructure/Persistence/DoctrineMyDomainEntity.php
class DoctrineMyDomainEntity
{
    #[ORM\Column(type: 'string')]
    #[ORM\Id]
    public string $id;

    #[ORM\Column(type: 'string')]
    public string $valueObject_firstAttribute;

    #[ORM\Column(type: 'integer')]
    public int $valueObject_secondAttribute;
}
```

```php
// src/Infrastructure/Persistence/DoctrineMyDomainEntityMapper.php
class DoctrineMyDomainEntityMapper
{
    public function fromDomain(
        MyDomainEntity $domainEntity,
        ?DoctrineMyDomainEntity $doctrineEntity,
    ): DoctrineMyDomainEntity {
        $doctrineEntity = $doctrineEntity ?? new DoctrineMyDomainEntity();

        $doctrineEntity->id = $domainEntity->getId()->getValue();
        $doctrineEntity->valueObject_firstAttribute = $domainEntity->getValueObject()->getFirstAttribute();
        $doctrineEntity->valueObject_secondAttribute = $domainEntity->getValueObject()->getSecondAttribute();

        return $doctrineEntity;
    }

    public function toDomain(DoctrineMyDomainEntity $doctrineEntity): MyDomainEntity
    {
        $reflector = new ReflectionClass(MyDomainEntity::class);
        $constructor = $reflector->getConstructor();
        if (!$constructor) {
            throw new RuntimeException('No constructor');
        }
        $object = $reflector->newInstanceWithoutConstructor();
        $constructor->invoke(
            $object,
            new MyDomainEntityId($doctrineEntity->id),
            new SampleValueObject(
                $doctrineEntity->valueObject_firstAttribute, 
                $doctrineEntity->valueObject_secondAttribute
            ),
        );
        return $object;
    }
}
```
Now you can create a repository for the domain entity using doctrine to persist the entity with the mapper.

```php
class MyDomainEntityRepository {
    public function __construct(
        private EntityManagerInterface $entityManager, 
        private DoctrineMyDomainEntityMapper $mapper,
    ) {
    }
    
    public function save(MyDomainEntity $entity): void
    {
        // Get previous existent doctrine entity if exists for update cases
        $existentDoctrineEntity = $this->entityManager
            ->getRepository(DoctrineMyDomainEntity::class)
            ->find($entity->getId()->getValue());
            
        // Map domain entity to doctrine entity
        $doctrineEntity = $this->mapper->fromDomain(
            $entity, 
            $existentDoctrineEntity ?? new DoctrineMyDomainEntity()
        );
        
        // Persist
        $this->entityManager->persist($doctrineEntity);
        $this->entityManager->flush();
    }
    
    public function findById(MyDomainEntityId $id): ?MyDomainEntity 
    {
        // Get doctrine entity
        $doctrineEntity = $this->entityManager
            ->getRepository(DoctrineMyDomainEntity::class)
            ->find($entity->getId()->getValue());
            
        if (!$doctrineEntity) {
            return null;
        }
        
        // Return domain entity mapped
        return $this->mapper->toDomain($doctrineEntity);
    }
}
```

Installation
============

Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```console
$ composer require --dev brokalia/doctrine-entity-generator
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require --dev brokalia/doctrine-entity-generator
```

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Brokalia\DoctrineEntityGenerator\DoctrineEntityGeneratorBundle::class => ['dev' => true],
];
```
