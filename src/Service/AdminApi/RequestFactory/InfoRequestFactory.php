<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Hordectl\Service\AdminApi\RequestFactory;

use Horde\Hordectl\Service\AdminApiConfig;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Factory for /api/v1/admin/info requests
 *
 * Creates PSR-7 HTTP requests for the Horde info endpoint.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class InfoRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory
    ) {}

    /**
     * Create HTTP request for /api/v1/admin/info endpoint
     *
     * @return RequestInterface PSR-7 request
     */
    public function create(): RequestInterface
    {
        $url = $this->config->getApiBaseUrl() . '/admin/info';

        return $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);
    }
}
