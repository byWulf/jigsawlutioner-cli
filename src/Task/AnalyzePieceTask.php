<?php

namespace App\Task;

use Amp\Parallel\Worker\Environment;
use Amp\Parallel\Worker\Task;
use Bywulf\Jigsawlutioner\Dto\Context\ByWulfBorderFinderContext;
use Bywulf\Jigsawlutioner\Exception\BorderParsingException;
use Bywulf\Jigsawlutioner\Exception\SideParsingException;
use Bywulf\Jigsawlutioner\Service\BorderFinder\ByWulfBorderFinder;
use Bywulf\Jigsawlutioner\Service\PieceAnalyzer;
use Bywulf\Jigsawlutioner\Service\SideFinder\ByWulfSideFinder;

class AnalyzePieceTask implements Task
{
    public function __construct(
        private readonly string $setName,
        private readonly int $pieceNumber,
        private readonly string $setDirectory,
    ) {
    }

    public function run(Environment $environment)
    {
        $borderFinder = new ByWulfBorderFinder();
        $sideFinder = new ByWulfSideFinder();
        $pieceAnalyzer = new PieceAnalyzer($borderFinder, $sideFinder);

        $meta = json_decode(file_get_contents($this->setDirectory . $this->setName . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);

        $image = imagecreatefromjpeg($this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '.jpg');
        $transparentImage = imagecreatefromjpeg($this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . ($meta['separateColorImages'] ?? false ? '_color' : '') . '.jpg');

        $resizedImage = imagecreatetruecolor((int) round(imagesx($transparentImage) / 10), (int) round(imagesy($transparentImage) / 10));
        imagecopyresampled($resizedImage, $transparentImage, 0, 0, 0,0, (int) round(imagesx($transparentImage) / 10), (int) round(imagesy($transparentImage) / 10), imagesx($transparentImage), imagesy($transparentImage));

        try {
            $piece = $pieceAnalyzer->getPieceFromImage($this->pieceNumber, $image, new ByWulfBorderFinderContext(
                threshold: $meta['threshold'],
                transparentImages: [$transparentImage, $resizedImage],
            ));

            $piece->reduceData();

            file_put_contents($this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '_piece.ser', serialize($piece));
            file_put_contents($this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '_piece.json', json_encode($piece, JSON_THROW_ON_ERROR));
        } catch (BorderParsingException $exception) {
            echo 'Piece ' . $this->pieceNumber . ' failed at BorderFinding: ' . $exception->getMessage() . PHP_EOL;
        } catch (SideParsingException $exception) {
            echo 'Piece ' . $this->pieceNumber . ' failed at SideFinding: ' . $exception->getMessage() . PHP_EOL;
        } finally {
            imagepng($image, $this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '_mask.png');
            imagepng($transparentImage, $this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '_transparent.png');
            imagepng($resizedImage, $this->setDirectory . $this->setName . '/piece' . $this->pieceNumber . '_transparent_small.png');
        }
    }
}
