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

/**
 * Factory for PATCH /api/v1/admin/identities/:username/:index/default requests
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SetDefaultIdentityRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param string $username Username
     * @param int $index Identity index to set as default
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private string $username,
        private int $index
    ) {}

    /**
     * Create PSR-7 request for setting default identity
     *
     * @return RequestInterface
     */
    public function create(): RequestInterface
    {
        $uri = rtrim($this->config->endpoint, '/')
            . '/api/v1/admin/identities/'
            . rawurlencode($this->username)
            . '/' . $this->index
            . '/default';

        $request = $this->requestFactory->createRequest('PATCH', $uri);
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);

        return $request;
    }
}
