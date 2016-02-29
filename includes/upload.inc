<?php defined('DRUPAL_ROOT') or exit('No direct script access');

/**
 * Form constructor for displaying upload form.
 *
 * @return array
 */
function slickplan_importer_upload_form(array $form)
{
    $form['slickplan_importer'] = array(
        '#type' => 'fieldset',
        '#title' => 'Slickplan Importer',
        '#description' => '<p>The Slickplan Importer plugin allows you to quickly import your '
            . '<a href="http://slickplan.com" target="_blank">Slickplan</a> projects into your Drupal site.</p>'
            . '<p>Upon import, your pages, navigation structure, and content will be instantly ready in your CMS.</p>'
            . '<p>Pick a XML file to upload and click Import.</p>',
        'slickplan_importer_xml_file' => array(
            '#title' => 'File',
            '#type' => 'file',
        ),
    );

    $form['submit'] = array(
        '#type' => 'submit',
        '#value' => 'Upload',
    );

    return $form;
}

/**
 * Form submission handler for slickplan_importer_upload_form().
 *
 * @return bool
 */
function slickplan_importer_upload_form_submit()
{
    if (
        isset($_FILES['files']['tmp_name']['slickplan_importer_xml_file'])
        and is_file($_FILES['files']['tmp_name']['slickplan_importer_xml_file'])
    ) {
        $xml = file_get_contents($_FILES['files']['tmp_name']['slickplan_importer_xml_file']);
        @unlink($_FILES['files']['tmp_name']['slickplan_importer_xml_file']);
        try {
            $slickplan = new Slickplan_Importer;
            $xml = $slickplan->parseSlickplanXml($xml);
            variable_set('slickplan_importer', $xml);
        } catch (Exception $e) {
            form_set_error('slickplan_importer_xml_file', $e->getMessage());
            return false;
        }
    }
    else {
        if (isset($_FILES['files']['error']['slickplan_importer_xml_file']) and $_FILES['files']['error']['slickplan_importer_xml_file'] > 0) {
            $upload_error_strings = array(
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.',
            );
            if (isset($upload_error_strings[$_FILES['files']['error']['slickplan_importer_xml_file']])) {
                form_set_error('slickplan_importer_xml_file', $upload_error_strings[$_FILES['files']['error']['slickplan_importer_xml_file']]);
                return false;
            }
        }
        form_set_error('slickplan_importer_xml_file', 'No file was uploaded.');
        return false;
    }
    drupal_goto('admin/config/content/slickplan_importer/options');
    return true;
}