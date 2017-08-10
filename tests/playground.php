<?php

require realpath(__DIR__ . '/../vendor/autoload.php');

use Nextform\Config\XmlConfig;
use Nextform\Renderer\Renderer;
use Nextform\Session\Session;
use Nextform\Validation\Validation;

$form1 = new XmlConfig('
    <form name="form1" method="GET">
        <input type="text" name="test1" placeholder="Test 1">
            <validation required="true">
                <errors>
                    <required>Fill this out</required>
                </errors>
            </validation>
        </input>
        <collection name="colors">
            <input type="checkbox" name="colors[]" value="blue" />
            <input type="checkbox" name="colors[]" value="green" />
            <input type="checkbox" name="colors[]" value="black" />
            <input type="checkbox" name="colors[]" value="yellow" />
            <validation required="true">
                <modifiers required-min="2" required-max="3" />
                <errors>
                    <required>Please select at least 2 and max. 3 colors</required>
                </errors>
            </validation>
        </collection>
        <input type="submit" value="OK! (1)" />
    </form>
', true);

$form2 = new XmlConfig('
    <form name="form2" method="GET">
        <input type="text" name="test2" placeholder="Test 2">
            <validation required="true">
                <errors>
                    <required>Fill this out</required>
                </errors>
            </validation>
        </input>
        <input type="submit" value="OK! (2)" />
    </form>
', true);

echo '<pre>';

$validator1 = new Validation($form1);
$validator2 = new Validation($form2);

$session = new Session('registration', $form1, $form2);
$session->completeIfFormDataExists($form1, ['test1']);
$session->completeIfFormDataExists($form2, ['test2']);
$session->setValidation($form1, $validator1);
$session->setValidation($form2, $validator2);

// Wait for completion
$session->onComplete(function ($data) {
    print_r($data);
    return true;
});

$session->proccess();

try {
    echo 'Result: ' . $session->validate();
} catch (\Exception $exception) {
}

// Output form
$renderer1 = new Renderer($form1);
$renderer2 = new Renderer($form2);

echo $renderer1->render();
echo $renderer2->render();
