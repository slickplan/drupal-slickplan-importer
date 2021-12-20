<?php
namespace Drupal\slickplan\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase as DrupalFormBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

abstract class FormBase extends DrupalFormBase
{
    /**
     * @param array $form
     * @param FormStateInterface $form_state
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // No actions by default
    }

    /**
     * Check if required data exists, if not redirect to upload form.
     *
     * @param $xml
     * @param string $page
     * @return RedirectResponse
     */
    protected function _checkRequiredData($xml, $page = '')
    {
        if (
            ($page === 'options' and !isset($xml['sitemap']))
            or ($page === 'summary' and !isset($xml['summary']))
            or ($page === 'ajax_importer' and (!isset($xml['sitemap']) or isset($xml['summary'])))
        ) {
            return new RedirectResponse(Url::fromRoute('slickplan.upload')->toString());
        }
    }
}
