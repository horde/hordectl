<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service;

use Horde\Hordectl\Target;
use Horde\Http\Client\Curl;
use Horde\Http\Client\Options as HttpClientOptions;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use RuntimeException;

/**
 * Factory for creating AdminApiClient instances from Target configuration
 *
 * Creates real AdminApiClient with HTTP dependencies configured from Target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class AdminApiClientFactory
{
    /**
     * Create AdminApiClient from Target configuration
     *
     * @param Target $target Target with API configuration
     * @return AdminApiClient Configured API client
     * @throws RuntimeException If target doesn't support API commands
     */
    public function createFromTarget(Target $target): AdminApiClient
    {
        // Validate target has API capability
        if (!$target->supportsApiCommands()) {
            throw new RuntimeException(
                "Target '{$target->name}' does not support API commands. "
                . "Endpoint and admin secret required."
            );
        }

        // Create API config from target
        $apiConfig = new AdminApiConfig(
            endpoint: $target->endpoint,
            adminSecret: $target->adminSecret
        );

        // Create HTTP client (PSR-18)
        $clientOptions = new HttpClientOptions([
            'timeout' => 30,
            'verifyPeer' => $target->verifySsl,
        ]);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $httpClient = new Curl($responseFactory, $streamFactory, $clientOptions);

        // Create request factory (PSR-17)
        $requestFactory = new RequestFactory();

        return new AdminApiClient($apiConfig, $httpClient, $requestFactory);
    }
}
