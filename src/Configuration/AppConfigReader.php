<?php

namespace Horde\Hordectl\Configuration;

use Horde_Registry;

/**
 * Handles reading a stack of configuration files
 *
 */
class AppConfigReader
{
    protected $registry;

    public function __construct(Horde_Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Read a stack of application $conf files
     *
     * TODO: Factor out reusable parts for "backends", yaml configs etc
     */
    public function getAppConfig(string $forApp = '', string $context = '')
    {
        $apps = ['horde'];
        $configFiles = [];
        if ($forApp && $forApp != 'horde') {
            $apps[] = $forApp;
        }
        foreach ($apps as $app) {
            // get app config dir
            $configDir = $this->registry->get('fileroot', $app) . '/config/';

            // get conf.php
            $file = $configDir . 'conf.php';
            if (is_readable($file)) {
                $configFiles[] = $file;
            }
            // get conf.d files
            if (is_dir($configDir . 'conf.d/')) {
                foreach (new DirectoryIterator($configDir . 'conf.d/*.php') as $file) {
                    if ($file->isDot()) {
                        continue;
                    }
                    if (!$file->isReadable()) {
                        continue;
                    }
                    if ($file->getExtension != 'php') {
                        continue;
                    }
                    $configFiles[] = $file->getPathName();
                }
            }
            // get conf.local file
            // get conf-context file
            // TODO
        }
        $stackedConfig = [];
        foreach ($configFiles as $file) {
            $conf = []; // ensure to "forget" contents from previous runs
            include $file;
            $stackedConfig = array_merge($stackedConfig, $conf);
        }
        return $stackedConfig;
    }
}
