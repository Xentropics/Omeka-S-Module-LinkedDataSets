<?php

namespace LinkedDataSets\Infrastructure\Services\Factories;

use Laminas\ServiceManager\Factory\FactoryInterface;
use LinkedDataSets\Infrastructure\Helpers\UriHelper;
use Psr\Container\ContainerInterface;

class UriHelperFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        try {
            $viewHelperManager = $serviceLocator->get('ViewHelperManager');
            return new UriHelper($viewHelperManager);
        } catch (\Exception $e) {
            // Fallback: try to get view helper manager through application services
            $application = $serviceLocator->get('Application');
            $viewHelperManager = $application->getServiceManager()->get('ViewHelperManager');
            return new UriHelper($viewHelperManager);
        }
    }
}
