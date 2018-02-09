<?php declare(strict_types=1);

namespace App\Source;

use App\UI\UserInterface;
use App\Domain\Collection;

interface Source
{
    /**
     * @param \App\UI\UserInterface $ui
     * @param array $options
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(UserInterface $ui, array $options);

    /**
     * @return \App\Domain\Content[]|\App\Domain\Collection
     */
    public function getContents(): Collection;
}
