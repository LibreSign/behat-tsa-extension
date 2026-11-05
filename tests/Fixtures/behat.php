<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use LibreSign\Behat\TsaExtension\ServiceContainer\TsaExtension;
use LibreSign\Behat\TsaExtension\Tests\Fixtures\FeatureContext;

return (new Config())->withProfile(
    (new Profile('default'))
        ->withSuite((new Suite('tsa'))->withContexts(FeatureContext::class)->withPaths(__DIR__ . '/tsa.feature'))
        ->withExtension(new Extension(TsaExtension::class, [
            'host' => getenv('TSA_TEST_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TSA_TEST_PORT') ?: 0),
            'env_var' => 'LIBRESIGN_TSA_URL',
            'path' => getenv('TSA_TEST_PATH') ?: '/tsr',
            'verbose' => true,
        ]))
);
