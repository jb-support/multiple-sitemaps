<?php

declare(strict_types=1);

namespace JBSupport\MultipleSitemapsBundle\Controller;

use Contao\ArticleModel;
use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\PageModel;
use Contao\NewsModel;
use Contao\CalendarModel;
use Contao\CalendarEventsModel;
use Contao\NewsArchiveModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use JBSupport\MultipleSitemapsBundle\Routing\RegisterSitemapRoutes;
use JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig;

/**
 * @Route(defaults={"_scope" = "frontend"})
 *
 * @internal
 */
class MultipleSitemapController extends AbstractController
{
    private PageRegistry $pageRegistry;

    public function __construct(PageRegistry $pageRegistry, Connection $connection)
    {
        $this->pageRegistry = $pageRegistry;
        $this->connection = $connection;
    }

    public function index(Request $request): Response
    {
        if (!$request->attributes->has(RegisterSitemapRoutes::ATTRIBUTE_NAME)) {
            throw new RouteNotFoundException(sprintf('The "%s" attribute is missing', RegisterSitemapRoutes::ATTRIBUTE_NAME));
        }

        $sitemapId = $request->attributes->get(RegisterSitemapRoutes::ATTRIBUTE_NAME);

        $jbSitemap = $this->connection->fetchAssociative(
            'SELECT * FROM tl_jb_sitemap WHERE id=:id AND published=:published',
            ['published' => 1, 'id' => $sitemapId]
        );

        if (!$jbSitemap) {
            throw new ResourceNotFoundException("SITEMAP NOT FOUND: ". $sitemapId);
        }

        $this->initializeContaoFramework();

        if ($jbSitemap["type"] == MultipleSitemapsConfig::TYPE_INDEX) {
            return $this->createSitemapIndex($request, $jbSitemap);
        }

        return $this->generateSitemap($request, $jbSitemap);
    }

