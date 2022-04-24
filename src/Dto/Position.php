<?php

declare(strict_types=1);

namespace App\Dto;

use Bywulf\Jigsawlutioner\Dto\Piece;
use Bywulf\Jigsawlutioner\Dto\Placement;
use Bywulf\Jigsawlutioner\Dto\Point;

class Position
{
    public function __construct(
        public readonly Piece $piece,
        public readonly Placement $placement,
        public readonly string $image,
        public readonly float $left,
        public readonly float $top,
        public readonly Point $center,
        public readonly float $rotation,
    ) {
    }
}
