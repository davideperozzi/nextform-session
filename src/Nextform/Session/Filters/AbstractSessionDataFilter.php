<?php

namespace Nextform\Session\Filters;

abstract class AbstractSessionDataFilter
{
    /**
     * @var callable
     */
    protected $callback = null;

    /**
     * @param callable $callback
     */
    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param array &$data
     */
    public function filter(&$data)
    {
        $callback = $this->callback;
        $callback($data);
    }
}