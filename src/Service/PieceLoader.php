<?php

declare(strict_types=1);

namespace App\Service;

use Bywulf\Jigsawlutioner\Dto\Piece;
use InvalidArgumentException;
use JsonException;

class PieceLoader
{
    public function __construct(
        private readonly string $setDirectory
    ) {
    }

    /**
     * @throws JsonException
     */
    public function getMeta(string $setName): array
    {
        return json_decode(file_get_contents($this->setDirectory . $setName . '/meta.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return Piece[]
     * @throws JsonException
     */
    public function getPieces(string $setName): array
    {
        $pieces = [];
        foreach ($this->getPieceNumbers($setName) as $i) {
            if (!is_file($this->setDirectory . $setName . '/piece' . $i . '_piece.ser')) {
                continue;
            }

            $piece = Piece::fromSerialized(file_get_contents($this->setDirectory . $setName . '/piece' . $i . '_piece.ser'));

            if (count($piece->getSides()) !== 4) {
                continue;
            }



            $pieces[$i] = $piece;
        }

        return $pieces;
    }

    /**
     * @param Piece[]  $pieces
     * @throws JsonException
     */
    public function reorderSides(string $setName, array $pieces): void
    {
        $meta = $this->getMeta($setName);

        // Reorder sides so the top side is the first side
        $targetTopSide = match ($meta['topLeftCorner']) {
            'top' => 1,
            'left' => 2,
            'bottom' => 3,
            'right' => 0,
            default => throw new InvalidArgumentException('topLeftCorner from meta.json invalid'),
        };

        foreach ($pieces as $piece) {
            $sides = $piece->getSides();

            while (
                $sides[($targetTopSide + 1) % 4]->getStartPoint()->getY() < $sides[$targetTopSide]->getStartPoint()->getY() ||
                $sides[($targetTopSide + 2) % 4]->getStartPoint()->getY() < $sides[$targetTopSide]->getStartPoint()->getY() ||
                $sides[($targetTopSide + 3) % 4]->getStartPoint()->getY() < $sides[$targetTopSide]->getStartPoint()->getY()
            ) {
                $side = array_splice($sides, 0, 1);
                $sides[] = $side[0];
                $sides = array_values($sides);
            }

            $piece->setSides(array_values($sides));
        }
    }

    public function getPieceNumbers(string $setName): array
    {
        $meta = $this->getMeta($setName);

        if (!isset($meta['numbers']) && !isset($meta['min']) && !isset($meta['max'])) {
            throw new InvalidArgumentException('"numbers" or "min"+"max" have to be set in the meta.json.');
        }

        if (isset($meta['numbers']) && (isset($meta['min']) || isset($meta['max']))) {
            throw new InvalidArgumentException('Either "numbers" or "min"+"max" have to be set in the meta.json, but not both at the same time.');
        }

        if (isset($meta['numbers'])) {
            $numbers = $meta['numbers'];
        } else {
            if (!isset($meta['min'], $meta['max'])) {
                throw new InvalidArgumentException('When using number range, "min" and "max" have to be set at the same time.');
            }

            $numbers = [];
            for ($i = $meta['min']; $i <= $meta['max']; ++$i) {
                if (isset($meta['exclude']) && is_array($meta['exclude']) && in_array($i, $meta['exclude'], true)) {
                    continue;
                }

                $numbers[] = $i;
            }
        }

        return $numbers;
    }
}
