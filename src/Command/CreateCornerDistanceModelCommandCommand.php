<?php

namespace App\Command;

use Bywulf\Jigsawlutioner\SideClassifier\CornerDistanceClassifier;
use Rubix\ML\Learner;
use Rubix\ML\Regressors\RegressionTree;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('app:model:corner-distance:create')]
class CreateCornerDistanceModelCommandCommand extends AbstractModelCreatorCommand
{
    protected function getClassifierClassName(): string
    {
        return CornerDistanceClassifier::class;
    }

    protected function createLearner(): Learner
    {
        return new RegressionTree(30, 6, 1e-4, 20, null);
    }
}
