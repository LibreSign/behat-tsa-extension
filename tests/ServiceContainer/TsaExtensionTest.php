<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\ServiceContainer;

use LibreSign\Behat\TsaExtension\ServiceContainer\TsaExtension;
use PHPUnit\Framework\TestCase;

final class TsaExtensionTest extends TestCase
{
    public function testConfigKey(): void
    {
        $extension = new TsaExtension();

        self::assertSame('libresign_tsa', $extension->getConfigKey());
    }
}
