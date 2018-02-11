<?php declare(strict_types=1);

namespace App\Platform\YouTube;

trait DryRunner
{
    /** @var \App\UI\UserInterface */
    private $ui;

    /** @var bool */
    private $dryRun;

    /**
     * @return bool
     */
    public function skip(): bool
    {
        if ($this->dryRun) {
            $this->ui->writeln('<info>[DRY-RUN]</info> Not doing anything...'.PHP_EOL);

            return true;
        }

        return false;
    }
}
