<?php

namespace Nextform\Session\Constraints;

abstract class AbstractFormDataConstraint
{
    /**
     * @var string
     */
    protected $formId = '';

    /**
     * @param string $formId
     */
    public function __construct($formId)
    {
        $this->formId = $formId;
    }

    /**
     * @param array $data
     * @return boolean
     */
    abstract function resolve($data);
}