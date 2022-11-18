<?php

declare(strict_types=1);

namespace Brokalia\DoctrineEntityGenerator\CompilerPass;

use Brokalia\DoctrineEntityGenerator\Command\DoctrineEntityGeneratorCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CommandRegistrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->register('doctrine_entity_generator.command', DoctrineEntityGeneratorCommand::class)
            ->addTag('console.command');
    }
}
