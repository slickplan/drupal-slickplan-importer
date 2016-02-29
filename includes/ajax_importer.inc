<?php defined('DRUPAL_ROOT') or exit('No direct script access');

/**
 * AJAX pages importer, better when there are files to download.
 *
 * @param array $form
 *
 * @return array
 */
function slickplan_importer_ajax_importer_form(array $form)
{
    $xml = variable_get('slickplan_importer');

    _slickplan_check_required_data($xml, 'ajax_importer');

    drupal_add_css('//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css', array(
        'type' => 'external',
    ));

    $slickplan = new Slickplan_Importer;

    $path = drupal_get_path('module', 'slickplan_importer');
    drupal_add_js(
        'window.SLICKPLAN_JSON = ' . json_encode($xml['sitemap']) . ';'
        ."\n"
        . 'window.SLICKPLAN_HTML = "' . str_replace('"', '\"', $slickplan->getSummaryRow(array(
            'title' => '{title}',
            'loading' => 1,
        )))
        . '";'
    ,'inline');
    drupal_add_js($path . '/js/ajax_importer.js');

    $form['page_header'] = array(
        '#markup' => '<h2>Importing Pages&hellip;</h2>',
    );

    $form['message'] = array(
        '#markup' => '<p style="display: none" class="slickplan-show-summary">Pages have been imported. Thank you for using '
            . '<a href="http://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>',
    );

    $form['progress'] = array(
        '#markup' => theme_progress_bar(array(
            'percent' => 0,
            'message' => '',
        )),
    );

    $form['summary'] = array(
        '#markup' => '<p><hr /></p><div class="slickplan-summary"></div><p><hr /></p>',
    );

    $form['submit'] = array(
        '#type' => 'link',
        '#href' => 'admin/content',
        '#title' => 'See all pages',
        '#attributes' => array(
            'style' => 'display: none',
            'class' => 'slickplan-show-summary',
        ),
    );

    return $form;
}

/**
 * Add a page, ajax action
 */
function slickplan_importer_ajax_importer_post()
{
    set_time_limit(180);
    $result = array(
        'error' => 'Unknown Error',
    );
    if (isset($_POST['slickplan']) and is_array($_POST['slickplan'])) {
        $form = $_POST['slickplan'];
        $xml = variable_get('slickplan_importer', array());
        $slickplan = new Slickplan_Importer;
        $slickplan->options = $xml['import_options'];
        if (isset($xml['pages'][$form['page']]) and is_array($xml['pages'][$form['page']])) {
            $mlid = (isset($form['mlid']) and $form['mlid'])
                ? $form['mlid']
                : 0;
            $result = $slickplan->importPage($xml['pages'][$form['page']], $mlid);
            $result['html'] = $slickplan->getSummaryRow($result);
        }
        if (isset($form['last']) and $form['last']) {
            $result['last'] = $form['last'];
            $slickplan->checkForInternalLinks();
            variable_set('slickplan_importer', array());
        }
        else {
            $xml['import_options'] = $slickplan->options;
            variable_set('slickplan_importer', $xml);
        }
    }
    drupal_json_output($result);
    exit;
}