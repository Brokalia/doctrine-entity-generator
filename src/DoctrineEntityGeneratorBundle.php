<?php

declare(strict_types=1);

namespace Brokalia\DoctrineEntityGenerator;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class DoctrineEntityGeneratorBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new CommandRegistrationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
}
