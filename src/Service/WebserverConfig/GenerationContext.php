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
 * Provenance metadata for one webserver-config run.
 *
 * Built by the command glue after argv parsing and passed to any
 * service that needs to render an accurate reproducer command. The
 * emitters do not read argv; this value is the only channel by
 * which "how was I invoked" travels into the generated files.
 *
 * The tier tells consumers WHICH input path won:
 *   - 'live-registry': tier 1, the target's admin API returned a
 *     compiled registry. Reproducer prints
 *     `hordectl webserver-config <flavor> [flags]` verbatim (same
 *     command re-queries the live registry).
 *   - 'stdin-yaml': tier 2, a YAML payload arrived on stdin.
 *     Reproducer says "pipe the same payload back in" and prints the
 *     command the operator likely used to obtain it.
 *   - 'defaults': tier 3, --default-url + --root-bundle-path
 *     synthesis. Reproducer prints those flags verbatim so a fresh
 *     bootstrap emits byte-identical output.
 */
final class GenerationContext
{
    /**
     * @param 'live-registry'|'stdin-yaml'|'defaults' $tier
     * @param 'apache-vhost'|'nginx'|'htaccess' $flavor
     * @param array<string, string|bool> $flags Reduced argv, only the flags this
     *        run consumed. Keys are the long-form option name (`--default-url`
     *        etc.); values are the raw string the user provided, or `true` for
     *        store_true flags.
     */
    public function __construct(
        public readonly string $tier,
        public readonly string $flavor,
        public readonly array $flags,
    ) {
    }

    /**
     * Renders the reproducer as a shell command. Preserves the tier's
     * conventions: live-registry runs read the API; stdin-yaml runs
     * remind the operator to feed the payload back in; defaults runs
     * are fully self-contained.
     */
    public function reproducer(): string
    {
        $cmd = 'hordectl webserver-config ' . $this->flavor;
        foreach ($this->flags as $flag => $value) {
            if ($value === true) {
                $cmd .= ' ' . $flag;
            } elseif ($value === false || $value === '') {
                continue;
            } else {
                $cmd .= ' ' . $flag . '=' . $this->shellQuote((string) $value);
            }
        }
        return $cmd;
    }

    /**
     * Escapes a shell argument for a copy-paste-safe reproducer.
     * Anything without shell-significant characters passes through
     * unquoted for readability.
     */
    private function shellQuote(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_\-\/:.,=@+]+$/', $value)) {
            return $value;
        }
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
