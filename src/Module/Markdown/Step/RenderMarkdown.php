<?php

namespace Couscous\Module\Markdown\Step;

use Couscous\Model\Project;
use Couscous\Module\Markdown\Model\MarkdownFile;
use Couscous\Module\Template\Model\HtmlFile;
use Couscous\Step;
use Mni\FrontYAML\Parser;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
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
        /** @var MarkdownFile[] $markdownFiles */
        $markdownFiles = $project->findFilesByType('Couscous\Module\Markdown\Model\MarkdownFile');

        foreach ($markdownFiles as $markdownFile) {
            $htmlFile = $this->renderFile($project, $markdownFile);

            $project->replaceFile($markdownFile, $htmlFile);
        }
    }

    private function renderFile(Project $project, MarkdownFile $file)
    {
        $twig = $this->createTwig(
            $project->metadata['template.directory'],
            $project->includedDirectories()
        );
        $context = array_merge(
            $project->metadata->toArray(),
            $file->getMetadata()->toArray()
        );

        $mdFilename = $this->replaceExtension($file->relativeFilename);
        $content = $twig->render($mdFilename, $context);
        if ($mdFilename == 'classes-objects/get_class_properties.md') {
            echo PHP_EOL . $content . PHP_EOL . PHP_EOL;
        }
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
        $loadDirs = array_merge([$templateDirectory], $sourceDirectories);
        $loader = new Twig_Loader_Filesystem($loadDirs);

        return new Twig_Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
            'autoescape' => false,
        ]);
    }
}
