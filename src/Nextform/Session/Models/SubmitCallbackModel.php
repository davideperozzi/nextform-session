<?php

namespace Nextform\Session\Models;

use Nextform\Config\AbstractConfig;

class SubmitCallbackModel
{
    /**
     * @var AbstractConfig
     */
    public $form = null;

    /**
     * @var callable
     */
    public $callback = null;

    /**
     * @param AbstractConfig $form
     * @param callable $callback
     */
    public function __construct(AbstractConfig $form, callable $callback)
    {
        $this->form = $form;
        $this->callback = $callback;
    }
}