    private function generateSitemap($request, $jbSitemap): Response
    {
        $pageModel = $this->getContaoAdapter(PageModel::class);
        $rootPages = $pageModel->findPublishedRootPages();

        if (null === $rootPages) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $urls = [];
        $rootPageIds = [];
        $tags = ['jb.sitemap'];
        $tags[] = 'jb.sitemap.'.$jbSitemap["id"];

        foreach ($rootPages as $rootPage) {
            $urls[] = $this->getPageAndArticleUrls((int) $rootPage->id, [(int)$rootPage->id], $jbSitemap);
            $rootPageIds[] = $rootPage->id;
        }
        $urls = array_unique(array_merge(...$urls));

        $sitemap = new \DOMDocument('1.0', 'UTF-8');
        $sitemap->formatOutput = true;
        $urlSet = $sitemap->createElementNS('https://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');

        foreach ($urls as $url) {
            $loc = $sitemap->createElement('loc', $url);
            // Todo lastmod ergänzen
            $urlEl = $sitemap->createElement('url');
            $urlEl->appendChild($loc);
            if (!empty($jbSitemap["priority"]) && $jbSitemap["priority"]>0) {
                $prio = $sitemap->createElement('priority', $jbSitemap["priority"]);
                $urlEl->appendChild($prio);
            }
            $urlSet->appendChild($urlEl);
        }

        $sitemap->appendChild($urlSet);

        $this->container
            ->get('event_dispatcher')
            ->dispatch(new SitemapEvent($sitemap, $request, $rootPageIds), ContaoCoreEvents::SITEMAP)
        ;

        // Cache the response for a given time in the shared cache and tag it for invalidation purposes
        $response = new Response((string) $sitemap->saveXML(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setSharedMaxAge((int)$jbSitemap["maxAge"]); // will be unset by the MakeResponsePrivateListener if a user is logged in

        $this->tagResponse($tags);

        return $response;
    }

    private function createSitemapIndex($request, $jbSitemap): Response
    {
        $selectedSitemaps = unserialize($jbSitemap["sitemaps"]);
        $tags = ['jb.sitemap'];

        $sitemapIndex = new \DOMDocument('1.0', 'UTF-8');
        $sitemapIndex->formatOutput = true;
        $urlSet = $sitemapIndex->createElementNS('https://www.sitemaps.org/schemas/sitemap/0.9', 'sitemapindex');

        foreach ($selectedSitemaps as $sitemapId) {
            $childSitemap = $this->connection->fetchAssociative(
                'SELECT * FROM tl_jb_sitemap WHERE id=:id AND published=:published',
                ['published' => 1, 'id' => $sitemapId]
            );
            if ($childSitemap) {
                $domain = ($request->isSecure() ? "https://" : "http://") . $request->server->get('HTTP_HOST');
                if (!empty($jbSitemap["domain"])) {
                    $domain = $jbSitemap["domain"];
                }
                $url = $domain . '/'. $childSitemap["filename"];
                $loc = $sitemapIndex->createElement('loc', $url);
                $urlEl = $sitemapIndex->createElement('sitemap');
                $urlEl->appendChild($loc);
                $urlSet->appendChild($urlEl);
                // Todo lastmod ergänzen
            }
        }

        $sitemapIndex->appendChild($urlSet);

        $response = new Response((string) $sitemapIndex->saveXML(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setSharedMaxAge((int)$jbSitemap["maxAge"]); // will be unset by the MakeResponsePrivateListener if a user is logged in

        $this->tagResponse($tags);

        return $response;
    }

    /*

    private function callLegacyHook(PageModel $rootPage, array $pages): array
    {
        $systemAdapter = $this->getContaoAdapter(System::class);

        // HOOK: take additional pages
        if (isset($GLOBALS['TL_HOOKS']['getSearchablePages']) && \is_array($GLOBALS['TL_HOOKS']['getSearchablePages'])) {
            trigger_deprecation('contao/core-bundle', '4.11', 'Using the "getSearchablePages" hook is deprecated. Use the "contao.sitemap" event instead.');

            foreach ($GLOBALS['TL_HOOKS']['getSearchablePages'] as $callback) {
                $pages = $systemAdapter->importStatic($callback[0])->{$callback[1]}($pages, $rootPage->id, true, $rootPage->language);
            }
        }

        return $pages;
    }
    */

    // Part from original Contao sitemap code
    private function getPageAndArticleUrls(int $parentPageId, $pageTreeIds, $jbSitemap): array
    {
        $pageModelAdapter = $this->getContaoAdapter(PageModel::class);

        // Since the publication status of a page is not inherited by its child
        // pages, we have to use findByPid() instead of findPublishedByPid() and
        // filter out unpublished pages in the foreach loop (see #2217)
        $pageModels = $pageModelAdapter->findByPid($parentPageId, ['order' => 'sorting']);

        if (null === $pageModels) {
            return [];
        }

        $articleModelAdapter = $this->getContaoAdapter(ArticleModel::class);

        $result = [];

        // Recursively walk through all subpages
        foreach ($pageModels as $pageModel) {
            $newPageTreeIds = $pageTreeIds;
            $newPageTreeIds[] = $pageModel->id;

            if ($pageModel->protected && !$this->isGranted(ContaoCorePermissions::MEMBER_IN_GROUPS, $pageModel->groups)) {
                continue;
            }

            $isPublished = $pageModel->published && (!$pageModel->start || $pageModel->start <= time()) && (!$pageModel->stop || $pageModel->stop > time());

            // Check in Sitemap (index mode)
            $isInSitemap = false;
            switch ($jbSitemap["indexMode"]) {
                case MultipleSitemapsConfig::INDEX_MODE_PRECISELY_SELECTED:
                    $isInSitemap = !empty($pageModel->jbSitemaps) && in_array($jbSitemap["id"], unserialize($pageModel->jbSitemaps));
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_ANY_SELECTED:
                    $isInSitemap = !empty($pageModel->jbSitemaps);
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_NOTHING_SELECTED:
                    $isInSitemap = empty($pageModel->jbSitemaps);
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_ALL:
                    $isInSitemap = true;
                    break;
            }

            // Check in Filetree
            $isInFiletree = false;
            if (!empty($jbSitemap["rootPages"])) {
                $rootPages = unserialize($jbSitemap["rootPages"]);
                if (count(array_intersect($rootPages, $newPageTreeIds)) > 0) {
                    $isInFiletree = true;
                }
            } else {
                $isInFiletree = true;
            }

            if ($isInSitemap
                && $isInFiletree
                && $isPublished
                && !$pageModel->requireItem
                && 'noindex,nofollow' !== $pageModel->robots
                && $this->pageRegistry->supportsContentComposition($pageModel)
                && $this->pageRegistry->isRoutable($pageModel)
                && 'html' === $this->pageRegistry->getRoute($pageModel)->getDefault('_format')
            ) {
                $urls = [$pageModel->getAbsoluteUrl()];

                // Get articles with teaser
                if (null !== ($articleModels = $articleModelAdapter->findPublishedWithTeaserByPid($pageModel->id, ['ignoreFePreview' => true]))) {
                    foreach ($articleModels as $articleModel) {
                        $urls[] = $pageModel->getAbsoluteUrl('/articles/'.($articleModel->alias ?: $articleModel->id));
                    }
                }

                $result[] = $urls;
            }

            $result[] = $this->getPageAndArticleUrls((int) $pageModel->id, $newPageTreeIds, $jbSitemap);
        }

        return array_merge(...$result);
    }

    protected function getNewsUrls($jbSitemap): array {
        $newsArchiveIds = isset($jbSitemap["newsList"]) ? unserialize($jbSitemap["newsList"]) : [];
        $newsArchiveAdapter = $this->getContaoAdapter(NewsArchiveModel::class);

        $newsAdapter = $this->getContaoAdapter(NewsModel::class);

        $pageAdapter = $this->getContaoAdapter(PageModel::class);

        $aliases = [];

        foreach($newsArchiveIds as $archiveId) {
            $newsArchive = $newsArchiveAdapter->findBy(['id = ?'],[$archiveId]);
            $newsInArchive = $newsAdapter->findBy(['pid = ?', 'published = ?'], [$archiveId, 1]) ?? [];
            $page = $pageAdapter->findById($newsArchive->jumpTo);
            $pageAlias = $page->alias;

            foreach($newsInArchive as $news) {
                $aliases[] = !$news ?: $page->alias."/".$news->alias;
            }
        }

        return $aliases;
    }

    protected function getEventsUrls($jbSitemap): array {
        $calendarIds = isset($jbSitemap["eventsList"]) ? unserialize($jbSitemap["eventsList"]) : [];
        $calendarAdapter = $this->getContaoAdapter(CalendarModel::class);

        $eventAdapter = $this->getContaoAdapter(CalendarEventsModel::class);

        $pageAdapter = $this->getContaoAdapter(PageModel::class);

        $aliases = [];

        foreach($calendarIds as $archiveId) {
            $calendar = $calendarAdapter->findBy(['id = ?'], [$archiveId]);
            $newsInArchive = $eventAdapter->findBy(['pid = ?', 'published = ?'], [$archiveId, 1]) ?? [];

            $page = $pageAdapter->findById($calendar->jumpTo);
            $pageAlias = $page->alias;

            foreach($newsInArchive as $news) {
                $aliases[] = !$news ?: $page->alias."/".$news->alias;
            }
        }

        return $aliases;
    }
}
