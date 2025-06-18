<?php

namespace JBSupport\MultipleSitemapsBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class JBSupportMultipleSitemapsBundle extends Bundle
{
    public function boot(): void
    {
        if (class_exists(AnnotationRegistry::class)) {
            AnnotationRegistry::registerLoader('class_exists');
        }
    }
}
