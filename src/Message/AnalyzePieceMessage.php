<?php

declare(strict_types=1);

namespace App\Message;

class AnalyzePieceMessage
{
    public function __construct(
        public readonly string $setName,
        public readonly int $pieceNumber
    ) {
    }
}
