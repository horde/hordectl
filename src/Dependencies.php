<?php

/**
 * Dependencies of hordectl
 */

namespace Horde\Hordectl;

use Horde\Hordectl\Configuration\AppConfigReader;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\Service\AdminApiConfig;
use Horde\Http\Client\Curl;
use Horde\Http\Client\Options as HttpClientOptions;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Injector\Injector;

class Dependencies extends Injector
{
    public function __construct($scope)
    {
        parent::__construct($scope);
        $this->setupCommonDependencies();
    }

    /**
     * Setup common dependencies for hordectl
     *
     * Sets up default instances to satisfy DI during module discovery.
     */
    public function setupCommonDependencies()
    {
        // Setup AdminApiClient (lazy - only if HTTP classes available)
        $this->setupAdminApiClient();

        return $this;
    }

    /**
     * Setup AdminApiClient with configuration and dependencies
     *
     * Creates a null placeholder AdminApiClient instance to satisfy DI.
     * Commands should create their own AdminApiClient from target config.
     */
    protected function setupAdminApiClient(): void
    {
        // Register NullAdminApiClient as placeholder
        // Commands must create real client from target configuration
        $this->setInstance(
            AdminApiClient::class,
            new Service\NullAdminApiClient()
        );
    }
    /**
     * Expose Horde Config in global namespace (DEPRECATED)
     *
     * Legacy method - not used in target-based approach.
     *
     * @deprecated No longer needed without Horde bootstrap
     * @return Dependencies
     */
    public function globalizeHordeConfig()
    {
        // Deprecated - no Horde config to globalize
        return $this;
    }

    /**
     * Push/initialize all globals which may be used by application code (DEPRECATED)
     *
     * Legacy method - not used in target-based approach.
     *
     * @deprecated No longer needed without Horde bootstrap
     */
    public function globalizeApp()
    {
        // Deprecated - no globals to push
    }

    /**
     * Hide Horde Config from global namespace (DEPRECATED)
     *
     * Legacy method - not used in target-based approach.
     *
     * @deprecated No longer needed without Horde bootstrap
     * @return Dependencies
     */
    public function unglobalizeHordeConfig()
    {
        // Deprecated - no globals to unset
        return $this;
    }

    /**
     * Return a list of applications from the Horde Registry (DEPRECATED)
     *
     * Legacy method - not available without Horde bootstrap.
     * Use AdminApiClient for resource queries.
     *
     * @deprecated Use AdminApiClient instead
     * @return string[]
     */
    public function getRegistryApplications(): array
    {
        // No Horde Registry available - return empty list
        return [];
    }

    /**
     * Return the application resource provider (DEPRECATED)
     *
     * Legacy method - not available without Horde bootstrap.
     * Use AdminApiClient for resource queries.
     *
     * @deprecated Use AdminApiClient instead
     * @return object|null
     */
    public function getApplicationResources(string $app): ?object
    {
        // No Horde Registry or app resources available - return null
        return null;
    }
}
