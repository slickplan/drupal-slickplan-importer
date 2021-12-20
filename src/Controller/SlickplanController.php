<?php
namespace Drupal\slickplan\Controller;

use Drupal;
use Exception;
use DOMDocument;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\system\Entity\Menu;
use Drupal\menu_link_content\Entity\MenuLinkContent;

class SlickplanController
{
    /**
     * Import options
     *
     * @var array
     */
    public $options = array(
        'titles' => '',
        'content' => '',
        'post_type' => '',
        'content_files' => false,
        'users' => array(),
        'internal_links' => array(),
        'imported_pages' => array()
    );

    /**
     * Import results
     *
     * @var array
     */
    public $summary = array();

    /**
     * Array of imported files
     *
     * @var array
     */
    private $_files = array();

    /**
     * If page has unparsed internal pages
     *
     * @var bool
     */
    private $_has_unparsed_internal_links = false;

    /**
     * Parse Slickplan's XML file.
     * Converts an XML DOMDocument to an array.
     *
     * @param $input_xml
     * @return array
     * @throws Exception
     */
    public function parseSlickplanXml($input_xml)
    {
        $input_xml = trim($input_xml);
        if (substr($input_xml, 0, 5) === '<?xml') {
            $xml = new DomDocument('1.0', 'UTF-8');
            $xml->xmlStandalone = false;
            $xml->formatOutput = true;
            $xml->loadXML($input_xml);
            if (isset($xml->documentElement->tagName) and $xml->documentElement->tagName === 'sitemap') {
                $array = $this->_parseSlickplanXmlNode($xml->documentElement);
                if ($this->_isCorrectSlickplanXmlFile($array)) {
                    if (isset($array['diagram'])) {
                        unset($array['diagram']);
                    }
                    if (isset($array['section']['options'])) {
                        $array['section'] = array(
                            $array['section']
                        );
                    }
                    $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                    $array['users'] = array();
                    $array['pages'] = array();
                    foreach ($array['section'] as $section_key => $section) {
                        if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                                if (
                                    isset($section['options']['id'], $cell['level'])
                                    and $cell['level'] === 'home'
                                    and $section['options']['id'] !== 'svgmainsection'
                                ) {
                                    unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                                }
                                if (isset($cell['contents']['assignee']['@value'], $cell['contents']['assignee']['@attributes'])) {
                                    $array['users'][$cell['contents']['assignee']['@value']] = $cell['contents']['assignee']['@attributes'];
                                }
                                if (isset($cell['@attributes']['id'])) {
                                    $array['pages'][$cell['@attributes']['id']] = $cell;
                                }
                            }
                        }
                    }
                    unset($array['section']);
                    return $array;
                }
            }
        }
        throw new Exception('Invalid file format.');
    }

    /**
     * Add a file to Media Library from URL
     *
     * @param $url
     * @param array $attrs Assoc array of attributes [title, alt, description, file_name]
     * @return bool|string
     */
    public function addMedia($url, array $attrs = array())
    {
        if (!$this->options['content_files']) {
            return false;
        }

        if (!isset($attrs['file_name']) or !$attrs['file_name']) {
            $fileUrl = parse_url($url);
            $attrs['file_name'] = basename($fileUrl['path']);
        }

        $result = array(
            'filename' => $attrs['file_name']
        );

        try {
            $response = Drupal::httpClient()->get($url);
            $fileData = (string) $response->getBody();
            if ($fileData) {
                $directory = 'public://slickplan/';
                $fs = Drupal::service('file_system');
                $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
                $localFile = file_save_data($fileData, $directory . $result['filename']);
                if ($localFile and $localFile->getFileUri() and $localFile->getFileUri() != null) {
                    $url = file_create_url($localFile->getFileUri());
                    if ($url) {
                        $result['url'] = $url;
                        $this->_files[] = $result;
                        return $url;
                    }
                    throw new Exception('File URL creation failed');
                }
                throw new Exception('File saving failed');
            }
            throw new Exception('File download failed');
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        $this->_files[] = $result;
        return false;
    }

    /**
     * Import pages into Drupal.
     *
     * @param array $structure
     * @param array $pages
     * @param int $parent_id
     */
    public function importPages(array $structure, array $pages, $parent_id = 0)
    {
        foreach ($structure as $page) {
            if (isset($page['id'], $pages[$page['id']])) {
                $result = $this->importPage($pages[$page['id']], $parent_id);
                if (isset($page['childs'], $result['mlid']) and $result['mlid'] and is_array($page['childs']) and count($page['childs'])) {
                    $this->importPages($page['childs'], $pages, $result['mlid']);
                }
            }
        }
    }

    /**
     * Import single page into Drupal.
     *
     * @param array $data
     * @param int $parent_id
     * @return array
     */
    public function importPage(array $data, $parent_id = 0)
    {
        $this->_files = array();

        $post_title = $this->_getFormattedTitle($data);

        $node = Node::create([
            'type' => $this->options['post_type'],
            'title' => $post_title
        ]);

        // Set post author
        if (isset($data['contents']['assignee']['@value'], $this->options['users'][$data['contents']['assignee']['@value']])) {
            $node->set('uid', $this->options['users'][$data['contents']['assignee']['@value']]);
        } else {
            $node->set('uid', Drupal::currentUser()->id());
        }

        // Set post status
        if (isset($data['contents']['status']) and $data['contents']['status'] === 'draft') {
            $node->set('status', 0);
        }

        $article_value = null;

        $this->_has_unparsed_internal_links = false;

        // Set post content
        if ($this->options['content'] === 'desc') {
            if (isset($data['desc']) and !empty($data['desc'])) {
                $this->_parseInternalLinks($data['desc']);
                $node->set('body', array(
                    'summary' => '',
                    'value' => $data['desc'],
                    'format' => 'full_html'
                ));
            }
        } elseif ($this->options['content'] === 'contents') {
            if (isset($data['contents']['body']) and is_array($data['contents']['body']) and count($data['contents']['body'])) {
                $article_value = $this->_getFormattedContent($data['contents']['body']);
                $this->_parseInternalLinks($article_value);
                $node->set('body', array(
                    'summary' => '',
                    'value' => $article_value,
                    'format' => 'full_html'
                ));
            }
        }

        $node->set('uid', Drupal::currentUser()->id());

        $pathService = version_compare(Drupal::VERSION, '9.0.0', '>=')
            ? 'path_alias.manager'
            : 'path.alias_manager';

        try {
            $node->save();

            $alias = Drupal::service($pathService)->getAliasByPath('/node/' . $node->id());
            $url = $alias;

            if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
                $slug = str_replace('%page_name%', $post_title, $data['contents']['url_slug']);
                $slug = str_replace('%separator%', '-', $slug);
                $slug = str_replace(' ', '', $slug);
                $url = '/' . ltrim($slug, '/');
                $pathAlias = PathAlias::create([
                    'path' => '/node/' . $node->id(),
                    'alias' => $url,
                ]);
                $pathAlias->save();
            }

            $menu = MenuLinkContent::create([
                'menu_name' => 'slickplan-importer',
                'title' => $post_title,
                'link' => [
                    'uri' => 'internal:' . $url
                ],
                'parent' => $parent_id,
                'module' => 'menu',
                'customized' => true,
                'options' => array()
            ]);
            $menu->save();

            $return = array(
                'ID' => $node->id(),
                'title' => $post_title,
                'url' => $url,
                'mlid' => $menu->getPluginId(),
                'files' => $this->_files
            );

            // Save page permalink
            if (isset($data['@attributes']['id']) and $data['@attributes']['id']) {
                $this->options['imported_pages'][$data['@attributes']['id']] = $return['url'];
            }

            if ($this->_has_unparsed_internal_links) {
                $this->options['internal_links'][] = $return['ID'];
            }
        } catch (Exception $e) {
            $return = array(
                'title' => $post_title,
                'error' => $e->getMessage()
            );
        }
        $this->summary[] = $return;
        return $return;
    }

    /**
     * Add new menu with pages structure
     */
    public function addMenu()
    {
        $menu = array(
            'id' => 'slickplan-importer',
            'menu_name' => 'slickplan-importer',
            'label' => 'Slickplan Importer',
            'description' => 'Slickplan Importer - imported pages structure'
        );

        $menu_array = Menu::loadMultiple();

        if (isset($menu_array[$menu['id']])) {
            Drupal::service('plugin.manager.menu.link')->deleteLinksInMenu($menu['id']);
        } else {
            Menu::create($menu)->save();
        }

        if (isset($menu_array[$menu['id']])) {
            $available_menus = Drupal::state()->get('menu_options_' . $this->options['post_type'], array(
                'main-menu' => 'main-menu'
            ));
            if (!isset($available_menus['slickplan-importer'])) {
                $available_menus['slickplan-importer'] = 'slickplan-importer';
                Drupal::state()->set('menu_options_' . $this->options['post_type'], $available_menus);
                Drupal::state()->resetCache();
            }

            return $menu['menu_name'];
        } else {
            $menu = false;
        }

        $cache = Drupal::cache('menu');
        $cache->deleteAll();

        return $menu;
    }

    /**
     * Get HTML of a summary row
     *
     * @param array $page
     * @return string
     */
    public function getSummaryRow(array $page)
    {
        $html = '<div style="margin: 10px 0;">Importing „<b>' . $page['title'] . '</b>”&hellip;<br />';
        if (isset($page['error']) and $page['error']) {
            $html .= '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> ' . $page['error'] . '</span>';
        } elseif (isset($page['url'])) {
            $html .= '<i class="fa fa-fw fa-check" style="color: #0d0"></i> '
                . '<a href="' . htmlspecialchars($page['url']) . '">' . $page['url'] . '</a>';
        } elseif (isset($page['loading']) and $page['loading']) {
            $html .= '<i class="fa fa-fw fa-refresh fa-spin"></i>';
        }
        if (isset($page['files']) and is_array($page['files']) and count($page['files'])) {
            $files = array();
            foreach ($page['files'] as $file) {
                if (isset($file['url']) and $file['url']) {
                    $files[] = '<i class="fa fa-fw fa-check" style="color: #0d0"></i> <a href="' . $file['url'] . '" target="_blank">'
                        . $file['filename'] . '</a>';
                } elseif (isset($file['error']) and $file['error']) {
                    $files[] = '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> '
                        . $file['filename'] . ' - ' . $file['error'] . '</span>';
                }
            }
            $html .= '<div style="border-left: 5px solid rgba(0, 0, 0, 0.05); margin-left: 5px; '
                . 'padding: 5px 0 5px 11px;">Files:<br />' . implode('<br />', $files) . '</div>';
        }
        $html .= '<div>';
        return $html;
    }

    /**
     * Check if there are any pages with unparsed internal links, if yes - replace links with real URLs
     */
    public function checkForInternalLinks()
    {
        if (isset($this->options['internal_links']) and is_array($this->options['internal_links'])) {
            foreach ($this->options['internal_links'] as $page_id) {
                $page = Node::load($page_id);
                $body = $page->get('body')->getValue();
                if (isset($body[0]['value'])) {
                    $page_content = $this->_parseInternalLinks($body[0]['value'], true);
                    if ($page_content) {
                        $page->set('body', array(
                            'summary' => '',
                            'value' => $page_content,
                            'format' => 'full_html'
                        ))->save();
                    }
                }
            }
        }
    }

    /**
     * Replace internal links with correct pages URLs.
     *
     * @param $content
     * @param $force_parse
     * @return bool
     */
    private function _parseInternalLinks($content, $force_parse = false)
    {
        preg_match_all('/href="slickplan:([a-z0-9]+)"/isU', $content, $internal_links);
        if (isset($internal_links[1]) and is_array($internal_links[1]) and count($internal_links[1])) {
            $internal_links = array_unique($internal_links[1]);
            $links_replace = array();
            foreach ($internal_links as $cell_id) {
                if (isset($this->options['imported_pages'][$cell_id]) and $this->options['imported_pages'][$cell_id]) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="' . htmlspecialchars($this->options['imported_pages'][$cell_id]) . '"';
                } elseif ($force_parse) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="#"';
                } else {
                    $this->_has_unparsed_internal_links = true;
                }
            }
            if (count($links_replace)) {
                return strtr($content, $links_replace);
            }
        }
        return false;
    }

    /**
     * Get formatted HTML content.
     *
     * @param array $contents
     * @return string
     */
    protected function _getFormattedContent(array $contents)
    {
        $post_content = array();
        foreach ($contents as $type => $content) {
            if (isset($content['content'])) {
                $content = array(
                    $content
                );
            }
            foreach ($content as $element) {
                if (!isset($element['content'])) {
                    continue;
                }
                $html = '';
                switch ($type) {
                    case 'wysiwyg':
                        $html .= $element['content'];
                        break;
                    case 'text':
                        $html .= htmlspecialchars($element['content']);
                        break;
                    case 'image':
                        foreach ($this->_getMediaElementArray($element) as $item) {
                            if (isset($item['type'], $item['url'])) {
                                $attrs = array(
                                    'alt' => isset($item['alt']) ? $item['alt'] : '',
                                    'title' => isset($item['title']) ? $item['title'] : '',
                                    'file_name' => isset($item['file_name']) ? $item['file_name'] : ''
                                );
                                if ($item['type'] === 'library') {
                                    $src = $this->addMedia($item['url'], $attrs);
                                } else {
                                    $src = $item['url'];
                                }
                                if ($src and is_string($src)) {
                                    $html .= '<img src="' . htmlspecialchars($src)
                                        . '" alt="' . htmlspecialchars($attrs['alt'])
                                        . '" title="' . htmlspecialchars($attrs['title']) . '" />';
                                }
                            }
                        }
                        break;
                    case 'video':
                    case 'file':
                        foreach ($this->_getMediaElementArray($element) as $item) {
                            if (isset($item['type'], $item['url'])) {
                                $attrs = array(
                                    'description' => isset($item['description']) ? $item['description'] : '',
                                    'file_name' => isset($item['file_name']) ? $item['file_name'] : ''
                                );
                                if ($item['type'] === 'library') {
                                    $src = $this->addMedia($item['url'], $attrs);
                                    $name = basename($src);
                                } else {
                                    $src = $item['url'];
                                    $name = $src;
                                }
                                if ($src and is_string($src)) {
                                    $name = $attrs['description'] ? $attrs['description'] : ($attrs['file_name'] ? $attrs['file_name'] : $name);
                                    $html .= '<a href="' . htmlspecialchars($src)
                                        . '" title="' . htmlspecialchars($attrs['description']) . '">' . $name . '</a>';
                                }
                            }
                        }
                        break;
                    case 'table':
                        if (isset($element['content']['data'])) {
                            if (!is_array($element['content']['data'])) {
                                $element['content']['data'] = @json_decode($element['content']['data'], true);
                            }
                            if (is_array($element['content']['data'])) {
                                $html .= '<table>';
                                foreach ($element['content']['data'] as $row) {
                                    $html .= '<tr>';
                                    foreach ($row as $cell) {
                                        $html .= '<td>' . $cell . '</td>';
                                    }
                                    $html .= '</tr>';
                                }
                                $html .= '<table>';
                            }
                        }
                        break;
                }
                if ($html) {
                    $prepend = '';
                    $append = '';
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $element['options']['tag'] = preg_replace('/[^a-z]+/', '', strtolower($element['options']['tag']));
                        if ($element['options']['tag']) {
                            $prepend = '<' . $element['options']['tag'];
                            if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                                $prepend .= ' id="' . htmlspecialchars($element['options']['tag_id']) . '"';
                            }
                            if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                                $prepend .= ' class="' . htmlspecialchars($element['options']['tag_class']) . '"';
                            }
                            $prepend .= '>';
                        }
                    }
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $append = '</' . $element['options']['tag'] . '>';
                    }
                    $post_content[] = $prepend . $html . $append;
                }
            }
        }
        return implode("\n\n", $post_content);
    }

    /**
     * Reformat title.
     *
     * @param $data
     * @return string
     */
    protected function _getFormattedTitle(array $data)
    {
        $title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
            ? $data['contents']['page_title']
            : (isset($data['text']) ? $data['text'] : '');
        if ($this->options['titles'] === 'ucfirst') {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title);
                $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
            } else {
                $title = ucfirst(strtolower($title));
            }
        } elseif ($this->options['titles'] === 'ucwords') {
            if (function_exists('mb_convert_case')) {
                $title = mb_convert_case($title, MB_CASE_TITLE);
            } else {
                $title = ucwords(strtolower($title));
            }
        }
        return $title;
    }

    /**
     * Parse single node XML element.
     *
     * @param \DOMElement $node
     * @return array|string
     */
    protected function _parseSlickplanXmlNode($node)
    {
        if (isset($node->nodeType)) {
            if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                return trim($node->textContent);
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $output = array();
                for ($i = 0, $j = $node->childNodes->length; $i < $j; ++ $i) {
                    $child_node = $node->childNodes->item($i);
                    $value = $this->_parseSlickplanXmlNode($child_node);
                    if (isset($child_node->tagName)) {
                        if (!isset($output[$child_node->tagName])) {
                            $output[$child_node->tagName] = array();
                        }
                        $output[$child_node->tagName][] = $value;
                    } elseif ($value !== '') {
                        $output = $value;
                    }
                }

                if (is_array($output)) {
                    foreach ($output as $tag => $value) {
                        if (is_array($value) and count($value) === 1) {
                            $output[$tag] = $value[0];
                        }
                    }
                    if (empty($output)) {
                        $output = '';
                    }
                }

                if ($node->attributes->length) {
                    $attributes = array();
                    foreach ($node->attributes as $attr_name => $attr_node) {
                        $attributes[$attr_name] = (string) $attr_node->value;
                    }
                    if (!is_array($output)) {
                        $output = array(
                            '@value' => $output
                        );
                    }
                    $output['@attributes'] = $attributes;
                }
                return $output;
            }
        }
        return array();
    }

    /**
     * Check if the array is from a correct Slickplan XML file.
     *
     * @param array $array
     * @param bool $parsed
     * @return bool
     */
    protected function _isCorrectSlickplanXmlFile($array, $parsed = false)
    {
        $first_test = (
            $array
            and is_array($array)
            and isset($array['title'], $array['version'], $array['link'])
            and is_string($array['link'])
            and strstr($array['link'], '.slickplan')
        );
        if ($first_test) {
            if ($parsed) {
                if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                    return true;
                }
            } elseif (
                isset($array['section']['options'], $array['section']['cells'])
                or isset($array['section'][0]['options'], $array['section'][0]['cells'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get multidimensional array, put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @return array
     */
    protected function _getMultidimensionalArrayHelper(array $array)
    {
        $cells = array();
        $main_section_key = - 1;
        $relation_section_cell = array();
        foreach ($array['section'] as $section_key => $section) {
            if (isset($section['@attributes']['id'], $section['cells']['cell']) and is_array($section['cells']['cell'])) {
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    if (isset($cell['@attributes']['id'])) {
                        $cell_id = $cell['@attributes']['id'];
                        if (isset($cell['section']) and $cell['section']) {
                            $relation_section_cell[$cell['section']] = $cell_id;
                        }
                    } else {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    }
                }
            } else {
                unset($array['section'][$section_key]);
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_id = $section['@attributes']['id'];
            if ($section_id !== 'svgmainsection') {
                $remove = true;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $cell['level'] = (string) $cell['level'];
                    if ($cell['level'] === 'home') {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    } elseif ($cell['level'] === '1' and isset($relation_section_cell[$section_id])) {
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['parent'] = $relation_section_cell[$section_id];
                        $remove = false;
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] *= 10;
                    }
                }
                if ($remove) {
                    unset($array['section'][$section_key]);
                }
            } else {
                $main_section_key = $section_key;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] /= 1000;
                }
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_cells = array();
            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                $section_cells[] = $cell;
            }
            usort($section_cells, array(
                $this,
                '_sortPages'
            ));
            $array['section'][$section_key]['cells']['cell'] = $section_cells;
            $cells = array_merge($cells, $section_cells);
            unset($section_cells);
        }
        $multi_array = array();
        if (isset($array['section'][$main_section_key]['cells']['cell'])) {
            foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                if (
                    isset($cell['@attributes']['id'])
                    and (
                        $cell['level'] === 'home'
                        or $cell['level'] === 'util'
                        or $cell['level'] === 'foot'
                        or strval($cell['level']) === '1'
                    )
                ) {
                    $level = $cell['level'];
                    if (!isset($multi_array[$level]) or !is_array($multi_array[$level])) {
                        $multi_array[$level] = array();
                    }
                    $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                    $cell = array(
                        'id' => $cell['@attributes']['id'],
                        'title' => $this->_getFormattedTitle($cell)
                    );
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $multi_array[$level][] = $cell;
                }
            }
        }
        unset($array, $cells, $relation_section_cell);
        return $multi_array;
    }

    /**
     * Put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @param $parent
     * @return array
     */
    protected function _getMultidimensionalArray(array $array, $parent)
    {
        $cells = array();
        foreach ($array as $cell) {
            if (isset($cell['parent'], $cell['@attributes']['id']) and $cell['parent'] === $parent) {
                $childs = $this->_getMultidimensionalArray($array, $cell['@attributes']['id']);
                $cell = array(
                    'id' => $cell['@attributes']['id'],
                    'title' => $this->_getFormattedTitle($cell)
                );
                if ($childs) {
                    $cell['childs'] = $childs;
                }
                $cells[] = $cell;
            }
        }
        return $cells;
    }

    /**
     * Sort cells.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    protected function _sortPages(array &$a, array &$b)
    {
        if (isset($a['order'], $b['order'])) {
            return ($a['order'] < $b['order']) ? - 1 : 1;
        }
        return 0;
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

