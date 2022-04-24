<?php

declare(strict_types=1);

namespace App\Dto;

class PositionedGroup
{
    private array $positions = [];

    private float $width = 0;

    private float $height = 0;

    public function getPositions(): array
    {
        return $this->positions;
    }

    public function addPosition(Position $position): self
    {
        $this->positions[] = $position;

        return $this;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function setWidth(float $width): PositionedGroup
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    public function setHeight(float $height): PositionedGroup
    {
        $this->height = $height;
        return $this;
    }
}
