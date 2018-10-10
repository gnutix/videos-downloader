<?php declare(strict_types=1);

namespace App\Domain;

use App\UI\UserInterface;
use Symfony\Component\Yaml\Yaml;

abstract class ContentsProcessor
{
    /** @var \App\UI\UserInterface */
    protected $ui;

    /** @var array */
    protected $config;

    /**
     * {@inheritdoc}
     * @param array $config
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(UserInterface $ui, array $config = [])
    {
        $this->ui = $ui;

        if (!empty($configFilePath = $this->getConfigFilePath())) {
            $config = array_replace_recursive((array) Yaml::parseFile($configFilePath), $config);
        }

        $this->config = $config;
    }

    /**
     * @return string
     */
    protected function getConfigFilePath(): string
    {
        return '';
    }

    /**
     * @param Contents $contents
     */
    abstract public function processContents(Contents $contents): void;
}
