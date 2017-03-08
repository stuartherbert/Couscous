<?php

namespace Couscous\Module\Template\Step;

use Couscous\Model\Project;
use Couscous\Module\Template\Model\HtmlFile;
use Couscous\Step;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Twig_Environment;
use Twig_Loader_Array;

/**
 * Renders file layouts using Twig.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class ProcessTwigLayouts implements Step
{
    const DEFAULT_LAYOUT_NAME = 'default.twig';

    public function __invoke(Project $project)
    {
        if (!$project->metadata['template.directory']) {
            return;
        }

        /** @var HtmlFile[] $htmlFiles */
        $htmlFiles = $project->findFilesByType('Couscous\Module\Template\Model\HtmlFile');

        foreach ($htmlFiles as $file) {
            $fileMetadata = $file->getMetadata();
            $layout = isset($fileMetadata['layout'])
                ? $fileMetadata['layout'].'.twig'
                : self::DEFAULT_LAYOUT_NAME;

            $context = array_merge(
                $project->metadata->toArray(),
                $fileMetadata->toArray()
            );

            $twig = $this->createTwig(
                $project->metadata['template.directory'],
                $project->includedDirectories(),
                $this->prepareContent($layout, $file->content)
            );

            try {
                $file->content = $twig->render('__content__.html', $context);
            } catch (\Exception $e) {
                throw new \RuntimeException(sprintf(
                    'There was an error while rendering the file "%s" with the layout "%s": %s',
                    $file->relativeFilename,
                    $layout,
                    $e->getMessage()
                ), 0, $e);
            }
        }
    }

    private function createTwig($templateDirectory, $sourceDirectories, $content)
    {
        $loader = $this->createLoader($templateDirectory, $sourceDirectories, $content);

        return new Twig_Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
        ]);
    }

    /**
     * We have to use a Twig_Loader_Array because of #12.
     *
     * @link https://github.com/CouscousPHP/Couscous/issues/12
     *
     * @param string $templateDirectory
     *
     * @return Twig_Loader_Array
     */
    private function createLoader($templateDirectory, $includedDirectories, $content)
    {
        $finder = new Finder();
        $finder->files()
            ->in($templateDirectory)
            ->name('*.twig');

        $layouts = [];
        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $name = $file->getFilename();
            $layouts[$name] = $file->getContents();
        }

        // add in any Twig templates from our source
        foreach ($includedDirectories as $includedDirectory) {
            $finder = new Finder();
            $finder->files()
                ->in($includedDirectory)
                ->ignoreDotFiles(false)
                ->name('*.twig');

            foreach ($finder as $file) {
                $name = $file->getRelativePathname();
                $layouts[$name] = trim($file->getContents());
            }
        }

        // add in our dynamic content
        $layouts['__content__.html'] = $content;

        return new Twig_Loader_Array($layouts);
    }

    private function prepareContent($layout, $content)
    {
        // we have to unescape double-quotes used inside Twig blocks
        //
        // yes, this is nasty. it's designed to reverse the double-quotes
        // only inside Twig sections
        //
        // if you've got a better way, I'm all ears :)
        $lastContent = null;

        while ($lastContent !== $content) {
            $lastContent = $content;
            $content = preg_replace(
                "/{%(.*)&quot;(.*)%}/",
                '{%\1"\2%}',
                $content
            );
        }

        $lastContent = null;

        while ($lastContent !== $content) {
            $lastContent = $content;
            $content = preg_replace(
                "/{{(.*)&quot;(.*)}}/",
                '{{\1"\2}}',
                $content
            );
        }

        $content = '{% extends "' . $layout . '" %}{% block content %}' . $content . '{% endblock %}';

        return $content;
    }
}
