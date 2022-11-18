Doctrine Entity Generator
=========================
This bundle provides a console command to generate doctrine entities with mapping attributes and a mapper class from
domain entity class.

Usage
-----

```bash
bin/console doctrine-generator:entity "App\Domain\MyDomainEntity"
```

Results
-------

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
    public function __construct(private string $firstAttribute, private int $secondAttribute)
    {
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

```php
// src/Domain/MyDomainEntity.php
class MyDomainEntity {
    public function __construct(
        private MyDomainEntityId $id,
        private SampleValueObject $valueObject,
    ) {}

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
Creates a doctrine entity and a mapper between them.

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
    public static function fromDomain(
        MyDomainEntity $domainEntity,
        ?DoctrineMyDomainEntity $doctrineEntity,
    ): DoctrineMyDomainEntity {
        $doctrineEntity = $doctrineEntity ?? new DoctrineMyDomainEntity();

        $doctrineEntity->id = $domainEntity->getId()->getValue();
        $doctrineEntity->valueObject_firstAttribute = $domainEntity->getValueObject()->getFirstAttribute();
        $doctrineEntity->valueObject_secondAttribute = $domainEntity->getValueObject()->getSecondAttribute();

        return $doctrineEntity;
    }

    public static function toDomain(DoctrineMyDomainEntity $doctrineEntity): MyDomainEntity
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
            new SampleValueObject($doctrineEntity->valueObject_firstAttribute, $doctrineEntity->valueObject_secondAttribute),
        );
        return $object;
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
$ composer require brokalia/doctrine-entity-generator
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require brokalia/doctrine-entity-generator
```

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Brokalia\DoctrineEntityGenerator\DoctrineEntityGeneratorBundle::class => ['all' => true],
];
```
