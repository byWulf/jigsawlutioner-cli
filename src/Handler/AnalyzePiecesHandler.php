<?php

namespace App\Handler;

use App\Message\AnalyzePieceMessage;
use Bywulf\Jigsawlutioner\Dto\Context\ByWulfBorderFinderContext;
use Bywulf\Jigsawlutioner\Dto\DerivativePoint;
use Bywulf\Jigsawlutioner\Exception\BorderParsingException;
use Bywulf\Jigsawlutioner\Exception\SideClassifierException;
use Bywulf\Jigsawlutioner\Exception\SideParsingException;
use Bywulf\Jigsawlutioner\Service\BorderFinder\ByWulfBorderFinder;
use Bywulf\Jigsawlutioner\Service\PieceAnalyzer;
use Bywulf\Jigsawlutioner\Service\SideFinder\ByWulfSideFinder;
use Bywulf\Jigsawlutioner\SideClassifier\BigWidthClassifier;
use Bywulf\Jigsawlutioner\SideClassifier\SmallWidthClassifier;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

#[AsCommand(name: 'app:consumer:piece:analyze')]
class AnalyzePiecesHandler implements MessageHandlerInterface
{
    public function __construct(
        private readonly string $setDirectory
    ) {
    }

    /**
     * @throws JsonException
     */
    public function __invoke(AnalyzePieceMessage $analyzePieceMessage): void
    {
        $borderFinder = new ByWulfBorderFinder();
        $sideFinder = new ByWulfSideFinder();
        $pieceAnalyzer = new PieceAnalyzer($borderFinder, $sideFinder);

        $meta = json_decode(file_get_contents($this->setDirectory . $analyzePieceMessage->setName . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);

        $image = imagecreatefromjpeg($this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '.jpg');
        $transparentImage = imagecreatefromjpeg($this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . ($meta['separateColorImages'] ?? false ? '_color' : '') . '.jpg');

        $resizedImage = imagecreatetruecolor((int) round(imagesx($transparentImage) / 10), (int) round(imagesy($transparentImage) / 10));
        imagecopyresampled($resizedImage, $transparentImage, 0, 0, 0,0, (int) round(imagesx($transparentImage) / 10), (int) round(imagesy($transparentImage) / 10), imagesx($transparentImage), imagesy($transparentImage));

        try {
            $piece = $pieceAnalyzer->getPieceFromImage($analyzePieceMessage->pieceNumber, $image, new ByWulfBorderFinderContext(
                threshold: $meta['threshold'],
                transparentImage: $transparentImage,
                smallTransparentImage: $resizedImage,
            ));

            // Found corners
            $cornerColor = imagecolorallocate($image, 255, 128, 0);
            foreach ($piece->getSides() as $side) {
                $point = $side->getStartPoint();
                for ($x = (int) $point->getX() - 10; $x < $point->getX() + 10; ++$x) {
                    imagesetpixel($image, $x, (int) $point->getY(), $cornerColor);
                }
                for ($y = (int) $point->getY() - 10; $y < $point->getY() + 10; ++$y) {
                    imagesetpixel($image, (int) $point->getX(), $y, $cornerColor);
                }
            }

            // BorderPoints (red = anti-clockwise, green = clockwise)
            foreach ($piece->getBorderPoints() as $point) {
                $color = imagecolorallocate($image, 255, 255, 255);
                if ($point instanceof DerivativePoint) {
                    $diff = (int) min((abs($point->getDerivative()) / 90) * 255, 255);

                    $color = imagecolorallocate(
                        $image,
                        255 - ($point->getDerivative() > 0 ? $diff : 0),
                        255 - ($point->getDerivative() < 0 ? $diff : 0),
                        255 - $diff
                    );
                    if ($point->isExtreme()) {
                        $color = imagecolorallocate($image, 255, 255, 0);
                    }
                }
                imagesetpixel($image, (int) $point->getX(), (int) $point->getY(), $color);
            }

            // Normalized side points
            $sidePoint = imagecolorallocate($image, 255, 0, 255);
            foreach ($piece->getSides() as $side) {
                foreach ($side->getUnrotatedPoints() as $point) {
                    imagesetpixel($image, (int) $point->getX(), (int) $point->getY(), $sidePoint);
                }
            }

            // Smoothed and normalized side points
            $black = imagecolorallocate($image, 0, 0, 0);
            $resizeFactor = 3;
            foreach ($piece->getSides() as $sideIndex => $side) {
                foreach ($side->getPoints() as $point) {
                    imagesetpixel($image, (int) (($point->getX() / $resizeFactor) + 300 / $resizeFactor), (int) (($point->getY() / $resizeFactor) + 50 + $sideIndex * 250 / $resizeFactor), imagecolorallocate($image, 50, 80, 255));
                }

                try {
                    /** @var BigWidthClassifier $bigWidthClassifier */
                    $bigWidthClassifier = $side->getClassifier(BigWidthClassifier::class);
                    $centerPoint = $bigWidthClassifier->getCenterPoint();
                    imagesetpixel($image, (int) (($centerPoint->getX() / $resizeFactor) + 300 / $resizeFactor), (int) (($centerPoint->getY() / $resizeFactor) + 50 + $sideIndex * 250 / $resizeFactor), imagecolorallocate($image, 0, 150, 150));
                } catch (SideClassifierException) {
                }

                try {
                    /** @var SmallWidthClassifier $smallWidthClassifier */
                    $smallWidthClassifier = $side->getClassifier(SmallWidthClassifier::class);
                    $centerPoint = $smallWidthClassifier->getCenterPoint();
                    imagesetpixel($image, (int) (($centerPoint->getX() / $resizeFactor) + 300 / $resizeFactor), (int) (($centerPoint->getY() / $resizeFactor) + 50 + $sideIndex * 250 / $resizeFactor), imagecolorallocate($image, 150, 150, 0));
                } catch (SideClassifierException) {
                }

                $classifiers = $side->getClassifiers();
                ksort($classifiers);

                $yOffset = 0;
                foreach ($classifiers as $classifier) {
                    imagestring($image, 1, (int) (600 / $resizeFactor), (int) ($yOffset + $sideIndex * 250 / $resizeFactor), $classifier, $black);
                    $yOffset += 10;
                }
            }

            $piece->reduceData();

            file_put_contents($this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '_piece.ser', serialize($piece));
            file_put_contents($this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '_piece.json', json_encode($piece, JSON_THROW_ON_ERROR));
        } catch (BorderParsingException $exception) {
            echo 'Piece ' . $analyzePieceMessage->pieceNumber . ' failed at BorderFinding: ' . $exception->getMessage() . PHP_EOL;
        } catch (SideParsingException $exception) {
            echo 'Piece ' . $analyzePieceMessage->pieceNumber . ' failed at SideFinding: ' . $exception->getMessage() . PHP_EOL;
        } finally {
            imagepng($image, $this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '_mask.png');
            imagepng($transparentImage, $this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '_transparent.png');
            imagepng($resizedImage, $this->setDirectory . $analyzePieceMessage->setName . '/piece' . $analyzePieceMessage->pieceNumber . '_transparent_small.png');
        }
    }
}
