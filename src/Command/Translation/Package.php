<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Translation;

/**
 * Discovered translatable Horde package.
 *
 * Every entry emerging from TranslationPackageFinder is either an
 * application or a library. Non-translatable types (composer-plugin,
 * horde-theme, extension, component, project) are filtered out at
 * discovery time and never surface as Package instances.
 *
 * @see ~/php/horde-development/tools/hordectl/hordectl-translation-subcommand-plan-2026-07-08.md
 */
final class Package
{
    public function __construct(
        /** Package id from .horde.yml, e.g. 'horde', 'Core', 'imp', 'Perms'. */
        public readonly string $id,
        /** Absolute filesystem path to the package root. */
        public readonly string $path,
        /** Either 'application' or 'library'. */
        public readonly string $type,
        /**
         * gettext text domain used at runtime. Defaults to $id but
         * libraries carrying Horde_-prefixed po files (e.g. Exception
         * → Horde_Exception) override this via discovery. See
         * TranslationHelperTrait::discoverDomain().
         */
        public readonly string $domain = '',
    ) {}

    /**
     * Returns the effective text domain: either the explicit
     * $domain from discovery or a fallback to $id.
     */
    public function effectiveDomain(): string
    {
        return $this->domain !== '' ? $this->domain : $this->id;
    }

    /**
     * Applications ship help.xml, libraries do not. Used by
     * UpdateHelp / MakeHelp subcommands to filter their target set.
     */
    public function isApplication(): bool
    {
        return $this->type === 'application';
    }

    public function localeDir(): string
    {
        return $this->path . '/locale';
    }

    public function potFile(): string
    {
        return $this->localeDir() . '/' . $this->effectiveDomain() . '.pot';
    }

    public function poFile(string $locale): string
    {
        return $this->localeDir() . '/' . $locale . '/LC_MESSAGES/' . $this->effectiveDomain() . '.po';
    }

    public function moFile(string $locale): string
    {
        return $this->localeDir() . '/' . $locale . '/LC_MESSAGES/' . $this->effectiveDomain() . '.mo';
    }

    public function helpXmlFile(string $locale): string
    {
        return $this->localeDir() . '/' . $locale . '/help.xml';
    }
}
