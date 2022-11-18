<?php

declare(strict_types=1);

namespace Brokalia\DoctrineEntityGenerator\Command;

use Brokalia\DoctrineEntityGenerator\Model\EntityProperty;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'doctrine-generator:entity',
    description: 'Generates a doctrine entity and a mapper from domain entity'
)]
class DoctrineEntityGeneratorCommand extends Command
{
    private array $entityProperties = [];

    public function __construct()
    {
        parent::__construct();
        $this->addArgument('entity', InputArgument::REQUIRED, 'Domain entity with namespace');
    }

    /**
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityClassName = $input->getArgument('entity');
        $doctrineEntity = $this->buildDoctrineEntity($entityClassName);
        $this->createMapper($entityClassName, $doctrineEntity);

        return 0;
    }

    /**
     * @throws ReflectionException
     */
    private function buildDoctrineEntity(string $entityClassName): ClassType
    {
        $reflector = new ReflectionClass($entityClassName);
        $doctrineEntityNamespace = $this->buildInfrastructureNamespace($reflector->getNamespaceName());

        $namespace = new PhpNamespace($doctrineEntityNamespace);
        $namespace->addUse('\Doctrine\ORM\Mapping', 'ORM');

        $doctrineEntity = $namespace->addClass('Doctrine' . $reflector->getShortName());
        $doctrineEntity->addAttribute(Table::class, ['name' => mb_strtolower($reflector->getShortName()) . 's']);
        $doctrineEntity->addAttribute(Entity::class);

        foreach ($reflector->getProperties() as $property) {
            if ($property->getType() && $property->getType()->isBuiltin()) {
                $this->addScalarProperty($property, $doctrineEntity, $reflector);
            } elseif ($property->getType() && !$property->getType()->isBuiltin()) {
                $this->addValueObjectProperty($namespace, $property, $doctrineEntity, $reflector);
            }
        }

        $this->createDirectoryIfNotExists($doctrineEntityNamespace);
        file_put_contents(
            $this->getCompletePathForNamespace($doctrineEntityNamespace, $doctrineEntity->getName()),
            "<?php\n\ndeclare(strict_types=1);\n\n" . (new PsrPrinter())->printNamespace($namespace) . "\n"
        );

        return $doctrineEntity;
    }

    /**
     * @throws ReflectionException
     */
    private function createMapper(string $entityClassName, ClassType $doctrineEntity): void
    {
        $reflector = new ReflectionClass($entityClassName);
        $doctrineEntityNamespace = $this->buildInfrastructureNamespace($reflector->getNamespaceName());

        $namespace = new PhpNamespace($doctrineEntityNamespace);
        $namespace->addUse($reflector->getName());
        $namespace->addUse($doctrineEntityNamespace . '\\' . $doctrineEntity->getName());
        $namespace->addUse(EntityManagerInterface::class);
        $namespace->addUse(ReflectionClass::class);
        $namespace->addUse(RuntimeException::class);

        // Add value objects use
        $constructor = $reflector->getMethod('__construct');
        foreach ($constructor->getParameters() as $parameter) {
            if (false === $parameter->getType()?->isBuiltin()) {
                $namespace->addUse('\\' . $parameter->getType()?->getName());
            }
        }

        $mapper = $namespace->addClass('Doctrine' . $reflector->getShortName() . 'Mapper');
        $mapper->addProperty('entityManager')->setType(EntityManagerInterface::class)->setPrivate()->setReadOnly();
        $mapper->addMethod('__construct')->setParameters([
            (new Parameter('entityManager'))->setType(EntityManagerInterface::class),
        ])->setBody('$this->entityManager = $entityManager;');

        $valueObjectMapping = $this->generateFromDomain(
            $doctrineEntity,
            $mapper,
            $entityClassName,
            $doctrineEntityNamespace
        );
        $this->generateToDomain(
            $doctrineEntity,
            $mapper,
            $entityClassName,
            $doctrineEntityNamespace,
            $reflector,
            $valueObjectMapping
        );

        file_put_contents(
            $this->getCompletePathForNamespace($doctrineEntityNamespace, $mapper->getName()),
            "<?php\n\ndeclare(strict_types=1);\n\n" . (new PsrPrinter())->printNamespace($namespace) . "\n"
        );
    }

    private function getDoctrineType(string $nativeType): string
    {
        return match ($nativeType) {
            'bool' => 'boolean',
            'int' => 'integer',
            'DateTimeImmutable' => 'datetime_immutable',
            default => $nativeType,
        };
    }

