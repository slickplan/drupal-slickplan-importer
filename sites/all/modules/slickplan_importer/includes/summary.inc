<?php defined('DRUPAL_ROOT') or exit('No direct script access');

/**
 * Display the summary of imported pages.
 *
 * @param array $form
 *
 * @return array
 */
function slickplan_importer_summary_form(array $form)
{
    $xml = variable_get('slickplan_importer', array());

    _slickplan_check_required_data($xml, 'summary');

    drupal_add_css('//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css', array(
        'type' => 'external',
    ));

    $form['page_header'] = array(
        '#markup' => '<h2>Success!</h2>',
    );

    $form['message'] = array(
        '#markup' => '<p>Pages have been imported. Thank you for using '
            . '<a href="http://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>',
    );

    if (isset($xml['summary']) and is_array($xml['summary'])) {
        $html = '';
        $slickplan = new Slickplan_Importer;
        foreach ($xml['summary'] as $page) {
            $html .= $slickplan->getSummaryRow($page);
        }
        if ($html) {
            $form['summary'] = array(
                '#markup' => '<p><hr /></p>' . $html . '<p><hr /></p>',
            );
        }
    }

    $form['submit'] = array(
        '#type' => 'link',
        '#href' => 'admin/content',
        '#title' => 'See all pages',
    );

    variable_set('slickplan_importer', array());

    return $form;
}