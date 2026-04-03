<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\ServiceContainer;

use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TsaExtension implements Extension
{
    public function getConfigKey(): string
    {
        return 'libresign_tsa';
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    public function configure(ArrayNodeDefinition $builder): void
    {
        $builder
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('host')->defaultValue('127.0.0.1')->end()
                ->integerNode('port')->defaultValue(0)->min(0)->max(65535)->end()
                ->scalarNode('path')->defaultValue('/tsr')->end()
                ->scalarNode('policy_oid')->defaultValue('1.2.3.4.1')->end()
                ->scalarNode('env_var')->defaultValue('LIBRESIGN_TSA_URL')->end()
                ->booleanNode('verbose')->defaultFalse()->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function load(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(
            \LibreSign\Behat\TsaExtension\Listener\TsaServerListener::class,
            [
                (bool) $config['enabled'],
                (string) $config['host'],
                (int) $config['port'],
                (string) $config['path'],
                (string) $config['policy_oid'],
                (string) $config['env_var'],
                (bool) $config['verbose'],
            ]
        );

        $definition->addTag('event_dispatcher.subscriber');
        $container->setDefinition('libresign.tsa.listener', $definition);
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
