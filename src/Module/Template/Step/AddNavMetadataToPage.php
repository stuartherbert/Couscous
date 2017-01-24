<?php

namespace Couscous\Module\Template\Step;

use Couscous\Model\Project;
use Couscous\Module\Template\Model\HtmlFile;
use Couscous\Step;

/**
 * Add navigation metadata to the page
 *
 * @author Stuart Herbert <stuart@stuartherbert.com>
 */
class AddNavMetadataToPage implements Step
{
    public function __invoke(Project $project)
    {
        /** @var HtmlFile[] $htmlFiles */
        $htmlFiles = $project->findFilesByType('Couscous\Module\Template\Model\HtmlFile');

        // step 1 - build some handy indexes about our pages
        $pageList = $this->buildPageList('', $project->metadata['navigation']);
        $pageFlowList = $this->buildPageFlowList($pageList);
        $siteNavList = $this->buildSiteNavList($project->metadata['navigation']);
        $siteSubNavList = $this->buildSiteSubNavList($project->metadata['navigation']);
        $siteSectionsList = $this->buildSiteSectionsList($pageList);

        foreach ($htmlFiles as $file) {
            // step 2 - extract the pieces of metadata for this page
            $pageName = $this->findInPageList($pageList, 'relativeUrl', $file->relativeFilename);
            if ($pageName === null) {
                echo "*** warning: page {$file->relativeFilename} has no navigation entry" . PHP_EOL;
                continue;
            }
            $pageFlowMetadata = $this->findPrevNextFor($pageList, $pageFlowList, $pageName);

            // step 3 - combine it all
            $metadata = [
                'page' => $pageFlowMetadata,
            ];
            $metadata['page']['id'] = $pageName;

            $pageNameParts = explode('.', $pageName);
            $topNavId = $pageNameParts[0];
            $metadata['page']['topNavId'] = $topNavId;

            // we want the first two sections
            $subNavId = $pageName;
            if (!isset($siteSubNavList[$topNavId][$subNavId])) {
                if (isset($pageNameParts[1])) {
                    $subNavId = $pageNameParts[0] . '.' . $pageNameParts[1];
                }
                else {
                    $subNavId = $pageNameParts[0];
                }
            }
            $metadata['subNavList'] = $siteSubNavList[$topNavId];
            $metadata['page']['subNavId'] = $subNavId;

            $currentSectionName = $pageName;
            if (!isset($siteSectionsList[$currentSectionName])) {
                $currentSectionName = substr($pageName, 0, strrpos($pageName, '.'));
            }
            $metadata['currentSectionList'] = $siteSectionsList[$currentSectionName];

            // step 4 - add the metadata to this page
            $file->getMetadata()->setMany($metadata);
        }
    }

    private function getPageFlowFor($prefix, $name, $metadata)
    {
        // robustness
        if ($name === null || $metadata === null) {
            return [];
        }

        $retval = [
            'pageflow_' . $prefix . '_name' => $name,
        ];

        foreach ($metadata as $key => $value) {
            // special cases
            switch ($key) {
                case 'relativeUrl2':
                    $value = basename($value);
                    $retval['pageflow_' . $prefix . '_' . $key] = $value;
                    break;

                default:
                    $retval['pageflow_' . $prefix . '_' . $key] = $value;
            }
        }

        return $retval;
    }

    /**
     * convert our list of pages to a flat data structure, to make it
     * easier to search
     *
     * @param  array $prefix
     *         the prefix to append to array keys
     * @param  array $pages
     *         a list of page data to flatten
     * @return array
     *         a flat list of pages
     */
    private function buildPageList($prefix, $pages)
    {
        if (!empty($prefix)) {
            $prefix = $prefix . '.';
        }

        $retval = [];
        foreach($pages as $pageName => $pageData) {
            $pageData['id'] = $prefix.$pageName;
            if (isset($pageData['contents'])) {
                // is the first page a duplicate?
                reset($pageData['contents']);
                $firstPage = current($pageData['contents']);
                if ($firstPage['relativeUrl'] !== $pageData['relativeUrl']) {
                    $retval[$prefix.$pageName] = $pageData;
                }
                $retval = $retval + $this->buildPageList($prefix . $pageName, $pageData['contents']);
            }
            else {
                $retval[$prefix.$pageName] = $pageData;
            }
        }

        // all done
        return $retval;
    }

