<?php

declare(strict_types=1);

namespace App\Contact\Command;

use App\Contact\Repository\ContactRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retention promised in the privacy policy: contact messages are kept at most
 * twelve months. [o2switch] Run through a cPanel cron, no worker required.
 */
#[AsCommand(name: 'app:contact:purge', description: 'Delete contact messages older than twelve months')]
final class PurgeContactsCommand extends Command
{
    public function __construct(private readonly ContactRepository $contactRepository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->contactRepository->deleteOlderThan(new \DateTimeImmutable('-12 months'));

        $output->writeln(\sprintf('%d contact message(s) deleted.', $deleted));

        return Command::SUCCESS;
    }
}
