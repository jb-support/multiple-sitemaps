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
        $tags = ['jb.sitemap', 'jb.sitemap.' . $jbSitemap["id"]];

        foreach ($rootPages as $rootPage) {
            $urls[] = $this->getPageAndArticleUrls((int) $rootPage->id, [(int) $rootPage->id], $jbSitemap);
            $rootPageIds[] = $rootPage->id;
        }

        $selectedNewsIds = !empty(StringUtil::deserialize($jbSitemap['newsList'], true)) ? StringUtil::deserialize($jbSitemap['newsList'], true) : [];
        $selectedEventsIds = !empty(StringUtil::deserialize($jbSitemap['eventsList'], true)) ? StringUtil::deserialize($jbSitemap['eventsList'], true) : [];
        $selectedFaqIds = !empty(StringUtil::deserialize($jbSitemap['faqList'], true)) ? StringUtil::deserialize($jbSitemap['faqList'], true) : [];

        if (!empty($selectedNewsIds) && class_exists(NewsArchiveModel::class)) {
            $urls[] = $this->extractUrls($selectedNewsIds, $this->getContaoAdapter(NewsArchiveModel::class), $this->getContaoAdapter(NewsModel::class));
        }
        if (!empty($selectedEventsIds) && class_exists(CalendarModel::class)) {
            $urls[] = $this->extractUrls($selectedEventsIds, $this->getContaoAdapter(CalendarModel::class), $this->getContaoAdapter(CalendarEventsModel::class));
        }
        if (!empty($selectedFaqIds) && class_exists(FaqCategoryModel::class)) {
            $urls[] = $this->extractUrls($selectedFaqIds, $this->getContaoAdapter(FaqCategoryModel::class), $this->getContaoAdapter(FaqModel::class));
        }

        $urls = array_unique(array_merge(...$urls));

        $tempSitemap = new \DOMDocument('1.0', 'UTF-8');
        $tempUrlSet = $tempSitemap->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $tempSitemap->appendChild($tempUrlSet);

        $this->container
            ->get('event_dispatcher')
            ->dispatch(new SitemapEvent($tempSitemap, $request, $rootPageIds), ContaoCoreEvents::SITEMAP);

        $eventUrls = [];
        foreach ($tempSitemap->getElementsByTagName('loc') as $locNode) {
            if (!empty($locNode->nodeValue)) {
                $eventUrls[] = $locNode->nodeValue;
            }
        }

        $blockedUrls = $this->getBlockedCoreUrls($selectedNewsIds, $selectedEventsIds, $selectedFaqIds);
        $eventUrls = array_diff($eventUrls, $blockedUrls);

        $finalUrls = array_unique(array_merge($urls, $eventUrls));

        $sitemap = new \DOMDocument('1.0', 'UTF-8');
        $sitemap->formatOutput = true;
        $urlSet = $sitemap->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');

        foreach ($finalUrls as $url) {
            $loc = $sitemap->createElement('loc');
            $loc->appendChild($sitemap->createTextNode($url));

            $urlEl = $sitemap->createElement('url');
            $urlEl->appendChild($loc);

            if (!empty($jbSitemap["priority"]) && $jbSitemap["priority"] > 0) {
                $prio = $sitemap->createElement('priority', (string)$jbSitemap["priority"]);
                $urlEl->appendChild($prio);
            }
            $urlSet->appendChild($urlEl);
        }

        $sitemap->appendChild($urlSet);

        // Cache the response for a given time in the shared cache and tag it for invalidation purposes
        $response = new Response((string) $sitemap->saveXML(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setSharedMaxAge((int) $jbSitemap["maxAge"]); // will be unset by the MakeResponsePrivateListener if a user is logged in

        $this->tagResponse($tags);
        return $response;
    }

    private function getBlockedCoreUrls(array $selectedNewsIds, array $selectedEventsIds, array $selectedFaqIds): array
    {
        $blocked = [];

        if (class_exists(NewsArchiveModel::class)) {
            $archiveAdapter = $this->getContaoAdapter(NewsArchiveModel::class);
            if ($all = $archiveAdapter->findAll()) {
                $unselected = [];
                foreach ($all as $archive) {
                    if (!in_array($archive->id, $selectedNewsIds)) {
                        $unselected[] = $archive->id;
                    }
                }
                if (!empty($unselected)) {
                    $blocked = array_merge($blocked, $this->extractUrls($unselected, $archiveAdapter, $this->getContaoAdapter(NewsModel::class)));
                }
            }
        }

        if (class_exists(CalendarModel::class)) {
            $archiveAdapter = $this->getContaoAdapter(CalendarModel::class);
            if ($all = $archiveAdapter->findAll()) {
                $unselected = [];
                foreach ($all as $archive) {
                    if (!in_array($archive->id, $selectedEventsIds)) {
                        $unselected[] = $archive->id;
                    }
                }
                if (!empty($unselected)) {
                    $blocked = array_merge($blocked, $this->extractUrls($unselected, $archiveAdapter, $this->getContaoAdapter(CalendarEventsModel::class)));
                }
            }
        }

        if (class_exists(FaqCategoryModel::class)) {
            $archiveAdapter = $this->getContaoAdapter(FaqCategoryModel::class);
            if ($all = $archiveAdapter->findAll()) {
                $unselected = [];
                foreach ($all as $archive) {
                    if (!in_array($archive->id, $selectedFaqIds)) {
                        $unselected[] = $archive->id;
                    }
                }
                if (!empty($unselected)) {
                    $blocked = array_merge($blocked, $this->extractUrls($unselected, $archiveAdapter, $this->getContaoAdapter(FaqModel::class)));
                }
            }
        }

        return $blocked;
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
                $loc = $sitemapIndex->createElement('loc');
                $loc->appendChild($sitemapIndex->createTextNode($url));

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

        foreach ($pageModels as $pageModel) {
            $newPageTreeIds = $pageTreeIds;
            $newPageTreeIds[] = $pageModel->id;

            if ($pageModel->protected && !$this->isGranted(ContaoCorePermissions::MEMBER_IN_GROUPS, $pageModel->groups)) {
                continue;
            }

            $isPublished = $pageModel->published && (!$pageModel->start || $pageModel->start <= time()) && (!$pageModel->stop || $pageModel->stop > time());

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

            $modelsInArchive = $modelAdapter->findBy(['pid = ?', 'published = ?'], [$archiveId, 1]);
            $page = $pageAdapter->findById($archive->jumpTo);

            if (!$page || !$modelsInArchive) {
                continue;
            }

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