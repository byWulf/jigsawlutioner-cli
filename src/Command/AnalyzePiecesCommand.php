<?php

namespace App\Command;

use App\Message\AnalyzePieceMessage;
use App\Service\PieceLoader;
use Doctrine\DBAL\Exception as DbalException;
use JsonException;
use App\Repository\MessengerRepository;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'app:pieces:analyze')]
class AnalyzePiecesCommand extends Command
{
    public function __construct(
        private readonly PieceLoader $pieceLoader,
        private readonly MessageBusInterface $messageBus,
        private readonly MessengerRepository $messengerRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('set', InputArgument::REQUIRED, 'Name of set folder');
        $this->addArgument('pieceNumber', InputArgument::IS_ARRAY, 'Piece number if you only want to analyze one piece. If omitted all pieces will be analyzed.');
    }

    /**
     * @throws JsonException
     * @throws DbalException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $setName = $input->getArgument('set');

        $numbers = $this->pieceLoader->getPieceNumbers($setName);
        if (count($input->getArgument('pieceNumber')) > 0) {
            $numbers = array_map('intval', $input->getArgument('pieceNumber'));
        }
        $pieceCount = count($numbers);

        $this->messengerRepository->clearQueue('analyze_pieces');
        foreach ($numbers as $pieceNumber) {
            $this->messageBus->dispatch(new AnalyzePieceMessage($setName, (int) $pieceNumber));
        }

        // Start up to 10 consumers
        /** @var Process[] $processes */
        $processes = [];
        for ($i = 1; $i <= min(10, $pieceCount); $i++) {
            $process = new Process([ PHP_BINARY, __DIR__ . '/../../bin/console', 'messenger:consume', 'analyze_pieces']);
            $process->start();
            $processes[] = $process;
        }

        $progress = new ProgressBar($output);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progress->start($pieceCount);
        do {
            $messageCount = $this->messengerRepository->getQueuedMessagesCount('analyze_pieces');
            $progress->setProgress($pieceCount - $messageCount);

            usleep(100000);
        } while ($messageCount > 0);

        $progress->finish();
        $output->writeln('');

        foreach ($processes as $process) {
            $process->stop();
        }

        $io->success('Finished.');

        return self::SUCCESS;
    }
}
