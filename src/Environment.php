<?php
declare(strict_types=1);
namespace Horde\Hordectl;
use ArrayObject;

class Environment extends ArrayObject
{
    public function __construct(array $array = [])
    {
        parent::__construct($array, ArrayObject::ARRAY_AS_PROPS);
    }

    public function get(string $key, $default = null)
    {
        return $this->offsetExists($key) ? $this->offsetGet($key) : $default;
    }
}
