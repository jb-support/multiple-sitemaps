<?php

declare(strict_types=1);

namespace JBSupport\MultipleSitemapsBundle\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;

/* THX TO Terminal42 UrlRewrite Extension for routing/caching hints */

class RegisterSitemapRoutes extends Loader
{
    /**
     * Attribute name.
     */
    public const ATTRIBUTE_NAME = '_jb_multiple_sitemaps';

    /**
     * Has been already loaded?
     *
     * @var bool
     */
    private $loaded = false;

    /**
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(Connection $connection)
    {
        // Do not call parent constructor, it does not exist in Symfony < 5
        $this->connection = $connection;
    }

    public function load($resource, $type = null): RouteCollection
    {
        if (true === $this->loaded) {
            throw new \RuntimeException('Do not add the "jb muptiple sitemaps" loader twice');
        }

        $this->loaded = true;
        $collection = new RouteCollection();
        
        $count=0;
        foreach ($this->generateRoutes() as $route) {
            $collection->add('jb_multiple_sitemaps_'.$count++, $route);
        }

         return $collection;
    }

    public function supports($resource, $type = null): bool
    {
        return 'jb_multiple_sitemaps' === $type;
    }

    /**
     * Generate the routes.
     */
    private function generateRoutes(): ?\Generator
    {
        try {
            $sitemaps = $this->connection->fetchAllAssociative(
                'SELECT id, filename FROM tl_jb_sitemap WHERE published=:published',
                ['published' => 1]
            );

            if ($sitemaps) {
                foreach ($sitemaps as $sitemap) {
                    yield $this->createRoute($sitemap["filename"], $sitemap["id"]);
                }
            }
        } catch (\PDOException | ConnectionException | TableNotFoundException | InvalidFieldNameException $e) {
            // Database not ready yet
        }

        return null;
    }

    /**
     * Create the route object.
     */
    private function createRoute($url, $sitemapId): ?Route
    {
        $route = new Route(rawurldecode("/". $url));
        $route->setDefault('_controller', 'JBSupport\MultipleSitemapsBundle\Controller\MultipleSitemapController::index');
        $route->setDefault(self::ATTRIBUTE_NAME, $sitemapId);
        $route->setOption('utf8', true);
        $route->setMethods('GET');

        return $route;
    }
}
