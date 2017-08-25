<?php

namespace Nextform\Session\Models;

class SessionModel implements \Serializable
{
    /**
     * @var string
     */
    public $id = '';

    /**
     * @var integer
     */
    public $created = -1;

    /**
     * @var array
     */
    public $data = [];

    /**
     * @var array
     */
    public $submittedFileForms = [];

    /**
     * @param string $id
     */
    public function __construct($id)
    {
        $this->id = $id;
        $this->created = time();
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
        $this->created = $properties['created'];
        $this->submittedFileForms = array_key_exists('submittedFileForms', $properties)
            ? $properties['submittedFileForms']
            : [];

        foreach ($properties['data'] as $id => $data) {
            $this->data[$id] = new DataModel($data['data']);
        }
    }
}
