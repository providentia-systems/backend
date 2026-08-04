<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Infrastructure\Cli;

use Providentia\AiIntegration\Application\Media\PrivateMediaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:video:process', description: 'Derive encrypted review frames from queued private videos.')]
final class VideoProcessCommand extends Command
{
    public function __construct(private readonly PrivateMediaService $media)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one video and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $processed = $this->media->processVideoOnce();
            if ($processed) {
                $output->writeln('Processed one private video.');
            } elseif (! $input->getOption('once')) {
                usleep(500_000);
            }
            $this->media->purgeExpired(100);
        } while (! $input->getOption('once'));

        return Command::SUCCESS;
    }
}
