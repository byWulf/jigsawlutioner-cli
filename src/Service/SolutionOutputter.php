<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Position;
use App\Dto\PositionedGroup;
use Bywulf\Jigsawlutioner\Dto\Group;
use Bywulf\Jigsawlutioner\Dto\Placement;
use Bywulf\Jigsawlutioner\Dto\Solution;
use Bywulf\Jigsawlutioner\Service\PointService;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;

class SolutionOutputter
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Filesystem $filesystem,
        private readonly PointService $pointService,
    ) {

    }

    public function outputAsJson(Solution $solution, string $placementJsonPath): void
    {
        $data = array_map(
            fn (Group $group): array => array_map(
                fn (Placement $placement): array => [
                    'pieceIndex' => $placement->getPiece()->getIndex(),
                    'x' => $placement->getX(),
                    'y' => $placement->getY(),
                    'topSideIndex' => $placement->getTopSideIndex(),
                ],
                $group->getPlacements()
            ),
            $solution->getGroups(),
        );

        $this->filesystem->dumpFile($placementJsonPath, json_encode($data));
    }

    public function outputAsHtml(string $setName, Solution $solution, string $placementHtmlPath, string $transparentImagePathPattern): void
    {
        $groups = [];

        foreach ($solution->getGroups() as $group) {
            $lefts = $this->getLefts($group);
            $tops = $this->getTops($group);

            $positionedGroup = new PositionedGroup();
            $positionedGroup->setWidth(array_sum($this->getWidths($group)));
            $positionedGroup->setHeight(array_sum($this->getHeights($group)));

            foreach ($group->getPlacements() as $placement) {
                $piece = $placement->getPiece();

                $topSide = $piece->getSide($placement->getTopSideIndex());
                $bottomSide = $piece->getSide($placement->getTopSideIndex() + 2);

                $position = new Position(
                    piece: $piece,
                    placement: $placement,
                    image: Path::makeRelative(
                        sprintf($transparentImagePathPattern, $piece->getIndex()),
                        dirname($placementHtmlPath)
                    ),
                    left: $lefts[$placement->getX()],
                    top: $tops[$placement->getY()],
                    center: $this->pointService->getAveragePoint([
                        $piece->getSide(0)->getStartPoint(),
                        $piece->getSide(1)->getStartPoint(),
                        $piece->getSide(2)->getStartPoint(),
                        $piece->getSide(3)->getStartPoint(),
                    ]),
                    rotation: -$this->pointService->getAverageRotation($topSide->getEndPoint(), $bottomSide->getStartPoint(), $bottomSide->getEndPoint(), $topSide->getStartPoint()),
                );
                $positionedGroup->addPosition($position);
            }

            $groups[] = $positionedGroup;
        }

        $this->filesystem->dumpFile($placementHtmlPath, $this->twig->render('solution.html.twig', [
            'setName' => $setName,
            'groups' => $groups,
        ]));
    }

    private function getWidths(Group $group): array
    {
        $widths = [];
        foreach ($group->getPlacements() as $placement) {
            $widths[$placement->getX()][] = $placement->getWidth();
        }
        foreach ($widths as $x => $widthArray) {
            $widths[$x] = array_sum($widthArray) / count($widthArray);
        }

        return $widths;
    }

    private function getLefts(Group $group): array
    {
        $widths = $this->getWidths($group);
        $lefts = [];

        $currentLeft = 0;
        $lastWidth = 0;
        for ($x = min(array_keys($widths)); $x <= max(array_keys($widths)); $x++) {
            $currentLeft += ($widths[$x] ?? $lastWidth) / 2;
            $lefts[$x] = $currentLeft;
            $currentLeft += ($widths[$x] ?? $lastWidth) / 2;

            if (isset($widths[$x])) {
                $lastWidth = $widths[$x];
            }
        }

        return $lefts;
    }

    private function getHeights(Group $group): array
    {
        $heights = [];
        foreach ($group->getPlacements() as $placement) {
            $heights[$placement->getY()][] = $placement->getHeight();
        }
        foreach ($heights as $y => $heightsArray) {
            $heights[$y] = array_sum($heightsArray) / count($heightsArray);
        }

        return $heights;
    }

    private function getTops(Group $group): array
    {
        $heights = $this->getHeights($group);
        $tops = [];

        $currentTop = 0;
        $lastHeight = 0;
        for ($y = min(array_keys($heights)); $y <= max(array_keys($heights)); $y++) {
            $currentTop += ($heights[$y] ?? $lastHeight) / 2;
            $tops[$y] = $currentTop;
            $currentTop += ($heights[$y] ?? $lastHeight) / 2;

            if (isset($heights[$y])) {
                $lastHeight = $heights[$y];
            }
        }

        return $tops;
    }
}