    private function addScalarProperty(
        ReflectionProperty $property,
        ClassType $doctrineEntity,
        ReflectionClass $domainEntityReflector
    ): void {
        $type = $property->getType()?->getName();
        $doctrineEntity->addProperty($property->getName())
            ->setType($type)
            ->setNullable($property->getType()?->allowsNull())
            ->addAttribute(Column::class, ['type' => $this->getDoctrineType($type)]);

        $this->entityProperties[] = new EntityProperty(
            $property->getName(),
            $type,
            $this->getAccessMethod($domainEntityReflector, $property),
            []
        );
    }

    /**
     * @throws ReflectionException
     */
    private function addValueObjectProperty(
        PhpNamespace $namespace,
        ReflectionProperty $property,
        ClassType $doctrineEntity,
        ReflectionClass $domainEntityReflector,
    ): void {
        $valueObjectReflector = new ReflectionClass($property->getType()?->getName());
        $this->recordEntityProperty($property, $domainEntityReflector);

        if ($valueObjectReflector->hasProperty('value')) {
            $this->addSimpleValueObject($namespace, $property, $valueObjectReflector, $doctrineEntity);
        } else {
            $this->addMultipleValueObject($property, $valueObjectReflector, $doctrineEntity);
        }
    }

    /**
     * @throws ReflectionException
     */
    private function addSimpleValueObject(
        PhpNamespace $namespace,
        ReflectionProperty $property,
        ReflectionClass $valueObjectReflector,
        ClassType $doctrineEntity
    ): void {
        $type = $valueObjectReflector->getProperty('value')->getType()?->getName();
        if (!$valueObjectReflector->getProperty('value')->getType()?->isBuiltin()) {
            $namespace->addUse($type);
        }
        $p = $doctrineEntity->addProperty($property->getName())
            ->setType($type)
            ->setNullable($property->getType()?->allowsNull() ?? false)
            ->addAttribute(Column::class, ['type' => $this->getDoctrineType($type)]);
        if ($property->getName() === 'id') {
            $p->addAttribute(Id::class);
        }
    }

    private function addMultipleValueObject(
        ReflectionProperty $property,
        ReflectionClass $valueObjectReflector,
        ClassType $doctrineEntity
    ): void {
        foreach ($valueObjectReflector->getProperties() as $valueObjectProperty) {
            $type = $valueObjectProperty->getType()?->getName();

            $doctrineEntity
                ->addProperty($property->getName() . '_' . $valueObjectProperty->getName())
                ->setType($type)
                ->setNullable($valueObjectProperty->getType()?->allowsNull())
                ->addAttribute(Column::class, ['type' => $this->getDoctrineType($type)]);
        }
    }

    public function getCompletePathForNamespace(string $namespace, string $className): string
    {
        return $this->getDirectoryByNamespace($namespace) . $className . '.php';
    }

    private function getDirectoryByNamespace(string $namespace): string
    {
        $parts = explode('\\', $namespace);

        $path = 'src/';
        foreach ($parts as $index => $value) {
            if ($index === 0) {
                continue;
            }
            $path .= $value . '/';
        }

        return $path;
    }

    /**
     * @throws ReflectionException
     */
    private function recordEntityProperty(ReflectionProperty $property, ReflectionClass $domainEntityReflector): void
    {
        $propertyReflector = new ReflectionClass($property->getType()?->getName());
        $internalProperties = [];
        foreach ($propertyReflector->getProperties() as $childProperty) {
            if ($childProperty->getType()?->isBuiltin()) {
                $internalProperties[] = new EntityProperty(
                    $childProperty->getName(),
                    $childProperty->getType()?->getName(),
                    $this->getAccessMethod($propertyReflector, $childProperty),
                    []
                );
            } else {
                throw new RuntimeException('No sabemos manejar ' . $property->getName());
            }
        }

        $this->entityProperties[] = new EntityProperty(
            $property->getName(),
            "\\" . $property->getType()?->getName(),
            $this->getAccessMethod($domainEntityReflector, $property),
            $internalProperties
        );
    }

    private function getAccessMethod(ReflectionClass $domainEntityReflector, ReflectionProperty $property): ?string
    {
        foreach ($domainEntityReflector->getMethods() as $method) {
            if ($method->getReturnType() && $method->getReturnType()->getName() === $property->getType()?->getName()) {
                return $method->getName();
            }
        }

        return null;
    }

    private function createDirectoryIfNotExists(array|string $doctrineEntityNamespace): void
    {
        $directory = $this->getDirectoryByNamespace($doctrineEntityNamespace);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
    }

