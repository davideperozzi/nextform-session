<?php

namespace Nextform\Session\Models;

use Nextform\Config\AbstractConfig;
use Nextform\FileHandler\FileHandler;

class FileHandlerModel
{
    /**
     * @var AbastractConfig
     */
    public $form = null;

    /**
     * @var FileHandler
     */
    public $fileHandler = null;

    /**
     * @param AbstractConfig $form
     * @param FileHandler $fileHandler
     */
    public function __construct(AbstractConfig &$form, FileHandler &$fileHandler)
    {
        $this->form = $form;
        $this->fileHandler = $fileHandler;
    }
}
