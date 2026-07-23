<?php

declare(strict_types=1);

namespace App\Tests\Contact;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeContactsCommandTest extends KernelTestCase
{
    public function testItDeletesOnlyContactsOlderThanTwelveMonths(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get('doctrine')->getConnection();

        $connection->executeStatement('DELETE FROM contact');
        $connection->executeStatement(
            "INSERT INTO contact (name, email, message, created_at) VALUES
                ('Old Contact', 'old@example.com', 'Old message', :old),
                ('Recent Contact', 'recent@example.com', 'Recent message', :recent)",
            [
                'old' => new \DateTimeImmutable('-13 months')->format('Y-m-d H:i:s'),
                'recent' => new \DateTimeImmutable('-1 month')->format('Y-m-d H:i:s'),
            ],
        );

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:contact:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 contact message(s) deleted.', $tester->getDisplay());

        $emails = $connection->fetchFirstColumn('SELECT email FROM contact');
        self::assertSame(['recent@example.com'], $emails);
    }
}
