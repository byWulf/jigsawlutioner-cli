<?php

namespace App\Command;

use Bywulf\Jigsawlutioner\SideClassifier\DepthClassifier;
use Rubix\ML\Learner;
use Rubix\ML\Regressors\RegressionTree;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('app:model:depth:create')]
class CreateDepthModelCommandCommand extends AbstractModelCreatorCommand
{
    protected function getClassifierClassName(): string
    {
        return DepthClassifier::class;
    }

    protected function createLearner(): Learner
    {
        return new RegressionTree(30, 6, 1e-4, 20, null);
    }
}
