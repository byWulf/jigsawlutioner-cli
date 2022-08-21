<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PieceLoader;
use App\Service\SolutionOutputter;
use Bywulf\Jigsawlutioner\Dto\Context\SolutionReport;
use Bywulf\Jigsawlutioner\Dto\Piece;
use Bywulf\Jigsawlutioner\Dto\ReducedPiece;
use Bywulf\Jigsawlutioner\Exception\PuzzleSolverException;
use Bywulf\Jigsawlutioner\Service\MatchingMapGenerator;
use Bywulf\Jigsawlutioner\Service\PuzzleSolver\ByWulfSolver;
use Bywulf\Jigsawlutioner\Service\SideMatcher\WeightedMatcher;
use DateTime;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(name: 'app:puzzle:solve')]
class SolvePuzzleCommand extends Command
{
    private string $currentMessage = '';

    private ?DateTime $currentStartDate = null;

    private string $messages = '';

    private ?ConsoleSectionOutput $messageSection = null;

    private ?ProgressBar $groupsProgressBar = null;

    private ?ProgressBar $biggestGroupProgressBar = null;

    private ByWulfSolver $solver;

    private MatchingMapGenerator $matchingMapGenerator;

    public function __construct(
        private readonly PieceLoader $pieceLoader,
        private readonly string $setDirectory,
        private readonly CacheInterface $cache,
        private readonly SolutionOutputter $solutionOutputter,
        private readonly Filesystem $filesystem,
    )
    {
        parent::__construct();

        $this->matchingMapGenerator = new MatchingMapGenerator(new WeightedMatcher());
        $this->solver = new ByWulfSolver();
        $this->solver->setStepProgressionCallback(function (string $message, int $groups, int $biggestGroup): void {
            $this->addMessage($message, $groups, $biggestGroup);
            $this->groupsProgressBar->setProgress($groups);
            $this->biggestGroupProgressBar->setProgress($biggestGroup);
        });
    }

    protected function configure(): void
    {
        $this->addArgument('set', InputArgument::REQUIRED, 'Name of set folder');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force rebuilding the matchingMap and not loading it from cache.');
        $this->addArgument('pieces', InputArgument::OPTIONAL, 'Comma separated list of piece ids that should be processed from the given set.');
        $this->addOption('use-report', 'r', InputOption::VALUE_OPTIONAL, 'Specify the number of the solution report if you want to continue from this solution');
    }

    /**
     * @throws PuzzleSolverException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$output instanceof ConsoleOutputInterface) {
            throw new InvalidArgumentException('Need a ConsoleOutput');
        }

        $setName = $input->getArgument('set');
        $pieces = $this->getPieces($setName, $input);

        $this->solver->setReportSolutionCallback(function(SolutionReport $report) use ($setName): void {
            $this->filesystem->dumpFile(
                $this->setDirectory . $setName . '/solution_report' . $report->getSolutionStep() . '.ser',
                serialize($report),
            );
        });

        $this->messageSection = $output->section();
        $this->initializeProgressBars($output, count($pieces));

        $cachingKey = 'matchingMap_' . $setName;
        if ($input->getOption('force')) {
            $this->cache->delete($cachingKey);
        }

        $matchingMap = $this->cache->get($cachingKey, function(CacheItem $item) use ($pieces) {
            $item->expiresAfter(86400);

            $this->addMessage('Creating matching map...');
            return $this->matchingMapGenerator->getMatchingMap($pieces);
        });

        /** @var string|null $solutionReportId */
        $solutionReportId = $input->getOption('use-report');
        if ($solutionReportId !== null) {
            /** @var SolutionReport $solutionReport */
            $solutionReport = unserialize(file_get_contents($this->setDirectory . $setName . '/solution_report' . $solutionReportId . '.ser'));
            $this->solver->setSolutionReport($solutionReport);
        }

        $solution = $this->solver->findSolution(
            array_map(fn (Piece $piece): ReducedPiece => ReducedPiece::fromPiece($piece), $pieces),
            $matchingMap,
        );

        $this->addMessage('');
        $this->groupsProgressBar->display();
        $this->biggestGroupProgressBar->display();

        $output->writeln('Transforming to json solution...');

        $jsonFile = $this->setDirectory . $setName . '/solution.json';
        $this->solutionOutputter->outputAsJson($solution, $jsonFile);

        $output->writeln('Transforming to html solution...');

        $htmlFile = $this->setDirectory . $setName . '/solution.html';
        $this->solutionOutputter->outputAsHtml(
            $setName,
            $solution,
            $htmlFile,
            $this->setDirectory . $setName . '/piece%s_transparent_small.png'
        );

        $output->writeln('Solution dumped to ' . $jsonFile . '. It can be viewed visually at <href=file://' . $htmlFile . '>' . $setName . '/solution.html</>');

        return Command::SUCCESS;
    }

    private function addMessage(string $message, ?int $groups = null, ?int $biggestGroup = null): void
    {
        if ($this->currentMessage === $message) {
            return;
        }

        if ($this->currentMessage !== '' && $this->currentStartDate !== null) {
            $this->messages .= PHP_EOL . $this->currentStartDate->format('H:i:s') . '-' . (new DateTime())->format('H:i:s') . ' <fg=#00ff00>✔ ' . $this->currentMessage . ($groups !== null && $biggestGroup !== null ? ' (' . $groups . ' // ' . $biggestGroup . ')' : '') . '</>';
        }
        $this->currentMessage = $message;
        $this->currentStartDate = new DateTime();

        $this->messageSection->overwrite($this->messages . ($message !== '' ? PHP_EOL . $this->currentStartDate->format('H:i:s') . '-         <fg=#ff8800>⌛ ' . $message . '</>' : ''));
    }

    private function initializeProgressBars(OutputInterface $output, int $piecesCount): void
    {
        ProgressBar::setFormatDefinition('custom', '%message:14s%: %current%/%max% [%bar%]');

        $this->groupsProgressBar = new ProgressBar($output->section(), $piecesCount);
        $this->groupsProgressBar->setFormat('custom');
        $this->groupsProgressBar->setMessage('Groups');
        $this->groupsProgressBar->start();
        $this->groupsProgressBar->setProgress($piecesCount);
        $this->groupsProgressBar->display();

        $this->biggestGroupProgressBar = new ProgressBar($output->section(), $piecesCount);
        $this->biggestGroupProgressBar->setFormat('custom');
        $this->biggestGroupProgressBar->setMessage('Biggest group');
        $this->biggestGroupProgressBar->start();
    }

    /**
     * @return Piece[]
     * @throws JsonException
     */
    protected function getPieces(string $setName, InputInterface $input): array
    {
        $pieces = $this->pieceLoader->getPieces($setName);

        $pieceIdsText = $input->getArgument('pieces');
        if ($pieceIdsText !== null) {
            $pieceIds = array_map('intval', explode(',', $pieceIdsText));
            $pieces = array_filter($pieces, static fn(Piece $piece): bool => in_array($piece->getIndex(), $pieceIds, true));
        }

        return $pieces;
    }
}
