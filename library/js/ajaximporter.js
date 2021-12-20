var SlickplanInitialized = false;

Drupal.behaviors.myModule = {
    attach: function() {
        if (SlickplanInitialized) {
            return;
        }

        SlickplanInitialized = true;
        var $ = jQuery;
        var $form = $('#ajax-importer-forms');

        var $slickplanJson = $form.find(':input[name="slickplan_json"]');
        var $slickplanHtml = $form.find(':input[name="slickplan_html"]')

        if (!$slickplanJson.length || !$slickplanHtml.length) {
        	return;
        }

        var slickplanJson = $.parseJSON($slickplanJson.val());
        var slickplanHtml = $slickplanHtml.val();

        var $summary = $form.find('.slickplan-summary');
        var $progress = $form.find('#slickplan-progress');

        var _pages = [];
        var _importIndex = 0;

        var _generatePagesFlatArray = function(pages, parent) {
            $.each(pages, function(index, data) {
                if (data.id) {
                    _pages.push({
                        id: data.id,
                        parent: parent,
                        title: data.title
                    });
                    if (data.childs) {
                        _generatePagesFlatArray(data.childs, data.id);
                    }
                }
            });
        };

        var _addMenuID = function(parent_id, mlid) {
            for (var i = 0; i < _pages.length; ++i) {
                if (_pages[i].parent === parent_id) {
                    _pages[i].mlid = mlid;
                }
            }
        };

        var _importPage = function(page) {
            var html = ('' + slickplanHtml).replace('{title}', page.title);
            var $element = $(html).appendTo($summary);
            var percent = Math.round((_importIndex / _pages.length) * 100);
            $progress
                .find('.progress__bar')
                    .width(percent + '%')
                    .end()
                .find('.progress__percentage')
                    .text(percent + '%')
                    .end()
                .find('.progress__description')
                    .text(page.title + '...');
            $.post(drupalSettings.path.baseUrl + 'admin/config/content/slickplan/ajax_post?ajax_form=1&_wrapper_format=drupal_ajax', {
                slickplan: {
                    page: page.id,
                    parent: page.parent ? page.parent : '',
                    mlid: page.mlid ? page.mlid : 0,
                    last: (_pages && _pages[_importIndex + 1]) ? 0 : 1
                }
            }, function(data) {
                $element.replaceWith(data.html);
                ++_importIndex;
                if (data) {
                    if (data.mlid) {
                        _addMenuID(page.id, data.mlid);
                    }
                }
                if (_pages && _pages[_importIndex]) {
                    _importPage(_pages[_importIndex]);
                } else {
                    $progress.remove();
                    $form.find('h2').text('Success!');
                    $form.find('.slickplan-show-summary').show();
                    $(window).scrollTop(0);
                }
            }, 'json');
        };

        var types = ['home', '1', 'util', 'foot'];
        for (var i = 0; i < types.length; ++i) {
            if (slickplanJson[types[i]] && slickplanJson[types[i]].length) {
                _generatePagesFlatArray(slickplanJson[types[i]]);
            }
        }

        _importIndex = 0;
        if (_pages && _pages[_importIndex]) {
            $(window).scrollTop(0);
            _importPage(_pages[_importIndex]);
        }
    }
};
