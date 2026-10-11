<?php

namespace App\Tests\Service\Cache;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The test env must never talk to the doctrine messenger transport: the
 * messenger_messages table is not part of the ORM schema SchemaTool builds,
 * so a real dispatch would fire auto_setup DDL inside every WebTestCase.
 */
class MessengerTestTransportTest extends KernelTestCase
{
    public function testAsyncTransportIsInMemoryUnderTest(): void
    {
        self::bootKernel();

        $transport = self::getContainer()->get('messenger.transport.async');

        $this->assertInstanceOf(InMemoryTransport::class, $transport);
    }
}
