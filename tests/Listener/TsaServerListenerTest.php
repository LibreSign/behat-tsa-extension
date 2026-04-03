<?php

declare(strict_types=1);

namespace LibreSign\Behat\TsaExtension\Tests\Listener;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use LibreSign\Behat\TsaExtension\Listener\TsaServerListener;
use PHPUnit\Framework\TestCase;

final class TsaServerListenerTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $events = TsaServerListener::getSubscribedEvents();

        self::assertArrayHasKey(BeforeSuiteTested::BEFORE, $events);
        self::assertArrayHasKey(AfterSuiteTested::AFTER, $events);
    }
}
