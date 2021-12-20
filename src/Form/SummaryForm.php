<?php
namespace Drupal\slickplan\Form;

use Drupal;
use Drupal\Core\Form\FormStateInterface;
use Drupal\slickplan\Controller\SlickplanController;

class SummaryForm extends FormBase
{
    /**
     * @return string
     */
    public function getFormId()
    {
        return 'summary_forms';
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return array
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $xml = Drupal::state()->get('slickplan_importer');

        $this->_checkRequiredData($xml, 'options');

        $form['page_header'] = array(
            '#markup' => '<h2>Success!</h2>'
        );

        $form['message'] = array(
            '#markup' => '<p>Pages have been imported. Thank you for using '
                . '<a href="http://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>'
        );

        if (isset($xml['summary']) and is_array($xml['summary'])) {
            $html = '';
            $slickplan = new SlickplanController();
            foreach ($xml['summary'] as $page) {
                $html .= $slickplan->getSummaryRow($page);
            }
            if ($html) {
                $form['summary'] = array(
                    '#markup' => '<p><hr /></p>' . $html . '<p><hr /></p>',
                    '#attached' => [
                        'library' => 'slickplan/awesome'
                    ]
                );
            }
        }

        $form['submit'] = array(
            '#type' => 'link',
            '#href' => 'admin/content',
            '#title' => 'See all pages'
        );

        Drupal::state()->delete('slickplan_importer');

        return $form;
    }
}


