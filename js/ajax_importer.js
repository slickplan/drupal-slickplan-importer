var SlickplanInitialized = false;

Drupal.behaviors.myModule = {
    attach: function() {
        if (SlickplanInitialized || !window.SLICKPLAN_JSON || !window.SLICKPLAN_HTML) {
            return;
        }

        SlickplanInitialized = true;

        var $ = jQuery;
        var $form = $('#slickplan-importer-ajax-importer-form');
        var $summary = $form.find('.slickplan-summary');
        var $progress = $form.find('#progress');

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
            var html = ('' + window.SLICKPLAN_HTML).replace('{title}', page.title);
            var $element = $(html).appendTo($summary);
            var percent = Math.round((_importIndex / _pages.length) * 100);
            $progress
                .find('.filled')
                    .width(percent + '%')
                    .end()
                .find('.percentage')
                    .text(percent + '%')
                    .end()
                .find('.message')
                    .text(page.title + '...');
            $.post(Drupal.settings.basePath + 'admin/config/content/slickplan_importer/ajax_importer/post', {
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
            if (window.SLICKPLAN_JSON[types[i]] && window.SLICKPLAN_JSON[types[i]].length) {
                _generatePagesFlatArray(window.SLICKPLAN_JSON[types[i]]);
            }
        }

        _importIndex = 0;
        if (_pages && _pages[_importIndex]) {
            $(window).scrollTop(0);
            _importPage(_pages[_importIndex]);
        }
    }
}