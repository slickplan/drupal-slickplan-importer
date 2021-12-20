<?php

namespace Drupal\slickplan\Form;

use Drupal;
use Drupal\user\Entity\User;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Form\FormStateInterface;
use Drupal\slickplan\Controller\SlickplanController;

class OptionsForm extends FormBase
{
    /**
     * @return string
     */
    public function getFormId()
    {
        return 'options_forms';
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

        $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
            ? $xml['settings']['title']
            : (isset($xml['title']) ? $xml['title'] : '');

        if ($title or (isset($xml['settings']['tagline']) and $xml['settings']['tagline'])) {
            $form['slickplan_importer_website_settings'] = array(
                '#type' => 'fieldset',
                '#title' => 'Website Settings'
            );

            if ($title) {
                $form['slickplan_importer_website_settings']['slickplan_importer_site_title'] = array(
                    '#type' => 'checkbox',
                    '#title' => 'Set website title to „' . $title . '” '
                        . '<div class="description">(It will update the Site Name option in System Configuration)</div>'
                );
            }

            if (isset($xml['settings']['tagline']) and $xml['settings']['tagline']) {
                $form['slickplan_importer_website_settings']['slickplan_importer_slogan'] = array(
                    '#type' => 'checkbox',
                    '#title' => 'Set website slogan to „' . $xml['settings']['tagline'] . '”'
                        . '<div class="description">(It will change the Slogan in System Configuration)</div>'
                );
            }
        }

        // Pages Titles
        $form['slickplan_importer_page_titles'] = array(
            '#type' => 'fieldset',
            '#title' => 'Pages Titles'
        );
        $form['slickplan_importer_page_titles']['slickplan_importer_page_title'] = array(
            '#type' => 'radios',
            '#default_value' => 'no',
            '#options' => array(
                'no' => 'No Change',
                'ucfirst' => 'Make just the first character uppercase <div class="description">(This is an example page title)</div>',
                'ucwords' => 'Uppercase the first character of each word <div class="description">(This Is An Example Page Title)</div>'
            )
        );

        // Pages Settings
        $form['slickplan_importer_pages_settings'] = array(
            '#type' => 'fieldset',
            '#title' => 'Pages Settings'
        );
        $form['slickplan_importer_pages_settings']['slickplan_importer_content'] = array(
            '#type' => 'radios',
            '#default_value' => 'contents',
            '#options' => array(
                'contents' => 'Import page content from Content Planner',
                'desc' => 'Import notes as page content',
                'ucwords' => 'Don’t import any content '
            )
        );

        $no_of_files = 0;
        $filesize_total = array();
        if (isset($xml['pages']) and is_array($xml['pages'])) {
            foreach ($xml['pages'] as $page) {
                if (isset($page['contents']['body']) and is_array($page['contents']['body'])) {
                    foreach ($page['contents']['body'] as $body) {
                        foreach ($this->_getMediaElementArray($body) as $item) {
                            if (isset($item['type']) and $item['type'] === 'library') {
                                ++$no_of_files;
                            }
                            if (isset($item['file_size'], $item['file_id']) and $item['file_size']) {
                                $filesize_total[$item['file_id']] = (int)$item['file_size'];
                            }
                        }
                    }
                }
            }
            $filesize_total = array_sum($filesize_total);
            $size = array(
                'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'
            );
            $factor = (int)floor((strlen($filesize_total) - 1) / 3);
            $filesize_total = round($filesize_total / pow(1024, $factor)) . $size[$factor];
        }
        if ($no_of_files) {
            $form['slickplan_importer_pages_settings']['slickplan_importer_content_files'] = array(
                '#type' => 'checkbox',
                '#title' => 'Import files to website’s library'
                    . '<div class="description">(Downloading files may take a while, approx total size: ' . $filesize_total . ')</div>',
                '#states' => array(
                    'visible' => array(
                        '#edit-slickplan-importer-content input[type="radio"]' => array(
                            'value' => 'contents'
                        )
                    )
                ),
                '#prefix' => '<br>'
            );
        }

        $post_types = NodeType::loadMultiple();
        $array_post_types = array();
        foreach ($post_types as $post_type) {
            $array_post_types[$post_type->get('type')] = $post_type->get('name');
        }
        asort($array_post_types);
        $form['slickplan_importer_pages_settings']['slickplan_importer_post_type'] = array(
            '#type' => 'select',
            '#options' => $array_post_types,
            '#title' => 'Import pages as:',
            '#default_value' => 'page'
        );

        // Users Mapping
        if (isset($xml['users']) && is_array($xml['users']) && count($xml['users'])) {
            $users = User::loadMultiple();
            $array_users = array();
            foreach ($users as $user_data) {
                if ($user_data->id() > 0 and $user_data->getDisplayName()) {
                    $array_users[$user_data->id()] = $user_data->getDisplayName();
                }
            }

            asort($array_users);
            unset($users);

            $form['slickplan_importer_users_map'] = array(
                '#type' => 'fieldset',
                '#title' => 'Users Mapping'
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
                    '#default_value' => Drupal::currentUser()->id(),
                    '#options' => $array_users,
                    '#field_prefix' => implode(' ', $name) . ': '
                );
            }
        }

