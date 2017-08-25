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
    private $complete = false;

    /**
     * @var boolean
     */
    private $removeFiles = true;

    /**
     * @var boolean
     */
    private $completeLocked = false;

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
            'complete' => $this->complete,
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
     * @param boolean $complete
     */
    public function setComplete($complete)
    {
        if (true == $this->completeLocked) {
            throw new Exception\CompletionLockedException(
                'This operation is not permitted.
                The result was already locked'
            );
        }

        $this->complete = $complete;
    }

    /**
     * @param boolean $locked
     */
    public function lockComplete($locked)
    {
        $this->completeLocked = $locked;
    }

    /**
     * @return boolean
     */
    public function isComplete()
    {
        return $this->valid && $this->complete;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this);
    }
}
