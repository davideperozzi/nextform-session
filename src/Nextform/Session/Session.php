<?php

namespace Nextform\Session;

use Nextform\Config\AbstractConfig;
use Nextform\Config\Signature;
use Nextform\Fields\InputField;
use Nextform\FileHandler\FileHandler;
use Nextform\Validation\Validation;

class Session
{
    /**
     * @var string
     */
    const ID_PREFIX = 'nextform_';

    /**
     * @var string
     *
     * MD5 hash of nextform_session_id
     */
    const SESSION_FIELD_NAME = '_73d39f2e64de879f0876fdaec6c96a16';

    /**
     * @var string
     */
    const SESSION_FIELD_SEPERATOR = ';';

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
    private $beforeSubmitCallbacks = [];

    /**
     * @var array
     */
    private $onSubmitCallbacks = [];

    /**
     * @var array
     */
    private $completeCallbacks = [];

    /**
     * @var array
     */
    private $beforeCompleteCallbacks = [];

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
     * @var boolean
     */
    private $separatedFileUploads = false;

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
                    Start it manually and make sure no header was
                    sent before the session is going to be started'
                );
            }
        }

        foreach ($forms as $i =>  $form) {
            $signature = Signature::get($form);

            if (array_key_exists($signature, $this->forms)) {
                throw new Exception\FormularAlreadyInSessionException(
                    sprintf('Formular "%s" was already added to this session', $signature)
                );
            }

            $this->forms[$signature] = $form;
        }

        $this->id = self::createId($name);
        $this->session = $this->restoreSession();

        // Add form fields to each form
        foreach ($this->forms as $form) {
            static::addSessionFields($name, $form);
        }

        // Add filter to remove name field from data before saving
        $this->filterIfDataContainsKeys([self::SESSION_FIELD_NAME], function (&$data) {
            unset($data[self::SESSION_FIELD_NAME]);
        });
    }

    /**
     * @param string $id
     * @param AbstractConfig &$form
     */
    public static function addSessionFields($id, AbstractConfig &$form)
    {
        $value = self::createId($id);
        $value .= self::SESSION_FIELD_SEPERATOR;
        $value .= Signature::get($form);

        $idField = new InputField();
        $idField->setAttribute('name', self::SESSION_FIELD_NAME);
        $idField->setAttribute('value', $value);
        $idField->setAttribute('hidden', '');
        $idField->setGhost(true);

        $form->addField($idField);
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

        $this->updateValidationType();
    }

    /**
     * @param array &$fileHandlers
     */
    public function addFileHandler(FileHandler &...$fileHandlers)
    {
        foreach ($fileHandlers as &$fileHandler) {
            $form = $fileHandler->getForm();
            $this->fileHandlers[] = new Models\FileHandlerModel($form, $fileHandler);

            $this->beforeSubmit($form, function (&$data) use (&$fileHandler, &$form) {
                if ($fileHandler->isActive($data)) {
                    $this->setValidationType(
                        Validation::TYPE_ONLY_FILE_VALIDATION,
                        $form
                    );
                }
            });

            $this->onSubmit($form, function (&$data, &$result) use (&$fileHandler, &$form) {
                if ($fileHandler->isActive($data)) {
                    $this->session->submittedFileForms[] = Signature::get($form);

                    $fileHandler->handle($data, function ($name, $error) use (&$result, &$fileHandler) {
                        $result->addError($fileHandler->getErrorMessage($error));
                    });
                }
            });

            $this->beforeComplete(function (&$data, &$result) use (&$fileHandler, &$form) {
                $signature = Signature::get($form);

                if ( ! in_array($signature, $this->session->submittedFileForms)) {
                    $result->setComplete(false);
                }
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
    public function beforeComplete(callable $callback)
    {
        $this->beforeCompleteCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param AbstractConfig &$form
     * @param callable $callback
     * @return self
     */
    public function onSubmit(&$form, callable $callback)
    {
        $this->onSubmitCallbacks[] = new Models\SubmitCallbackModel($form, $callback);

        return $this;
    }

    /**
     * @param AbstractConfig &$form
     * @param callable $callback
     * @return self
     */
    public function beforeSubmit(&$form, callable $callback)
    {
        $this->beforeSubmitCallbacks[] = new Models\SubmitCallbackModel($form, $callback);

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

        if ($this->isSessionActive($data)) {
            list($sessionId, $signature) = $this->getNameIdParts($data);

            if ($sessionId == $this->session->id) {
                if (array_key_exists($signature, $this->forms)) {
                    if ($signature == Signature::get($form)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array $data
     * @param array
     * @return Nextform\Validation\Models\ResultModel|Models\ResultModel|null
     */
    public function process($mergeData = [])
    {
        $data = $this->getData($mergeData);

        if ($this->isSessionActive($data)) {
            list($sessionId, $formId) = $this->getNameIdParts($data);
            if ($sessionId == $this->session->id) {
                if (array_key_exists($formId, $this->forms)) {
                    $result = $this->processForm($data, $formId);

                    if ( ! $result->isValid()) {
                        return $result;
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

                    $result = new Models\ResultModel();
                    $result->setComplete(true);

                    // Transform data
                    $combinedData = [];

                    foreach ($this->session->data as $id => $values) {
                        if (array_key_exists($id, $this->forms)) {
                            $root = $this->forms[$id]->getFields()->getRoot();
                            $combinedData[$root->id] = $values;
                        }
                    }

                    // Complete callbacks (if they were set)
                    foreach ($this->beforeCompleteCallbacks as $callback) {
                        $callback($combinedData, $result);
                    }

                    // Complete callbacks (if they were set)
                    if ($result->isComplete()) {
                        $result->lockComplete(true);

                        foreach ($this->completeCallbacks as $callback) {
                            $callback($combinedData, $result);
                        }

                        // If the result is valid cleanup the session
                        if ($result->isValid()) {
                            $this->destroy($result->isRemoveFiles());
                        }
                    }

                    return $result;
                }
            }
        }

        try {
            return $this->validate();
        } catch (Exception\NoValidationFoundException $exception) {
        }

        return null;
    }

    /**
     * @param array &$data
     * @param string $formId
     * @return Models\ResultModel
     */
    private function processForm(array &$data, $formId)
    {
        $form = $this->forms[$formId];
        $result = new Models\ResultModel();

        if ($form->isCsrfTokenEnabled() && ! $form->checkCsrfToken($data)) {
            // Prevent any further output and
            // set repsonse header status to 500
            http_response_code(500);
            exit;
        }

        $beforeSubmitModels = $this->getSubmitCallbacks(
            $this->beforeSubmitCallbacks,
            $form
        );

        foreach ($beforeSubmitModels as $model) {
            $callback = $model->callback;
            $callback($data);
        }

        $onSubmitModels = $this->getSubmitCallbacks(
            $this->onSubmitCallbacks,
            $form
        );

        if (count($onSubmitModels) > 0) {
            foreach ($onSubmitModels as $model) {
                $callback = $model->callback;
                $callback($data, $result);
            }

            if ($result->isValid()) {
                $this->saveData($formId, $data);
            } else {
                $this->clearData($formId);
            }
        } else {
            $this->saveData($formId, $data);
        }

        return $result;
    }

    /**
     * @param boolean $active
     */
    public function enableSeparatedFileUploads($active)
    {
        $this->separatedFileUploads = $active;
        $this->updateValidationType();
    }

    /**
     * @param boolean $enbale
     * @return
     */
    public function enableCsrfToken($enable = true)
    {
        foreach ($this->forms as $form) {
            $form->enableCsrfToken($enable);
        }
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
     * @param string $slug
     * @return string
     */
    private static function createId($slug)
    {
        return self::ID_PREFIX . $slug;
    }

    /**
     * @param AbstractConfig $form
     * @return FileHandler
     */
    private function getFileHandler(AbstractConfig &$form)
    {
        foreach ($this->fileHandlers as &$model) {
            $sig1 = Signature::get($model->form);
            $sig2 = Signature::get($form);

            if ($sig1 == $sig2) {
                return $model->fileHandler;
            }
        }

        return null;
    }

    /**
     * @param boolean $removeFiles
     */
    public function destroy($removeFiles = false)
    {
        if (true == $removeFiles) {
            foreach ($this->session->data as $formId => $model) {
                if (array_key_exists($formId, $this->forms)) {
                    $fileHandler = $this->getFileHandler($this->forms[$formId]);

                    if ( ! is_null($fileHandler)) {
                        $fileHandler->removeFilesByData($model->data);
                    }
                }
            }
        }

        if (array_key_exists($this->session->id, $_SESSION)) {
            unset($_SESSION[$this->session->id]);
        }
    }

    private function updateValidationType()
    {
        if (true == $this->separatedFileUploads) {
            $this->setValidationType(Validation::TYPE_EXCLUDE_FILE_VALIDATION);
        } else {
            $this->setValidationType(Validation::TYPE_DEFAULT);
        }
    }

    /**
     * @param number $type
     * @param AbstractConfig $form
     */
    private function setValidationType($type, AbstractConfig $form = null)
    {
        foreach ($this->validations as $model) {
            if ( ! is_null($form) && $form instanceof $form) {
                $sig1 = Signature::get($model->form);
                $sig2 = Signature::get($form);

                if ($sig1 != $sig2) {
                    continue;
                }
            }

            $model->validation->setType($type);
        }
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
     * @return array
     */
    private function getNameIdParts($data)
    {
        return explode(self::SESSION_FIELD_SEPERATOR, $data[self::SESSION_FIELD_NAME]);
    }

    /**
     * @param array $data
     * @return boolean
     */
    private function isSessionActive($data)
    {
        return array_key_exists(self::SESSION_FIELD_NAME, $data);
    }

    /**
     * @param array $models
     * @param AbstractConfig $form
     * @return Models\SubmitCallbackModel
     */
    private function getSubmitCallbacks(array $models, AbstractConfig &$form)
    {
        $result = [];

        foreach ($models as $model) {
            $sig1 = Signature::get($model->form);
            $sig2 = Signature::get($form);

            if ($sig1 == $sig2) {
                $result[] = $model;
            }
        }

        return $result;
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

    private function storeSession()
    {
        $_SESSION[$this->session->id] = serialize($this->session);
    }
}
