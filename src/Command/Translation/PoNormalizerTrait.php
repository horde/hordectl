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
 * Normalizes .po / .pot content to a canonical form for reviewers
 * and CI, without mutating any source file.
 *
 * The normalization pipeline:
 *   1. msgcat --sort-output --no-wrap  (stable msgid order, no line wraps)
 *   2. Strip volatile header fields    (POT-Creation-Date, PO-Revision-Date, ...)
 *   3. Optionally strip #: reference lines (--ignore-refs)
 *
 * Every call is temp-file based. Callers pass the ORIGINAL path;
 * the returned string is the normalized content. Nothing on disk
 * moves. This is deliberately paranoid: we never want a "check"
 * or "diff" run to alter what the translator committed.
 *
 * Requires TranslationHelperTrait for the runGettext() plumbing.
 */
trait PoNormalizerTrait
{
    /**
     * Volatile po/pot header fields that CI should ignore. Anything
     * that changes just because a fresh extract happened lives here.
     *
     * @var list<string>
     */
    private array $volatileHeaders = [
        'POT-Creation-Date',
        'PO-Revision-Date',
        'Last-Translator',
        'Language-Team',
    ];

    /**
     * Normalizes a single .po/.pot file and returns the canonical
     * text. The file on disk is not touched.
     *
     * $sourcePath is only used for msgcat's --directory resolution
     * and error messages. The actual content comes from disk.
     */
    private function normalizePo(string $sourcePath, bool $ignoreRefs = false): string
    {
        if (!is_file($sourcePath)) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hordectl-norm-');
        if ($tmp === false) {
            return '';
        }
        // Emit msgcat's output into $tmp, then read+strip in PHP.
        $result = $this->runGettext('msgcat', [
            '--sort-output',
            '--no-wrap',
            '--output-file=' . $tmp,
            $sourcePath,
        ]);
        if ($result['exitCode'] !== 0) {
            @unlink($tmp);
            // Fallback: return the raw file so callers can still diff.
            return (string) file_get_contents($sourcePath);
        }
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $this->stripVolatileMetadata($content, $ignoreRefs);
    }

    /**
     * Same as normalizePo() but for a string buffer already loaded.
     * Used by check() when the "candidate" side is a freshly-generated
     * po we already have on disk in a workspace.
     */
    private function normalizePoContent(string $content, bool $ignoreRefs = false): string
    {
        $tmpIn = tempnam(sys_get_temp_dir(), 'hordectl-norm-in-');
        $tmpOut = tempnam(sys_get_temp_dir(), 'hordectl-norm-out-');
        if ($tmpIn === false || $tmpOut === false) {
            if ($tmpIn !== false) {
                @unlink($tmpIn);
            }
            if ($tmpOut !== false) {
                @unlink($tmpOut);
            }
            return $this->stripVolatileMetadata($content, $ignoreRefs);
        }
        file_put_contents($tmpIn, $content);
        $result = $this->runGettext('msgcat', [
            '--sort-output',
            '--no-wrap',
            '--output-file=' . $tmpOut,
            $tmpIn,
        ]);
        if ($result['exitCode'] !== 0) {
            @unlink($tmpIn);
            @unlink($tmpOut);
            return $this->stripVolatileMetadata($content, $ignoreRefs);
        }
        $normalized = (string) file_get_contents($tmpOut);
        @unlink($tmpIn);
        @unlink($tmpOut);
        return $this->stripVolatileMetadata($normalized, $ignoreRefs);
    }

    /**
     * Removes volatile header fields from the po/pot msgstr block,
     * and (optionally) drops all `#:` reference comments.
     *
     * Kept as a pure string operation so tests don't need msgcat.
     */
    private function stripVolatileMetadata(string $content, bool $ignoreRefs): string
    {
        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            return $content;
        }
        $out = [];
        foreach ($lines as $line) {
            // "POT-Creation-Date: 2026-07-08 13:17+0200\n" style lines
            // live inside the header msgstr block as quoted strings.
            $isVolatile = false;
            foreach ($this->volatileHeaders as $field) {
                if (preg_match('/^"' . preg_quote($field, '/') . ':/', $line)) {
                    $isVolatile = true;
                    break;
                }
            }
            if ($isVolatile) {
                continue;
            }
            if ($ignoreRefs && str_starts_with($line, '#: ')) {
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * Diffs two normalized po/pot buffers and returns the unified
     * diff as a string. Empty return means no material change.
     *
     * Wraps the standard `diff -u`. No PHP diff libraries because
     * gettext maintainers, reviewers and CI all speak unified diff
     * fluently. It matches what they already read in `git log -p`.
     */
    private function unifiedDiff(string $left, string $right, string $leftLabel, string $rightLabel): string
    {
        $tmpL = tempnam(sys_get_temp_dir(), 'hordectl-diff-l-');
        $tmpR = tempnam(sys_get_temp_dir(), 'hordectl-diff-r-');
        if ($tmpL === false || $tmpR === false) {
            if ($tmpL !== false) {
                @unlink($tmpL);
            }
            if ($tmpR !== false) {
                @unlink($tmpR);
            }
            return "diff: could not allocate temp files\n";
        }
        file_put_contents($tmpL, $left);
        file_put_contents($tmpR, $right);
        $result = $this->runGettext('diff', [
            '-u',
            '--label=' . $leftLabel,
            '--label=' . $rightLabel,
            $tmpL,
            $tmpR,
        ]);
        @unlink($tmpL);
        @unlink($tmpR);
        // diff returns 0 (identical), 1 (different), 2 (error).
        if ($result['exitCode'] === 0) {
            return '';
        }
        return $result['stdout'];
    }
}
