<?php declare(strict_types=1);

namespace App\UI;

use App\Collection\Collection;

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

    /**
     * @param \App\UI\UserInterface $ui
     * @param \App\Collection\Collection $collection
     * @param string $message
     * @param string $action
     *
     * @return bool
     */
    private function shouldProcess(UserInterface $ui, Collection $collection, string $message, string $action): bool
    {
        if ($collection->isEmpty()) {
            $ui->writeln(sprintf($ui->indent().'<comment>Nothing to %s.</comment>'.PHP_EOL, $action));

            return false;
        }

        $ui->writeln($message);

        $ui->write($ui->indent());
        if ($this->skip($ui) || !$ui->confirm()) {
            $ui->writeln(($ui->isDryRun() ? '' : PHP_EOL).'<info>Done.</info>'.PHP_EOL);

            return false;
        }

        return true;
    }
}
