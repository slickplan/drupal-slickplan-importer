<?php

namespace Drupal\slickplan\Form;

use Drupal;
use Exception;
use Drupal\file\Entity\File;
use Drupal\Core\Form\FormStateInterface;
use Drupal\slickplan\Controller\SlickplanController;

class UploadForm extends FormBase
{
    /**
     * @return string
     */
    public function getFormId()
    {
        return 'upload_file_forms';
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return array
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $file_validator = array(
            'file_validate_extensions' => array(
                'xml',
            ),
            'file_validate_size' => array(
                file_upload_max_size(),
            )
        );

        $form['slickplan'] = array(
            '#type' => 'managed_file',
            '#upload_location' => 'public://upload/slickplan',
            '#title' => 'Slickplan Importer',
            '#required' => true,
            '#description' => '<p>The Slickplan Importer plugin allows you to quickly import your '
                . '<a href="http://slickplan.com" target="_blank">Slickplan</a> projects into your Drupal site.</p>'
                . '<p>Upon import, your pages, navigation structure, and content will be instantly ready in your CMS.</p>'
                . '<p>Pick a XML file to upload and click Import.</p>',
            '#upload_validators' => $file_validator,
        );

        $form['submit'] = array(
            '#type' => 'submit',
            '#value' => 'Submit'
        );

        return $form;
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return bool
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $xml = $form_state->getValue('slickplan');
        $file = File::load($xml[0]);
        $filePath = $file->getFileUri();
        $data = file_get_contents($filePath);
        unset($filePath);

        if (isset($data) and !empty($data)) {
            try {
                $slickplan = new SlickplanController();

                $data = $slickplan->parseSlickplanXml($data);
                Drupal::state()->set('slickplan_importer', $data);
                Drupal::state()->resetCache();

                $form_state->setRedirect('slickplan.options');
            } catch (Exception $ex) {
                drupal_set_message($ex->getMessage(), 'error');
                return false;
            }
        }
    }
}


