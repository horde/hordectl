<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl;

use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Output\Presenter;
use Horde\Cli\Output\PresenterFactory;
use Horde\Cli\Output\Presenter\Ci;
use Horde\Cli\Output\Presenter\Unicode;
use Horde\Hordectl\Output\AsciiTableRenderer;
use Horde\Hordectl\Output\MarkdownTableRenderer;
use Horde\Hordectl\Output\Table;
use Horde\Hordectl\Output\TableRenderer;
use Horde\Hordectl\Output\UnicodeTableRenderer;

/**
 * Output facade for hordectl commands
 *
 * Wraps the presentation layer and provides application-level features:
 * - Quiet/verbose mode handling
 * - Backward compatibility with existing message() calls
 * - Semantic categories for rich output
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Output
{
    private readonly Presenter $presenter;

    public function __construct(
        private readonly HordeCli $cli,
        private readonly bool $verbose = false,
        private readonly bool $quiet = false
    ) {
        // Auto-detect best presenter for environment
        $this->presenter = PresenterFactory::create($cli, [
            'cli_format' => 'autodetect',
        ]);
    }

    /**
     * Success message (green checkmark or [   OK   ])
     */
    public function ok(string $message): void
    {
        if (!$this->quiet) {
            $this->presenter->ok($message);
        }
    }

    /**
     * Warning message (yellow symbol or [  WARN  ])
     */
    public function warn(string $message): void
    {
        if (!$this->quiet) {
            $this->presenter->warn($message);
        }
    }

    /**
     * Info message (blue symbol or [  INFO  ])
     */
    public function info(string $message): void
    {
        if (!$this->quiet) {
            $this->presenter->info($message);
        }
    }

    /**
     * Error message (red X or [ ERROR! ]) - ALWAYS shown
     */
    public function error(string $message): void
    {
        // Errors always shown, ignore quiet flag
        $this->presenter->error($message);
    }

    /**
     * Semantic category output (detected, created, running, etc.)
     */
    public function semantic(string $category, string $message): void
    {
        if (!$this->quiet) {
            $this->presenter->semantic($category, $message);
        }
    }

    /**
     * Backward compatibility: map old message() calls to presenter
     */
    public function message(string $message, string $type = 'cli.message'): void
    {
        match ($type) {
            'cli.success' => $this->ok($message),
            'cli.error' => $this->error($message),
            'cli.warning' => $this->warn($message),
            'cli.message' => $this->info($message),
            default => $this->info($message),
        };
    }

    /**
     * Access underlying CLI for raw operations
     */
    public function getCli(): HordeCli
    {
        return $this->cli;
    }

    /**
     * Access underlying presenter
     */
    public function getPresenter(): Presenter
    {
        return $this->presenter;
    }

    /**
     * Render a structured table.
     *
     * Chooses a renderer based on the concrete presenter type.
     * Unicode presenter uses box-drawing borders. Ci presenter emits
     * a markdown pipe table for GitHub Actions / GitLab CI log
     * viewers. Everything else falls back to the classic ASCII grid.
     *
     * Suppressed under --quiet. This method is a phase-1 shim; phase 2
     * pushes the renderer choice into the Presenter interface itself.
     *
     * @see ~/php/horde-development/tools/hordectl/table-presenter-sub-plan-2026-07-08.md
     * @see ~/php/horde-development/libraries/cli/table-presenter-port-2026-07-08.md
     */
    public function table(Table $table): void
    {
        if ($this->quiet) {
            return;
        }
        $renderer = $this->pickTableRenderer();
        $renderer->render($table);
    }

    private function pickTableRenderer(): TableRenderer
    {
        return match (true) {
            $this->presenter instanceof Unicode => new UnicodeTableRenderer($this->cli),
            $this->presenter instanceof Ci      => new MarkdownTableRenderer($this->cli),
            default                              => new AsciiTableRenderer($this->cli),
        };
    }
}
