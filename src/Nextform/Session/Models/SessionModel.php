<?php

namespace Nextform\Session\Models;

class SessionModel implements \Serializable
{
    /**
     * @var string
     */
    public $id = '';

    /**
     * @var array
     */
    public $data = [];

    /**
     * @param string $id
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * @return boolean
     */
    public function isValid()
    {
        return ! empty($this->id);
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return json_encode($this);
    }

    /**
     * @param string $data
     * @return SessionModel
     */
    public function unserialize($str)
    {
        $properties = json_decode($str, true);

        $this->id = $properties['id'];

        foreach ($properties['data'] as $id => $data) {
            $this->data[$id] = new DataModel($data['data']);
        }
    }
}