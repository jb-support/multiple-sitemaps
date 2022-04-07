<?php

declare(strict_types=1);

namespace JBSupport\MultipleSitemapsBundle\EventListener;

use Contao\Backend;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Image;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Symfony\Cmf\Component\Routing\ChainRouterInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class SitemapsChangedListener
{
    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var Filesystem
     */
    private $fs;

    public function __construct(RouterInterface $router, string $cacheDir, ContaoFramework $framework, Filesystem $fs = null)
    {
        if (null === $fs) {
            $fs = new Filesystem();
        }

        $this->router = $router;
        $this->cacheDir = $cacheDir;
        $this->fs = $fs;
        $this->framework = $framework;
    }

    public function addSitemapCacheInvalidationTag($dc, array $tags)
    {
        // Todo: Maybe implement more ingelligent function to get only required sitemaps bag. Tricky because of index mapping etc.
        return array_merge($tags, ['jb.sitemap']);
    }

    /**
     * On records modified.
     */
    public function onRecordsModified(): void
    {
        $this->clearRouterCache();
        $this->invalidateSitemap();
    }

    /**
     * On inactive save callback.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function onInactiveSaveCallback($value)
    {
        $this->clearRouterCache();
        $this->invalidateSitemap();

        return $value;
    }

    /**
     * Clear the router cache.
     */
    private function clearRouterCache(): void
    {
        // Search Symfony router in CMF ChainRouter (Contao 4.7+)
        if ($this->router instanceof ChainRouterInterface) {
            foreach ($this->router->all() as $router) {
                if ($router instanceof Router) {
                    $this->clearSymfonyRouterCache($router);
                }
            }
        }

        // Regular Symfony router (Contao 4.4+)
        if ($this->router instanceof Router) {
            $this->clearSymfonyRouterCache($this->router);
        }

        if ($this->router instanceof WarmableInterface) {
            $this->router->warmUp($this->cacheDir);
        }

        // Clear the Zend OPcache
        if (\function_exists('opcache_reset')) {
            // @codeCoverageIgnoreStart
            opcache_reset();
            // @codeCoverageIgnoreEnd
        }

        // Clear the APC OPcache
        if (\function_exists('apc_clear_cache')) {
            // @codeCoverageIgnoreStart
            apc_clear_cache('opcode');
            // @codeCoverageIgnoreEnd
        }
    }

    private function clearSymfonyRouterCache(Router $router): void
    {
        try {
            $cacheClasses = [];

            foreach (['generator_cache_class', 'matcher_cache_class'] as $option) {
                $cacheClasses[] = $router->getOption($option);
            }
        } catch (\InvalidArgumentException $exception) {
            $cacheClasses = ['url_generating_routes', 'url_matching_routes'];
        }

        foreach ($cacheClasses as $class) {
            $file = $this->cacheDir.\DIRECTORY_SEPARATOR.$class.'.php';

            if ($this->fs->exists($file)) {
                $this->fs->remove($file);
            }
        }
    }

    private function invalidateSitemap()
    {
        $container = System::getContainer();

        if (!$container->has('fos_http_cache.cache_manager')) {
            return;
        }

        /** @var CacheManager $cacheManager */
        $cacheManager = $container->get('fos_http_cache.cache_manager');
        $tag = 'jb.sitemap';

        $cacheManager->invalidateTags([$tag]);
    }
}
