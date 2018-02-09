<?php declare(strict_types=1);

namespace App\Source;

use App\UI\UserInterface;

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
     * @return \App\Domain\Content[]
     */
    public function getContents(): array;
}
