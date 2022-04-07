<?php
declare(strict_types=1);

namespace JBSupport\MultipleSitemapsBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use JBSupport\MultipleSitemapsBundle\JBSupportMultipleSitemapsBundle;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpKernel\KernelInterface;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{ 
    public function getBundles(ParserInterface $parser): array
    { 
        return [ 
            BundleConfig::create(JBSupportMultipleSitemapsBundle::class)
                ->setLoadAfter(
                    [
                        ContaoCoreBundle::class,
                    ]
                ),
        ]; 
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = '@JBSupportMultipleSitemapsBundle/Resources/config/routing.yaml';
        return $resolver->resolve($file)->load($file);
    }
}