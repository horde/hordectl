<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service\AdminApi\RequestFactory;

use Horde\Hordectl\Service\AdminApiConfig;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Factory for PUT /api/v1/admin/identities/:username/:index requests
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UpdateIdentityRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param string $username Username
     * @param int $index Identity index
     * @param array $identityData Partial identity data to update
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private string $username,
        private int $index,
        private array $identityData
    ) {}

    /**
     * Create PSR-7 request for identity update
     *
     * @return RequestInterface
     */
    public function create(): RequestInterface
    {
        $uri = rtrim($this->config->endpoint, '/')
            . '/api/v1/admin/identities/'
            . rawurlencode($this->username)
            . '/' . $this->index;

        $body = $this->streamFactory->createStream(
            json_encode($this->identityData, JSON_THROW_ON_ERROR)
        );

        $request = $this->requestFactory->createRequest('PUT', $uri);
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody($body);

        return $request;
    }
}