        $form['submit'] = array(
            '#type' => 'submit',
            '#value' => 'Submit'
        );

        return $form;
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $xml = Drupal::state()->get('slickplan_importer');

        $this->_checkRequiredData($xml, 'options');

        $form_data = $form_state->getValues();

        if (isset($form_data['slickplan_importer_site_title']) and $form_data['slickplan_importer_site_title']) {
            $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
                ? $xml['settings']['title']
                : (isset($xml['title']) ? $xml['title'] : '');
            if ($title) {
                Drupal::configFactory()->getEditable('system.site')->set('name', $title)->save();
            }
        }

        if (
            isset($form_data['slickplan_importer_slogan'], $xml['settings']['tagline'])
            and $form_data['slickplan_importer_slogan'] and $xml['settings']['tagline']
        ) {
            Drupal::configFactory()->getEditable('system.site')->set('slogan', $xml['settings']['tagline'])->save();
        }

        $xml['import_options'] = array(
            'titles' => isset($form_data['slickplan_importer_page_title']) ? $form_data['slickplan_importer_page_title'] : '',
            'content' => isset($form_data['slickplan_importer_content']) ? $form_data['slickplan_importer_content'] : '',
            'content_files' => (
                isset($form_data['slickplan_importer_content'], $form_data['slickplan_importer_content_files'])
                and $form_data['slickplan_importer_content'] === 'contents'
                and $form_data['slickplan_importer_content_files']
            ),
            'post_type' => isset($form_data['slickplan_importer_post_type']) ? $form_data['slickplan_importer_post_type'] : '',
            'users' => array(),
            'internal_links' => array(),
            'imported_pages' => array()
        );
        foreach ($xml['users'] as $user_id => $user_data) {
            if (isset($form_data['slickplan_importer_users_' . $user_id]) and $form_data['slickplan_importer_users_' . $user_id]) {
                $xml['import_options']['users'] = $form_data['slickplan_importer_users_' . $user_id];
            }
        }

        $slickplan = new SlickplanController();
        $slickplan->options = $xml['import_options'];
        $slickplan->addMenu();

        if ($xml['import_options']['content_files']) {
            Drupal::state()->set('slickplan_importer', $xml);
            Drupal::state()->resetCache();
            $form_state->setRedirect('slickplan.ajax_importer');
        } else {
            foreach (array('home', '1', 'util', 'foot') as $type) {
                if (isset($xml['sitemap'][$type]) and is_array($xml['sitemap'][$type])) {
                    $slickplan->importPages($xml['sitemap'][$type], $xml['pages']);
                }
            }
            $slickplan->checkForInternalLinks();
            $xml['summary'] = $slickplan->summary;
            Drupal::state()->set('slickplan_importer', $xml);
            Drupal::state()->resetCache();

            $cache = Drupal::cache('menu');
            $cache->deleteAll();

            $form_state->setRedirect('slickplan.summary');
        }
    }

    /**
     * @param array $element
     * @return array
     */
    protected function _getMediaElementArray(array $element): array
    {
        $items = $element['content']['contentelement'] ?? $element['content'];
        return isset($items['type'])
            ? [$items]
            : (isset($items[0]['type']) ? $items : []);
    }
}
