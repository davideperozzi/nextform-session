<?php

namespace Nextform\Session\Models;

class DataModel
{
    /**
     * @var array
     */
    public $data = [];

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array $data
     */
    public function merge($data)
    {
        $this->data = array_replace_recursive($this->data, $data);
    }
}
