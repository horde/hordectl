<?php

/**
 * Writer for a yaml output format
 */

namespace Horde\Hordectl;

use Horde\Yaml\Dumper;

/**
 *
 */
class YamlWriter
{
    private $_dumper;
    private $_resources;

    public function __construct(Dumper $dumper)
    {
        $this->_dumper = $dumper;
        $this->_resources = ['apps' => []];
    }

    public function addResource(string $app, string $type, array $items, array $params = []): void
    {
        $skel = [$app => ['resources' => [ $type => ['items' => $items]]]];
        $this->_resources['apps'] = array_merge($this->_resources['apps'], $skel);
    }

    public function dump(): string
    {
        return $this->_dumper->dump($this->_resources);
    }
}
