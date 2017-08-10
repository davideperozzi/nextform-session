<?php

namespace Nextform\Session;

use Nextform\Config\AbstractConfig;
use Nextform\Fields\InputField;
use Nextform\Validation\Validation;

class Session
{
    /**
     * @var string
     * MD5 hash of nextform_session_id
     */
    const SESSION_FIELD_NAME = '_73d39f2e64de879f0876fdaec6c96a16';

    /**
     * @var integer
     */
    private static $idPrefix = 0;

    /**
     * @var string
     */
    public $id = '';

    /**
     * @var array
     */
    private $forms = [];

    /**
     * @var Models\SessionModel
     */
    private $session = null;

    /**
     * @var array
     */
    private $submitCallbacks = [];

    /**
     * @var array
     */
    private $completeCallbacks = [];

    /**
     * @var array
     */
    private $formDataConstraints = [];

    /**
     * @param string $name
     * @param array $forms
     */
    public function __construct($name, AbstractConfig ...$forms)
    {
        if (session_status() == PHP_SESSION_NONE) {
            if (false == @session_start()) {
                throw new Exception\SessionNotStartedException(
                    'Something went wrong while starting the session.
                    Start it manually and make sure not header was
                    sent before the session will be started'
                );
            }
        }

        foreach ($forms as $i =>  $form) {
            $this->forms['f' . $i] = $form;
        }

        $this->id = 'nextform_' . $name;
        $this->session = $this->restoreSession();

        // Add unique id to forms in this session
        foreach ($this->forms as $id => $form) {
            // Add id field
            $idField = new InputField();
            $idField->setAttribute('name', self::SESSION_FIELD_NAME);
            $idField->setAttribute('value', $this->id . ';' . $id);
            $idField->setAttribute('hidden', '1');

            $form->addField($idField);
        }

        static::$idPrefix++;
    }

    /**
     * @return string
     */
    public static function createToken()
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param AbstractConfig $form
     * @param Validation $validation
     */
    public function setValidation(AbstractConfig &$form, Validation $validation)
    {
        $this->validations[] = new Models\ValidationModel($form, $validation);

        $this->onSubmit($form, function ($data) use (&$validation) {
            $result = $validation->validate($data);
            return $result->isValid();
        });
    }

    /**
     * @param array $mergeData
     * @throws Nextform\Session\Exception\NoValidationFoundException
     * @return Nextform\Validation\Models\ResultModel
     */
    public function validate($mergeData = [])
    {
        foreach ($this->validations as $model) {
            if ($this->isActive($model->form)) {
                $data = $this->getData($mergeData);

                return $model->validation->validate($data);
            }
        }

        throw new Exception\NoValidationFoundException(
            'No active forms found in validation models'
        );
    }

    /**
     * @param callable $callback
     * @return self
     */
    public function onComplete(callable $callback)
    {
        $this->completeCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param callable $callback
     * @return self
     */
    public function onSubmit($form, callable $callback)
    {
        $this->submitCallbacks[] = new Models\SubmitCallbackModel($form, $callback);

        return $this;
    }

    /**
     * @param AbstractConfig $form
     * @param array $data
     * @return boolean
     */
    public function isActive(AbstractConfig $form, $mergeData = [])
    {
        $data = $this->getData($mergeData);

        if (array_key_exists(self::SESSION_FIELD_NAME, $data)) {
            $ids = explode(';', $data[self::SESSION_FIELD_NAME]);
            $sessionId = $ids[0];
            $formId = $ids[1];

            if ($sessionId == $this->session->id) {
                if (array_key_exists($formId, $this->forms)) {
                    if ($this->forms[$formId] == $form) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array
     */
    private function getData($data = [])
    {
        return array_merge(
            $_POST ? $_POST : [],
            $_GET ? $_GET : [],
            $_FILES ? $_FILES : [],
            $data
        );
    }

    /**
     * @param array $data
     * @param array
     */
    public function proccess($mergeData = [])
    {
        $data = $this->getData($mergeData);

        if (array_key_exists(self::SESSION_FIELD_NAME, $data)) {
            $ids = explode(';', $data[self::SESSION_FIELD_NAME]);
            $sessionId = $ids[0];
            $formId = $ids[1];

            if ($sessionId == $this->session->id) {
                if (array_key_exists($formId, $this->forms)) {
                    $models = $this->getSubmitCallbacks($this->forms[$formId]);

                    if (count($models) > 0) {
                        $valid = 0;

                        foreach ($models as $model) {
                            $callback = $model->callback;

                            if (true == $callback($data)) {
                                $valid++;
                            }
                        }

                        if (count($models) == $valid) {
                            $this->saveData($formId, $data);
                        }
                    } else {
                        $this->saveData($formId, $data);
                    }
                }

                $fulfilled = true;
                $valid = 0;

                foreach ($this->formDataConstraints as $constraint) {
                    if ( ! $constraint->resolve($this->session->data)) {
                        $fulfilled = false;
                        break;
                    }
                }

                if (true == $fulfilled) {
                    foreach ($this->completeCallbacks as $callback) {
                        $data = [];

                        foreach ($this->session->data as $id => $values) {
                            if (array_key_exists($id, $this->forms)) {
                                $root = $this->forms[$id]->getFields()->getRoot();
                                $data[$root->id] = $values;
                            }
                        }

                        if (true == $callback($data)) {
                            $valid++;
                        }
                    }
                }

                if ($valid == count($this->completeCallbacks)) {
                    $this->cleanup();
                }
            }
        }

        return $data;
    }

    /**
     * @param AbstractConfig $form
     * @param array $keys
     * @return self
     */
    public function completeIfFormDataExists(AbstractConfig $form, $keys)
    {
        $formId = '';

        foreach ($this->forms as $id => $value) {
            if ($value == $form) {
                $formId = $id;
                break;
            }
        }

        if (empty($formId)) {
            throw new \Exception('Formular not found');
        }

        $this->formDataConstraints[] = new Constraints\FormDataExistConstraint($formId, $keys);
    }

    /**
     * @param AbstractConfig $form
     * @return Models\SubmitCallbackModel
     */
    private function getSubmitCallbacks(AbstractConfig $form)
    {
        $models = [];

        foreach ($this->submitCallbacks as $model) {
            if ($model->form == $form) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * @return
     */
    private function restoreSession()
    {
        if (array_key_exists($this->id, $_SESSION)) {
            $session = unserialize($_SESSION[$this->id]);

            if ($session instanceof Models\SessionModel && $session->isValid()) {
                return $session;
            }
        }

        return new Models\SessionModel($this->id);
    }

    /**
     * @param array $data
     */
    private function saveData($id, $data)
    {
        if (array_key_exists(self::SESSION_FIELD_NAME, $data)) {
            unset($data[self::SESSION_FIELD_NAME]);
        }

        if (array_key_exists($id, $this->session->data)) {
            $this->session->data[$id]->merge($data);
        } else {
            $this->session->data[$id] = new Models\DataModel($data);
        }

        $this->storeSession();
    }

    public function cleanup()
    {
        if (array_key_exists($this->session->id, $_SESSION)) {
            unset($_SESSION[$this->session->id]);
        }
    }

    private function storeSession()
    {
        $_SESSION[$this->id] = serialize($this->session);
    }
}
