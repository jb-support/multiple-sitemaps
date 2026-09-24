<?php

declare (strict_types = 1);

namespace JBSupport\MultipleSitemapsBundle\Controller;

use Contao\ArticleModel;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\FaqCategoryModel;
use Contao\FaqModel;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use JBSupport\MultipleSitemapsBundle\MultipleSitemapsConfig;
use JBSupport\MultipleSitemapsBundle\Routing\RegisterSitemapRoutes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class MultipleSitemapController extends AbstractController
{
    private PageRegistry $pageRegistry;
    private Connection $connection;

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
            throw new ResourceNotFoundException("SITEMAP NOT FOUND: " . $sitemapId);
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

        switch ($jbSitemap["indexMode"]) {
            case MultipleSitemapsConfig::INDEX_MODE_NO_PAGES:
                $rootPages = [];
                break;
            case MultipleSitemapsConfig::INDEX_MODE_ALL:
                $unserializedRootPages = StringUtil::deserialize($jbSitemap["rootPages"], true);
                if (!empty($unserializedRootPages)) {
                    $rootPages = [];
                    foreach ($unserializedRootPages as $urp) {
                        $rootPages[] = $pageModel->findOneBy(["id = ?", "published = ?"], [$urp, 1]);
                    }
                }
                break;
        }

        if (empty($rootPages)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $urls = [];
        $rootPageIds = [];
        $tags = ['jb.sitemap'];
        $tags[] = 'jb.sitemap.' . $jbSitemap["id"];

        foreach ($rootPages as $rootPage) {
            $urls[] = $this->getPageAndArticleUrls((int) $rootPage->id, [(int) $rootPage->id], $jbSitemap);
            $rootPageIds[] = $rootPage->id;
        }

        if (!empty(StringUtil::deserialize($jbSitemap['newsList'], true))) {
            $newsUrls = $this->getNewsUrls($jbSitemap);
            array_push($urls, $newsUrls);
        }

        if (!empty(StringUtil::deserialize($jbSitemap['eventsList'], true))) {
            $eventsUrls = $this->getEventsUrls($jbSitemap);
            array_push($urls, $eventsUrls);
        }

        if (!empty(StringUtil::deserialize($jbSitemap['faqList'], true))) {
            $faqUrls = $this->getFaqUrls($jbSitemap);
            array_push($urls, $faqUrls);
        }

        $urls = array_unique(array_merge(...$urls));

        $sitemap = new \DOMDocument('1.0', 'UTF-8');
        $sitemap->formatOutput = true;
        $urlSet = $sitemap->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');

        foreach ($urls as $url) {
            $loc = $sitemap->createElement('loc', $url);
            // Todo lastmod ergänzen
            $urlEl = $sitemap->createElement('url');
            $urlEl->appendChild($loc);
            if (!empty($jbSitemap["priority"]) && $jbSitemap["priority"] > 0) {
                $prio = $sitemap->createElement('priority', (string)$jbSitemap["priority"]);
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
        $response->setSharedMaxAge((int) $jbSitemap["maxAge"]); // will be unset by the MakeResponsePrivateListener if a user is logged in

        $this->tagResponse($tags);
        return $response;
    }

    private function createSitemapIndex($request, $jbSitemap): Response
    {
        $selectedSitemaps = StringUtil::deserialize($jbSitemap["sitemaps"], true);
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
                $url = rtrim($domain, '/') . '/' . $childSitemap["filename"];
                $loc = $sitemapIndex->createElement('loc', $url);
                $urlEl = $sitemapIndex->createElement('sitemap');
                $urlEl->appendChild($loc);
                $urlSet->appendChild($urlEl);
                // Todo lastmod ergänzen
            }
        }

        $sitemapIndex->appendChild($urlSet);

        $response = new Response((string) $sitemapIndex->saveXML(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setSharedMaxAge((int) $jbSitemap["maxAge"]); // will be unset by the MakeResponsePrivateListener if a user is logged in

        $this->tagResponse($tags);

        return $response;
    }

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
            $pageSitemaps = StringUtil::deserialize($pageModel->jbSitemaps, true);

            switch ($jbSitemap["indexMode"]) {
                case MultipleSitemapsConfig::INDEX_MODE_PRECISELY_SELECTED:
                    $isInSitemap = !empty($pageSitemaps) && in_array($jbSitemap["id"], $pageSitemaps);
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_ANY_SELECTED:
                    $isInSitemap = !empty($pageSitemaps);
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_NOTHING_SELECTED:
                    $isInSitemap = empty($pageSitemaps);
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_ALL:
                    $isInSitemap = true;
                    break;
                case MultipleSitemapsConfig::INDEX_MODE_NO_PAGES:
                    $isInSitemap = false;
                    break;
            }

            // Check in Filetree
            $isInFiletree = false;
            $rootPages = StringUtil::deserialize($jbSitemap["rootPages"], true);

            if (!empty($rootPages)) {
                if (count(array_intersect($rootPages, $newPageTreeIds)) > 0) {
                    $isInFiletree = true;
                }
            } else {
                $isInFiletree = true;
            }

            $isReaderPage = !empty($pageModel->requireItem);
            if (!$isReaderPage && $this->pageRegistry->isRoutable($pageModel)) {
                $route = $this->pageRegistry->getRoute($pageModel);
                if (in_array('parameters', $route->compile()->getVariables(), true)) {
                    $isReaderPage = true;
                }
            }

            if ($isInSitemap
                && $isInFiletree
                && $isPublished
                && !$isReaderPage
                && 'noindex,nofollow' !== $pageModel->robots
                && $this->pageRegistry->supportsContentComposition($pageModel)
                && $this->pageRegistry->isRoutable($pageModel)
                && 'html' === $this->pageRegistry->getRoute($pageModel)->getDefault('_format')
            ) {
                $urls = [$pageModel->getAbsoluteUrl()];

                // Get articles with teaser
                if (null !== ($articleModels = $articleModelAdapter->findPublishedWithTeaserByPid($pageModel->id, ['ignoreFePreview' => true]))) {
                    foreach ($articleModels as $articleModel) {
                        $urls[] = $pageModel->getAbsoluteUrl('/articles/' . ($articleModel->alias ?: $articleModel->id));
                    }
                }

                $result[] = $urls;
            }

            $result[] = $this->getPageAndArticleUrls((int) $pageModel->id, $newPageTreeIds, $jbSitemap);
        }

        return array_merge(...$result);
    }

    protected function getNewsUrls($jbSitemap): array
    {
        /* Case: An archive has been added to the sitemap but the package
        has been disabled afterwards without updating the database */
        if (!class_exists(NewsArchiveModel::class)) {
            return [];
        }

        $newsArchiveIds = !empty($jbSitemap["newsList"]) ? StringUtil::deserialize($jbSitemap["newsList"], true) : [];
        $newsArchiveAdapter = $this->getContaoAdapter(NewsArchiveModel::class);

        $newsAdapter = $this->getContaoAdapter(NewsModel::class);
        $urls = $this->extractUrls($newsArchiveIds, $newsArchiveAdapter, $newsAdapter);
        return $urls;
    }

    protected function getFaqUrls($jbSitemap): array
    {
        if (!class_exists(FaqCategoryModel::class)) {
            return [];
        }

        $faqCategoryIds = !empty($jbSitemap["faqList"]) ? StringUtil::deserialize($jbSitemap["faqList"], true) : [];
        $faqCategoryAdapter = $this->getContaoAdapter(FaqCategoryModel::class);

        $faqAdapter = $this->getContaoAdapter(FaqModel::class);
        $urls = $this->extractUrls($faqCategoryIds, $faqCategoryAdapter, $faqAdapter);

        return $urls;
    }

    protected function getEventsUrls($jbSitemap): array
    {
        if (!class_exists(CalendarModel::class)) {
            return [];
        }

        $calendarIds = !empty($jbSitemap["eventsList"]) ? StringUtil::deserialize($jbSitemap["eventsList"], true) : [];
        $calendarAdapter = $this->getContaoAdapter(CalendarModel::class);

        $eventAdapter = $this->getContaoAdapter(CalendarEventsModel::class);
        $urls = $this->extractUrls($calendarIds, $calendarAdapter, $eventAdapter);

        return $urls;
    }

    //Extract the URLs for each Model in an Archive (Event/News/FAQ);
    private function extractUrls($archiveIds, $archiveAdapter, $modelAdapter): array
    {
        $pageAdapter = $this->getContaoAdapter(PageModel::class);

        $urls = [];

        if (!is_array($archiveIds)) {
            return $urls;
        }

        foreach ($archiveIds as $archiveId) {
            $archive = $archiveAdapter->findById($archiveId);

            if (!$archive) {
                continue;
            }

            $modelsInArchive = $modelAdapter->findBy(['pid = ?', 'published = ?'], [$archiveId, 1]) ?? [];
            $page = $pageAdapter->findById($archive->jumpTo);

            //NOTE: For FAQ it is possible to remove the jumpTo page, leading to no page
            if (!$page) {
                continue;
            }

            $pageAlias = $page->alias;

            $time = time();
            foreach ($modelsInArchive as $model) {
                if ((empty($model->start) || $model->start <= $time) && (empty($model->stop) || $model->stop > $time)) {
                    $path = "/" . $model->alias;
                    $urls[] = $page->getAbsoluteUrl($path);
                }
            }
        }

        return $urls;
    }
}