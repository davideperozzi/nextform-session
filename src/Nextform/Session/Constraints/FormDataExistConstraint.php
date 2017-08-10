<?php

namespace Nextform\Session\Constraints;

class FormDataExistConstraint extends AbstractFormDataConstraint
{
    /**
     * @var array
     */
    private $keys = [];

    /**
     * @param string $formId
     * @param array $keys
     */
    public function __construct($formId, $keys)
    {
        parent::__construct($formId);

        $this->keys = $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve($data)
    {
        $found = [];

        if (array_key_exists($this->formId, $data)) {
            $model = $data[$this->formId];

            foreach ($this->keys as $key) {
                if (array_key_exists($key, $model->data)) {
                    $found[] = $key;
                }
            }
        }

        return $found == $this->keys;
    }
}