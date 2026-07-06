<?php

/**
 * Dependencies of hordectl
 */

namespace Horde\Hordectl;

use Horde\Cli\Cli as HordeCli;
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
     * Create Output facade with presentation layer
     *
     * @param HordeCli $cli The CLI instance
     * @param array $options Configuration options (verbose, quiet)
     * @return Output The output facade
     */
    public function createOutput(HordeCli $cli, array $options = []): Output
    {
        return new Output(
            $cli,
            verbose: !empty($options['verbose']),
            quiet: !empty($options['quiet'])
        );
    }
}
