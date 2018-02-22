<?php declare(strict_types=1);

namespace App\UI;

trait Skippable
{
    /**
     * @param \App\UI\UserInterface $ui
     *
     * @return bool
     */
    protected function skip(UserInterface $ui): bool
    {
        if ($ui->isDryRun()) {
            $ui->writeln('<info>[DRY-RUN]</info> Not doing anything...'.PHP_EOL);

            return true;
        }

        return false;
    }
}
