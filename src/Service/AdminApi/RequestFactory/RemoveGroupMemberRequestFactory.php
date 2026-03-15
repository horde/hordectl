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
 * Factory for DELETE /api/v1/admin/groups/:identifier/members/:username requests
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class RemoveGroupMemberRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param string $identifier Group identifier/name
     * @param string $username Username to remove
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private string $identifier,
        private string $username
    ) {}

    /**
     * Create PSR-7 request for removing group member
     *
     * @return RequestInterface
     */
    public function create(): RequestInterface
    {
        $uri = rtrim($this->config->endpoint, '/')
            . '/api/v1/admin/groups/'
            . rawurlencode($this->identifier)
            . '/members/'
            . rawurlencode($this->username);

        $request = $this->requestFactory->createRequest('DELETE', $uri);
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);

        return $request;
    }
}
