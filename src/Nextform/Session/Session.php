<?php

namespace Nextform\Session;

use Nextform\Config\AbstractConfig;
use Nextform\Fields\InputField;
use Nextform\Validation\Validation;
use Nextform\FileHandler\FileHandler;

class Session
{
    /**
     * @var string
     *
     * MD5 hash of nextform_session_id
     */
    const SESSION_FIELD_NAME = '_73d39f2e64de879f0876fdaec6c96a16';

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
     * @var array
     */
    private $sessionDataFilters = [];

    /**
     * @var array
     */
    private $validations = [];

    /**
     * @var array
     */
    private $fileHandlers = [];

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

        // Add filter to remove name field from data before saving
        $this->filterIfDataContainsKeys([self::SESSION_FIELD_NAME], function(&$data){
            unset($data[self::SESSION_FIELD_NAME]);
        });
    }

    /**
     * @return string
     */
    public static function createToken()
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param array &$validations
     */
    public function addValidation(Validation &...$validations)
    {
        foreach ($validations as &$validation) {
            $form = $validation->getConfig();
            $this->validations[] = new Models\ValidationModel($form, $validation);

            $this->onSubmit($form, function (&$data) use (&$validation) {
                $result = $validation->validate($data);

                return $result->isValid();
            });
        }
    }

    /**
     * @param array &$fileHandlers
     */
    public function addFileHandler(FileHandler &...$fileHandlers)
    {
        foreach ($fileHandlers as &$fileHandler) {
            $form = $fileHandler->getForm();
            $this->fileHandlers[] = new Models\FileHandlerModel($form, $fileHandler);

            $this->onSubmit($form, function (&$data) use (&$fileHandler) {
                $fileHandler->handle($data);

                return true;
            });
        }
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
     * @return Nextform\Validation\Models\ResultModel|null
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
                        else {
                            $this->clearData($formId);
                        }
                    } else {
                        $this->saveData($formId, $data);
                    }
                }

                $fulfilled = true;

                // Check if all forms are given
                foreach ($this->forms as $id => $form) {
                    if ( ! array_key_exists($id, $this->session->data)) {
                        $fulfilled = false;
                        break;
                    }
                }

                // Check if constrains will be resolved
                if (true == $fulfilled) {
                    foreach ($this->formDataConstraints as $constraint) {
                        if ( ! $constraint->resolve($this->session->data)) {
                            $fulfilled = false;
                            break;
                        }
                    }
                }

                $valid = 0;

                // All forms appear to be stored in the session
                // Now revalidate the data, let the user handle the callbacks
                // and clear the data if everything is correct
                if (true == $fulfilled) {
                    // Revalidate data stored in the session to ensure it
                    // isn't manipulated before resolving this session
                    foreach ($this->session->data as $id => $formData) {
                        if (array_key_exists($id, $this->forms)) {
                            foreach ($this->validations as $model) {
                                if ($model->form == $this->forms[$id]) {
                                    $result = $model->validation->validate($formData->data);

                                    if ( ! $result->isValid()) {
                                        return $result;
                                    }
                                }
                            }
                        }
                    }

                    // Complete callbacks (if they were set)
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

                // If all callbacks were valid cleanup the session
                if ($valid == count($this->completeCallbacks)) {
                    $this->cleanup();
                }
            }
        }

        try {
            return $this->validate();
        }
        catch (Exception\NoValidationFoundException $exception) {}

        return null;
    }

    /**
     * @param array $keys
     * @param callable $callback
     * @return self
     */
    public function filterIfDataContainsKeys($keys, callable $callback)
    {
        $this->sessionDataFilters[] = new Filters\SessionDataKeyFilter($keys, $callback);
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
            throw new \Exception('Formular not found to add constraint on');
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
     * @param string $id
     * @return boolean
     */
    private function clearData($id)
    {
        if (array_key_exists($id, $this->session->data)) {
            unset($this->session->data[$id]);

            $this->storeSession();

            return true;
        }

        return false;
    }

    /**
     * @param string $id
     * @param array $data
     */
    private function saveData($id, array $data)
    {
        // Filter session data
        foreach ($this->sessionDataFilters as $dataFilter) {
            $dataFilter->filter($data);
        }

        // Merge or add data to session
        if (array_key_exists($id, $this->session->data)) {
            $this->session->data[$id]->merge($data);
        } else {
            $this->session->data[$id] = new Models\DataModel($data);
        }

        // Save session to cookie
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
        $_SESSION[$this->session->id] = serialize($this->session);
    }
}
