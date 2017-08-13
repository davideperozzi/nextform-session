<?php

namespace Nextform\Session\Models;

class ResultModel implements \JsonSerializable
{
    /**
     * @var boolean
     */
    private $valid = true;

    /**
     * @var boolean
     */
    private $removeFiles = true;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'session' => true,
            'valid' => $this->valid,
            'errors' => $this->errors
        ];
    }

    /**
     * @param string $message
     */
    public function addError(string $message)
    {
        $this->errors[] = $message;
        $this->valid = false;
    }

    /**
     * @param boolan $remove
     */
    public function setRemoveFiles($remove)
    {
        $this->removeFiles = $remove;
    }

    /**
     * @return boolean
     */
    public function isRemoveFiles()
    {
        return $this->removeFiles;
    }

    /**
     * @return boolean
     */
    public function isValid()
    {
        return $this->valid;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this);
    }
}
