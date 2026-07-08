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
 * Writes EmitEntry lists to disk with overwrite / sketch semantics.
 *
 * The only service in the WebserverConfig namespace that touches
 * the filesystem. Isolates I/O so emitters remain pure and testable.
 */
final class ConfigWriter
{
    /**
     * @param list<EmitEntry> $entries
     * @return WriteReport
     */
    public function write(array $entries, bool $force): WriteReport
    {
        $written = [];
        $skipped = [];
        $sketched = [];
        $failed = [];

        foreach ($entries as $entry) {
            $result = $this->writeOne($entry, $force);
            match ($result) {
                'written' => $written[] = $entry->path,
                'skipped' => $skipped[] = $entry->path,
                'sketch-preserved' => $sketched[] = $entry->path,
                'failed' => $failed[] = $entry->path,
            };
        }
        return new WriteReport($written, $skipped, $sketched, $failed);
    }

    private function writeOne(EmitEntry $entry, bool $force): string
    {
        if (is_file($entry->path)) {
            if ($entry->sketch) {
                // Sketches are operator-owned. Never overwrite, even
                // with --force.
                return 'sketch-preserved';
            }
            if (!$force) {
                return 'skipped';
            }
        }
        $dir = dirname($entry->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
            return 'failed';
        }
        if (file_put_contents($entry->path, $entry->content) === false) {
            return 'failed';
        }
        return 'written';
    }
}