    private function buildPageFlowList($pageList)
    {
        $retval = [];
        $prev = null;

        foreach ($pageList as $pageName => $pageData) {
            if (isset($prev)) {
                $retval[$pageName]['prev'] = $prev;
                $retval[$prev]['next'] = $pageName;
            }

            $prev = $pageName;
        }

        // all done
        return $retval;
    }

    private function findInPageList($pageList, $searchKey, $searchValue)
    {
        foreach ($pageList as $pageName => $pageData) {
            if (isset($pageData[$searchKey]) && $pageData[$searchKey] == $searchValue) {
                return $pageName;
            }
        }

        return null;
    }

    private function findPrevNextFor($pageList, $pageFlowList, $pageName)
    {
        if (!isset($pageFlowList[$pageName])) {
            return [];
        }

        $prevItemName = null;
        $prevItem = null;
        $currentItemName = $pageName;
        $currentItem = $pageList[$pageName];
        $nextItemName = null;
        $nextItem = null;

        if (isset($pageFlowList[$pageName]['prev'])) {
            $prevItemName = $pageFlowList[$pageName]['prev'];
            $prevItem = $pageList[$prevItemName];
        }
        if (isset($pageFlowList[$pageName]['next'])) {
            $nextItemName = $pageFlowList[$pageName]['next'];
            $nextItem = $pageList[$nextItemName];
        }

        return array_merge(
            $this->getPageFlowFor('prev', $prevItemName, $prevItem),
            $this->getPageFlowFor('current', $currentItemName, $currentItem),
            $this->getPageFlowFor('next', $nextItemName, $nextItem)
        );
    }

    /**
     * convert our list of pages to a flat data structure, to make it
     * easier to search
     *
     * @param  array $pages
     *         a list of page data to flatten
     * @return array
     *         a flat list of pages
     */
    private function buildSiteNavList($pages)
    {
        $retval = [];
        foreach($pages as $pageName => $pageData) {
            $retval[$pageName] = $pageData;
        }

        // all done
        return ['sitenav' => $retval ];
    }

    private function buildSiteSubNavList($pages)
    {
        $retval = [];
        foreach ($pages as $pageName => $pageData) {
            if (!isset($pageData['contents'])) {
                continue;
            }

            foreach ($pageData['contents'] as $subNavName => $subNavData) {
                $retval[$pageName][$pageName . '.' . $subNavName] = $subNavData;
            }
        }

        return $retval;
    }

    private function buildSiteSectionsList($pages)
    {
        $retval = [];
        foreach ($pages as $pageName => $pageData) {
            // what is the name of this section?
            $sectionName = substr($pageName, 0, strrpos($pageName, '.'));

            if (!isset($retval[$sectionName])) {
                $retval[$sectionName] = ['contents' => []];
            }
            $retval[$sectionName]['contents'][$pageName] = $pageData;
            echo "Added {$pageName} to {$sectionName}" . PHP_EOL;
        }

        // add in the top page for each section
        foreach ($retval as $sectionName => $sectionData) {
            $leadPageName = $sectionName;
            if (!isset($pages[$leadPageName])) {
                $leadPageName .= '.home';
            }
            $leadPage = $pages[$leadPageName];
            unset($leadPage['contents']);
            $retval[$sectionName] = array_merge(
                $retval[$sectionName],
                $leadPage
            );

            // make sure the top page is the first page in
            // each section
            if (!isset($retval[$sectionName]['contents'][$leadPageName])) {
                $retval[$sectionName]['contents'] = array_merge(
                    [ $leadPageName => $pages[$leadPageName] ],
                    $retval[$sectionName]['contents']
                );
            }
        }

        return $retval;
    }
}
