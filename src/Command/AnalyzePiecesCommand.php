<?php

namespace App\Command;

use Amp\Parallel\Worker\DefaultPool;
use App\Service\PieceLoader;
use App\Task\AnalyzePieceTask;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Amp\call;
use function Amp\Promise\all;
use function Amp\Promise\wait;

#[AsCommand(name: 'app:pieces:parallel-analyze')]
class AnalyzePiecesCommand extends Command
{
    public function __construct(
        private readonly PieceLoader $pieceLoader,
        private readonly string      $setDirectory,
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

        $pool = new DefaultPool();

        $coroutines = [];
        $setDirectory = $this->setDirectory;

        $processedPieces = 0;
        $progress = new ProgressBar($output);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progress->start($pieceCount);

        foreach ($numbers as $pieceNumber) {
            $coroutines[] = call(function() use ($pool, $setName, $pieceNumber, $setDirectory, &$processedPieces, $progress) {
                yield $pool->enqueue(new AnalyzePieceTask($setName, (int) $pieceNumber, $setDirectory));

                $processedPieces++;
                $progress->setProgress($processedPieces);
            });
        }

        wait(all($coroutines));


        $progress->finish();

        $output->writeln('');

        $io->success('Finished.');

        return self::SUCCESS;
    }
}
