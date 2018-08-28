<?php

namespace Couscous\Module\Markdown\Step;

use Couscous\Model\Project;
use Couscous\Module\Markdown\Model\MarkdownFile;
use Couscous\Module\Template\Model\HtmlFile;
use Couscous\Step;
use Mni\FrontYAML\Parser;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Twig_Loader_Array;
use Twig_Environment;
use Twig_Loader_Filesystem;

/**
 * Turns Markdown to HTML.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class RenderMarkdown implements Step
{
    /**
     * @var Parser
     */
    private $markdownParser;

    public function __construct(Parser $markdownParser)
    {
        $this->markdownParser = $markdownParser;
    }

    public function __invoke(Project $project)
    {
        $twig = $this->createTwig(
            $project->metadata['template.directory'],
            $project->includedDirectories()
        );

        /** @var MarkdownFile[] $markdownFiles */
        $markdownFiles = $project->findFilesByType('Couscous\Module\Markdown\Model\MarkdownFile');

        foreach ($markdownFiles as $markdownFile) {
            $htmlFile = $this->renderFile($project, $markdownFile, $twig);

            $project->replaceFile($markdownFile, $htmlFile);
        }
    }

    private function renderFile(Project $project, MarkdownFile $file, $twig)
    {
        $context = array_merge(
            $project->metadata->toArray(),
            $file->getMetadata()->toArray()
        );

        $mdFilename = $this->replaceExtension($file->relativeFilename);
        $content = $twig->render($mdFilename, $context);
        $document = $this->markdownParser->parse($content);

        return new HtmlFile($file->relativeFilename, $document->getContent(), $file);
    }

    private function replaceExtension($filename)
    {
        $filename = substr($filename, 0, strrpos($filename, '.'));

        return $filename.'.md';
    }

    private function createTwig($templateDirectory, $sourceDirectories)
    {
        // we have to do it this way, because otherwise Twig won't
        // render new content when files on disk change
        $loader = $this->createTwigLoader($templateDirectory, $sourceDirectories);

        return new Twig_Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
            'autoescape' => false,
        ]);
    }

    private function createTwigLoader($templateDirectory, $includedDirectories)
    {
        $finder = new Finder();
        $finder->files()
            ->in($templateDirectory)
            ->name('*.twig')
            ->followLinks();

        $layouts = [];
        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $name = $file->getFilename();
            $layouts[$name] = $file->getContents();
        }

        // add in any files from our source
        foreach ($includedDirectories as $includedDirectory) {
            $finder = new Finder();
            $finder->files()
                ->in($includedDirectory)
                ->ignoreDotFiles(false)
                ->name('*.md')
                ->followLinks();

            foreach ($finder as $file) {
                $name = $file->getRelativePathname();
                $layouts[$name] = trim($file->getContents());
            }
        }

        return new Twig_Loader_Array($layouts);
    }
}
