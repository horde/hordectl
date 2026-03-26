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
 * Factory for POST /api/v1/admin/users requests (create user)
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class CreateUserRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param string $username Username to create
     * @param string $password User password
     * @param bool $skipIdentity Skip creating default identity
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private string $username,
        private string $password,
        private bool $skipIdentity = false
    ) {}

    /**
     * Create PSR-7 request for user creation
     *
     * @return RequestInterface
     */
    public function create(): RequestInterface
    {
        $uri = rtrim($this->config->endpoint, '/') . '/api/v1/admin/users';

        $request = $this->requestFactory->createRequest('POST', $uri);

        // Add authentication header
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);
        $request = $request->withHeader('Content-Type', 'application/json');

        // Add JSON body
        $body = json_encode([
            'username' => $this->username,
            'password' => $this->password,
            'skip_identity' => $this->skipIdentity,
        ]);

        $stream = $request->getBody();
        $stream->write($body);

        return $request;
    }
}
