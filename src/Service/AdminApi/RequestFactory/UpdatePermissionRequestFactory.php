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
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Factory for PATCH /api/v1/admin/permissions/{name} requests
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UpdatePermissionRequestFactory
{
    /**
     * Constructor
     *
     * @param AdminApiConfig $config API configuration
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param string $name Permission name
     * @param array $data Permission data to update (users, groups, default, guest, creator)
     */
    public function __construct(
        private AdminApiConfig $config,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private string $name,
        private array $data
    ) {}

    /**
     * Create HTTP request for updating permission
     *
     * @return RequestInterface PSR-7 request
     */
    public function create(): RequestInterface
    {
        $url = $this->config->getApiBaseUrl() . '/admin/permissions/' . urlencode($this->name);

        $body = json_encode($this->data);
        $stream = $this->streamFactory->createStream($body);

        return $this->requestFactory->createRequest('PATCH', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->config->adminSecret)
            ->withBody($stream);
    }
}
