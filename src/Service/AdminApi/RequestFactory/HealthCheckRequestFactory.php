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
 * Request factory for health check endpoints
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HealthCheckRequestFactory
{
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private string $subsystem = 'all'
    ) {}

    /**
     * Create GET request for health check
     *
     * @return RequestInterface
     */
    public function create(): RequestInterface
    {
        $url = $this->config->getApiBaseUrl() . '/admin/health/' . urlencode($this->subsystem);

        return $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret);
    }
}
