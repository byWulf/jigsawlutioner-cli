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
                transparentImages: [$transparentImage, $resizedImage],
            ));

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