    private function generateFromDomain(
        ClassType $doctrineEntity,
        ClassType $mapper,
        string $entityClassName,
        array|string $doctrineEntityNamespace
    ): array {
        $valueObjectParameters = [];
        $body = '$doctrineEntity = $doctrineEntity ?? new ' . $doctrineEntity->getName() . "();\n\n";
        /** @var EntityProperty $entityProperty */
        foreach ($this->entityProperties as $entityProperty) {
            if (count($entityProperty->properties) === 0) {
                $accessMethod = $entityProperty->accessMethod
                    ? ($entityProperty->accessMethod . '()')
                    : $entityProperty->name;
                $body .= '$doctrineEntity->' . $entityProperty->name . ' = $domainEntity->' . $accessMethod . ";\n";
            } elseif (count($entityProperty->properties) === 1) {
                $valueObjectParameters[$entityProperty->type] = [];
                foreach ($entityProperty->properties as $subProperty) {
                    $valueObjectParameters[$entityProperty->type][] = '$doctrineEntity->' . $entityProperty->name;
                    $accessMethodProperty = $entityProperty->accessMethod
                        ? ($entityProperty->accessMethod . '()')
                        : $entityProperty->name;
                    $accessMethod = $subProperty->accessMethod
                        ? ($subProperty->accessMethod . '()')
                        : $subProperty->name;
                    $body .= '$doctrineEntity->' . $entityProperty->name . ' = $domainEntity->' . $accessMethodProperty . '->' . $accessMethod . ";\n";
                }
            } else {
                $valueObjectParameters[$entityProperty->type] = [];
                foreach ($entityProperty->properties as $subProperty) {
                    $valueObjectParameters[$entityProperty->type][] = '$doctrineEntity->' . $entityProperty->name . '_' . $subProperty->name;
                    $accessMethodProperty = $entityProperty->accessMethod
                        ? ($entityProperty->accessMethod . '()')
                        : $entityProperty->name;
                    $accessMethod = $subProperty->accessMethod
                        ? ($subProperty->accessMethod . '()')
                        : $subProperty->name;
                    $body .= '$doctrineEntity->' . $entityProperty->name . '_' . $subProperty->name . ' = $domainEntity->' . $accessMethodProperty . '->' . $accessMethod . ";\n";
                }
            }
        }

        $body .= "\n" . 'return $doctrineEntity;' . "\n";

        $mapper->addMethod('fromDomain')
            ->setParameters([
                (new Parameter('domainEntity'))->setType($entityClassName),
                (new Parameter('doctrineEntity'))->setType(
                    $doctrineEntityNamespace . '\\' . $doctrineEntity->getName()
                )->setNullable(),
            ])
            ->setReturnType($doctrineEntityNamespace . '\\' . $doctrineEntity->getName())
            ->setStatic()
            ->setBody($body);

        return $valueObjectParameters;
    }

    /**
     * @throws ReflectionException
     */
    private function generateToDomain(
        ClassType $doctrineEntity,
        ClassType $mapper,
        string $entityClassName,
        array|string $doctrineEntityNamespace,
        ReflectionClass $domainEntityReflector,
        array $valueObjectMapping,
    ): void {
        $parts = explode('\\', $entityClassName);
        $entityClassShortName = $parts[count($parts) - 1];
        $body = '$reflector = new ReflectionClass(' . $entityClassShortName . '::class);' . "\n";
        $body .= '$constructor = $reflector->getConstructor();' . "\n";
        $body .= 'if (!$constructor) {' . "\n";
        $body .= '    throw new RuntimeException(\'No constructor\');' . "\n";
        $body .= '}' . "\n";
        $body .= '$object = $reflector->newInstanceWithoutConstructor();' . "\n";
        $body .= '$constructor->invoke(' . "\n";
        $body .= '    $object,' . "\n";

        $constructor = $domainEntityReflector->getMethod('__construct');
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getType()?->isBuiltin()) {
                $body .= '    $doctrineEntity->' . $parameter->getName() . ',' . "\n";
            } else {
                $parts = explode('\\', $parameter->getType()?->getName());
                $valueObjectName = $parts[count($parts) - 1];
                $params = $valueObjectMapping['\\' . $parameter->getType()?->getName()];
                $body .= '    new ' . $valueObjectName . '(' . implode(', ', $params) . '),' . "\n";
            }
        }

        $body .= ');' . "\n";
        $body .= 'return $object;' . "\n";


        $mapper->addMethod('toDomain')
            ->setParameters([
                (new Parameter('doctrineEntity'))->setType(
                    $doctrineEntityNamespace . '\\' . $doctrineEntity->getName()
                ),
            ])
            ->setReturnType('\\' . $entityClassName)
            ->setStatic()
            ->setBody($body);
    }

    private function buildInfrastructureNamespace(string $domainNamespace): string
    {
        return str_replace('Domain', 'Infrastructure', $domainNamespace) . '\Persistence';
    }
}
