<?php defined('DRUPAL_ROOT') or exit('No direct script access');

/**
 * Form constructor for displaying options form.
 *
 * @param $form
 *
 * @return array
 */
function slickplan_importer_options_form(array $form)
{
    global $user;

    $xml = variable_get('slickplan_importer', array());

    _slickplan_check_required_data($xml, 'options');

    $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
        ? $xml['settings']['title']
        : (isset($xml['title']) ? $xml['title'] : '');

    // Website Settings
    if (
        $title
        or (isset($xml['settings']['tagline']) and $xml['settings']['tagline'])
    ) {
        $form['slickplan_importer_website_settings'] = array(
            '#type' => 'fieldset',
            '#title' => 'Website Settings',
        );

        if ($title) {
            $form['slickplan_importer_website_settings']['slickplan_importer_site_title'] = array(
                '#type' => 'checkbox',
                '#title' => 'Set website title to „' . $title . '” '
                    . '<div class="description">(It will change the Site Name in System Configuration)</div>',
            );
        }

        if (isset($xml['settings']['tagline']) and $xml['settings']['tagline']) {
            $form['slickplan_importer_website_settings']['slickplan_importer_slogan'] = array(
                '#type' => 'checkbox',
                '#title' => 'Set website slogan to „' . $xml['settings']['tagline'] . '”'
                    . '<div class="description">(It will change the Slogan in System Configuration)</div>',
            );
        }
    }

    // Pages Titles
    $form['slickplan_importer_page_titles'] = array(
        '#type' => 'fieldset',
        '#title' => 'Pages Titles',
    );
    $form['slickplan_importer_page_titles']['slickplan_importer_page_title'] = array(
        '#type' => 'radios',
        '#default_value' => 'no',
        '#options' => array(
            'no' => 'No Change',
            'ucfirst' => 'Make just the first character uppercase '
                . '<div class="description">(This is an example page title)</div>',
            'ucwords' => 'Uppercase the first character of each word '
                . '<div class="description">(This Is An Example Page Title)</div>',
        ),
    );

    // Pages Settings
    $form['slickplan_importer_pages_settings'] = array(
        '#type' => 'fieldset',
        '#title' => 'Pages Settings',
    );
    $form['slickplan_importer_pages_settings']['slickplan_importer_content'] = array(
        '#type' => 'radios',
        '#default_value' => 'contents',
        '#options' => array(
            'contents' => 'Import page content',
            'desc' => 'Import page notes as content',
            'ucwords' => 'Don’t import any content ',
        ),
    );
    $form['slickplan_importer_pages_settings']['slickplan_importer_content_files'] = array(
        '#type' => 'checkbox',
        '#title' => 'Import files to website’s library'
            . '<div class="description">(Downloading files may take a while)</div>',
        '#states' => array(
            'visible' => array(
                '#edit-slickplan-importer-content input[type="radio"]' => array(
                    'value' => 'contents',
                ),
            ),
        ),
        '#prefix' => '<br>',
    );
    $post_types = node_type_get_types();
    $array_post_types = array();
    foreach ($post_types as $post_type) {
        $array_post_types[$post_type->type] = $post_type->name;
    }
    asort($array_post_types);
    $form['slickplan_importer_pages_settings']['slickplan_importer_post_type'] = array(
        '#type' => 'select',
        '#options' => $array_post_types,
        '#title' => 'Import pages as:',
        '#default_value' => 'page',
    );

    // Users Mapping
    if (isset($xml['users']) and is_array($xml['users']) and count($xml['users'])) {
        $users = entity_load('user');
        $array_users = array();
        foreach ($users as $user_data) {
            if ($user_data->uid > 0 and $user_data->name) {
                $array_users[$user_data->uid] = $user_data->name;
            }
        }
        asort($array_users);
        unset($users);
        $form['slickplan_importer_users_map'] = array(
            '#type' => 'fieldset',
            '#title' => 'Users Mapping',
        );
        foreach ($xml['users'] as $user_id => $user_data) {
            $name = array();
            if (isset($user_data['firstName']) and $user_data['firstName']) {
                $name[] = $user_data['firstName'];
            }
            if (isset($user_data['lastName']) and $user_data['lastName']) {
                $name[] = $user_data['lastName'];
            }
            if (isset($user_data['email']) and $user_data['email']) {
                if (count($name)) {
                    $user_data['email'] = '(' . $user_data['email'] . ')';
                }
                $name[] = $user_data['email'];
            }
            if (!count($name)) {
                $name[] = $user_id;
            }
            $form['slickplan_importer_users_map']['slickplan_importer_users_' . $user_id] = array(
                '#type' => 'select',
                '#default_value' => $user->uid,
                '#options' => $array_users,
                '#field_prefix' => implode(' ', $name) . ': ',
            );
        }
    }

    $form['submit'] = array(
        '#type' => 'submit',
        '#value' => 'Import',
    );

    return $form;
}

/**
 * Form submission handler for slickplan_importer_options_form().
 *
 * @param array $form
 * @param array $form_data
 */
function slickplan_importer_options_form_submit(array $form, array &$form_data)
{
    $xml = variable_get('slickplan_importer', array());

    _slickplan_check_required_data($xml, 'options');

    if (
        isset($form_data['values']['slickplan_importer_site_title'])
        and $form_data['values']['slickplan_importer_site_title']
    ) {
        $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
            ? $xml['settings']['title']
            : (isset($xml['title']) ? $xml['title'] : '');
        variable_set('site_name', $title);
    }

    if (
        isset($form_data['values']['slickplan_importer_slogan'], $xml['settings']['tagline'])
        and $form_data['values']['slickplan_importer_slogan']
        and $xml['settings']['tagline']
    ) {
        variable_set('site_slogan', $xml['settings']['tagline']);
    }

    $xml['import_options'] = array(
        'titles' => isset($form_data['values']['slickplan_importer_page_title'])
            ? $form_data['values']['slickplan_importer_page_title']
            : '',
        'content' => isset($form_data['values']['slickplan_importer_content'])
            ? $form_data['values']['slickplan_importer_content']
            : '',
        'content_files' => (
            isset($form_data['values']['slickplan_importer_content_files'])
            and $form_data['values']['slickplan_importer_content_files']
        ),
        'post_type' => isset($form_data['values']['slickplan_importer_post_type'])
            ? $form_data['values']['slickplan_importer_post_type']
            : '',
        'users' => array(),
    );
    foreach ($xml['users'] as $user_id => $user_data) {
        if (
            isset($form_data['values']['slickplan_importer_users_' . $user_id])
            and $form_data['values']['slickplan_importer_users_' . $user_id]
        ) {
            $xml['import_options']['users'] = $form_data['values']['slickplan_importer_users_' . $user_id];
        }
    }

    $slickplan = new Slickplan_Importer;
    $slickplan->options = $xml['import_options'];
    $slickplan->addMenu();

    if (
        isset($form_data['values']['slickplan_importer_content_files'])
        and $form_data['values']['slickplan_importer_content_files'] === 1
    ) {
        // Import with progress bars
        variable_set('slickplan_importer', $xml);
        drupal_goto('admin/config/content/slickplan_importer/ajax_importer');
    }
    else {
        // Import with no progress bars
        foreach (array('home', '1', 'util', 'foot') as $type) {
            if (isset($xml['sitemap'][$type]) and is_array($xml['sitemap'][$type])) {
                $slickplan->importPages($xml['sitemap'][$type], $xml['pages']);
            }
        }
        $xml['summary'] = $slickplan->summary;
        variable_set('slickplan_importer', $xml);
        drupal_goto('admin/config/content/slickplan_importer/summary');
    }
}