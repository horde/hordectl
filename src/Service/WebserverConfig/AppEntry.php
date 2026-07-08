<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service\WebserverConfig;

/**
 * Resolved deployment layout for one Horde app.
 *
 * Immutable value class emitted by AppMapBuilder from any of the
 * three input tiers (query registry, stdin pipe, --default-url
 * synthesis). Consumed by the flavor emitters.
 *
 * All URL fields are absolute when a scheme is known (per-vhost
 * setups) or relative when only a path prefix is known (single-vhost
 * setups). Emitters read isAbsolute() to decide vhost anchoring.
 */
final class AppEntry
{
    public function __construct(
        /** Registry app id, e.g. 'horde', 'imp', 'turba'. */
        public readonly string $id,
        /** Absolute filesystem path to the app's fileroot. */
        public readonly string $fileroot,
        /** URL prefix, absolute (`https://mail.example.org/`) or relative (`/imp/`). */
        public readonly string $webroot,
        /** Filesystem path to static asset root, or empty when the app has none. */
        public readonly string $staticfs = '',
        /** URL prefix for static assets. */
        public readonly string $staticuri = '',
        /** Filesystem path to JS root. */
        public readonly string $jsfs = '',
        /** URL prefix for JS. */
        public readonly string $jsuri = '',
        /** Filesystem path to themes root. */
        public readonly string $themesfs = '',
        /** URL prefix for themes. */
        public readonly string $themesuri = '',
    ) {
    }

    /**
     * True when webroot carries an absolute scheme (https:// / http://).
     * Emitters read this to decide whether to open a dedicated vhost.
     */
    public function isAbsolute(): bool
    {
        return preg_match('#^https?://#i', $this->webroot) === 1;
    }

    /**
     * Returns the hostname portion of an absolute webroot, or empty
     * when the webroot is relative.
     */
    public function host(): string
    {
        if (!$this->isAbsolute()) {
            return '';
        }
        $parts = parse_url($this->webroot);
        return is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
    }

    /**
     * Path portion of the webroot. For a relative webroot this is
     * the full webroot; for an absolute webroot this strips scheme
     * and host.
     */
    public function pathPrefix(): string
    {
        if (!$this->isAbsolute()) {
            return $this->webroot;
        }
        $parts = parse_url($this->webroot);
        return is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';
    }

    /**
     * True when the app uses https. Governs whether the emitted
     * vhost pulls in an operator-owned tls snippet.
     */
    public function isTls(): bool
    {
        return str_starts_with(strtolower($this->webroot), 'https://');
    }

    /**
     * True when the app anchors at the root of its host (no path
     * prefix). Distinct from `isAbsolute()`: an absolute webroot can
     * still carry a `/imp/` path.
     *
     * Root-anchored apps deserve `DocumentRoot <fileroot>` at vhost
     * scope; subpath-anchored apps deserve `Alias /prefix <fileroot>/`
     * inside the vhost.
     */
    public function isRootAnchored(): bool
    {
        $prefix = $this->pathPrefix();
        return $prefix === '' || $prefix === '/';
    }
}
