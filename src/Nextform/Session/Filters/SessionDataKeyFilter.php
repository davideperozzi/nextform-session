<?php

namespace Nextform\Session\Filters;

class SessionDataKeyFilter extends AbstractSessionDataFilter
{
    /**
     * @param array
     */
    private $keys = [];

    /**
     * @param array $keys
     * @param callable $callback
     */
    public function __construct(array $keys, callable $callback)
    {
        parent::__construct($callback);

        $this->keys = $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function filter(&$data)
    {
        foreach ($this->keys as $key) {
            if ( ! array_key_exists($key, $data)) {
                return;
            }
        }

        parent::filter($data);
    }
}
