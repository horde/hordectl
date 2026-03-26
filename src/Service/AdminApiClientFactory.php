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

        // For local targets, auto-load secret from conf.php if not in target config
        $adminSecret = $target->adminSecret;
        if ($target->isLocal() && ($adminSecret === null || $adminSecret === '')) {
            $confPath = $target->hordeInstallDir . '/var/config/horde/conf.php';

            if (!file_exists($confPath)) {
                throw new RuntimeException(
                    "Cannot load admin_secret: Configuration file not found at {$confPath}\n"
                    . "\nOptions:\n"
                    . "  1. Activate Horde: hordectl activate\n"
                    . "  2. Generate secret: hordectl secret generate"
                );
            }

            $conf = [];
            require $confPath;
            $adminSecret = $conf['admin_api']['admin_secret'] ?? null;

            if ($adminSecret === null || $adminSecret === '') {
                throw new RuntimeException(
                    "admin_secret not configured in {$confPath}\n"
                    . "\nOptions:\n"
                    . "  1. Generate secret: hordectl secret generate\n"
                    . "  2. Sync from conf.php: hordectl target sync-secret {$target->name}\n"
                    . "  3. Set manually: hordectl target update {$target->name} --secret=YOUR_SECRET"
                );
            }
        }

        // Validate we have a secret before creating config
        if ($adminSecret === null || $adminSecret === '') {
            throw new RuntimeException(
                "admin_secret not configured for target '{$target->name}'\n"
                . "\nFor remote targets:\n"
                . "  hordectl target update {$target->name} --secret=YOUR_SECRET\n"
                . "\nFor local targets:\n"
                . "  1. Generate: hordectl secret generate\n"
                . "  2. Sync: hordectl target sync-secret {$target->name}"
            );
        }

        // Create API config from target
        $apiConfig = new AdminApiConfig(
            endpoint: $target->endpoint,
            adminSecret: $adminSecret
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
