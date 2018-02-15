<?php declare(strict_types=1);

namespace Extension\PDF;

use App\Domain\Collection\Contents;
use App\Domain\Content;
use App\Domain\Download as DownloadInterface;
use App\Domain\PathPart;
use App\Domain\Platform;
use App\UI\UserInterface;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

final class PDF implements Platform
{
    private const PDF_REGEX = <<<REGEX
/((?:http|https)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(?:\/\S*)?\.pdf)/i
REGEX;

    /** @var \App\UI\UserInterface */
    private $ui;

    /**
     * @param \App\UI\UserInterface $ui
     */
    public function __construct(UserInterface $ui)
    {
        $this->ui = $ui;
    }

    /**
     * @param \App\Domain\Collection\Contents $contents
     * @param \App\Domain\PathPart $rootPathPart
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function synchronizeContents(Contents $contents, PathPart $rootPathPart): void
    {
        $this->ui->writeln('Download PDF files... '.PHP_EOL);

        foreach ($contents->slice(16) as $content) {
            foreach ($this->extractPdfLinks($content) as $download) {
                $this->download($download);
            }
        }

        $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);
    }

    /**
     * @param \App\Domain\Content $content
     *
     * @return \Extension\PDF\Downloads
     */
    private function extractPdfLinks(Content $content): Downloads
    {
        $downloads = new Downloads();

        if (preg_match_all(static::PDF_REGEX, $content->getData(), $matches)) {
            foreach ((array) $matches[0] as $pdfLink) {
                $downloads->add(new Download($pdfLink, $content->getPath()));
            }
        }

        return $downloads;
    }

    /**
     * @param \Extension\PDF\Download|\App\Domain\Download $download
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function download(DownloadInterface $download): void
    {
        $downloadPath = (string) $download->getPath();
        $downloadUrl = $download->getUrl();
        $fileName = pathinfo($downloadUrl, PATHINFO_BASENAME);
        $filePath = $downloadPath.DIRECTORY_SEPARATOR.$fileName;

        $this->ui->writeln(
            sprintf(
                '%s* Download the PDF file in <info>%s</info>... ',
                $this->ui->indent(2),
                $filePath
            )
        );


        (new Filesystem())->mkdir($downloadPath);
        (new Client())->request('GET', $downloadUrl, ['sink' => $filePath]);
    }
}
