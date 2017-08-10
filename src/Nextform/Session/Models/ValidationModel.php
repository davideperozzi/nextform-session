<?php

namespace Nextform\Session\Models;

use Nextform\Config\AbstractConfig;
use Nextform\Validation\Validation;

class ValidationModel
{
    /**
     * @var AbastractConfig
     */
    public $form = null;

    /**
     * @var Validation
     */
    public $validation = null;

    /**
     * @param AbstractConfig $form
     * @param Validation $validation
     */
    public function __construct(AbstractConfig &$form, Validation $validation)
    {
        $this->form = $form;
        $this->validation = $validation;
    }
}