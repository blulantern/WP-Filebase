<?php

class WPFB_TreeviewAdmin
{

    public static function ReturnHTML($id, $drag_drop = false, $tpl_tag = null, $args = array())
    {
        ob_start();
        self::RenderHTML($id, $drag_drop, $tpl_tag, $args);
        return ob_get_clean();
    }

    public static function RenderHTML($id, $drag_drop = false, $tpl_tag = null, $args = array())
    {
        $jss = md5($id);
        wp_print_scripts('wpfb-live-admin');
        ?>
        <style type="text/css" media="screen">
            .treeview  .dragover-target {
                background: #f1c40f;
            }

            .treeview.dragover-target {
                outline: 2px dashed #f1c40f;
                outline-offset: 4px;
            }

            .treeview .dragged {
                opacity: 0.5;
                background: #3498db;
                border: 0.2em solid #2980b9;
                margin: -0.2em;
            }
        </style>
        <script type="text/javascript">
            //<![CDATA[
            var wpfb_fbDOMModTimeout<?php echo $jss ?> = -1;

            <?php if ($drag_drop) { ?>
            function wpfb_dtContains(dt, t) {
                if ('undefined' !== typeof dt.types.indexOf)
                    return dt.types.indexOf(t) !== -1;
                if ('undefined' !== typeof dt.types.contains)
                    return dt.types.contains(t);
                for (var s in dt.types) {
                    if (s === t)
                        return true;
                }
                return false;
            }
            <?php } ?>

            wpfb_tvaUseDataText = false;

            var dragOverHandler = function (e) {
                var cat_id = wpfb_fileBrowserTargetId(e, 'cat'), dt = e.originalEvent.dataTransfer;
                var hasFiles = wpfb_dtContains(dt, "Files");
                var hasWpfbItem = wpfb_dtContains(dt, "application/x-wpfilebase-item") || (wpfb_tvaUseDataText && wpfb_dtContains(dt, "Text"));
                if (!hasFiles && !hasWpfbItem)
                    return true;

                var ok = hasFiles || (cat_id >= 0 && !wpfb_dtContains(dt, "application/x-wpfilebase-cat-" + cat_id));

                var idp = wpfb_getFileBrowserIDP(jQuery(this));

                var cur_id = wpfb_fbDragCat<?php echo $jss ?>;

                if (cur_id !== cat_id && cat_id > 0) {
                    jQuery('#' + idp + 'cat-' + cur_id).removeClass('dragover-target');
                    if (ok)
                        jQuery('#' + idp + 'cat-' + cat_id).addClass('dragover-target');
                    wpfb_fbDragCat<?php echo $jss ?> = ok ? cat_id : 0;
                }

                // Over the tree itself (or a root-level row): the item would land at the root.
                var base = +(wpfb_getFileBrowserParams('<?php echo $id ?>').base || 0);
                if (hasWpfbItem) {
                    if (cat_id == base && cur_id > 0)
                        jQuery('#' + idp + 'cat-' + cur_id).removeClass('dragover-target');
                    jQuery(this).toggleClass('dragover-target', ok && cat_id == base);
                }

                if (hasFiles)
                    return true;

                if (hasWpfbItem)
                    e.stopPropagation();

                if (hasWpfbItem && ok) { // make dropk OK effect
                    e.preventDefault();
                    e.originalEvent.dataTransfer.dropEffect = 'move';
                }
            };



            <?php if ($drag_drop) { ?>
            // Read a dragged item out of the dataTransfer: ['file'|'cat', id] or null.
            function wpfb_fbDraggedItem<?php echo $jss ?>(dt) {
                if (!wpfb_dtContains(dt, "application/x-wpfilebase-item") && !(wpfb_tvaUseDataText && wpfb_dtContains(dt, "Text")))
                    return null;
                var tid = wpfb_tvaUseDataText ? dt.getData("Text").substr("application/x-wpfilebase-item=".length).split('-') : dt.getData("application/x-wpfilebase-item").split('-');
                return (tid && tid.length === 2 && +tid[1] > 0) ? tid : null;
            }

            // Move the dragged item (tid = ['file'|'cat', id]) into category newCatId (the browser's
            // base = root) through the change-category action, then place its row in the tree.
            function wpfb_fbMove<?php echo $jss ?>(tid, newCatId) {
                var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                var params = wpfb_getFileBrowserParams('<?php echo $id ?>');
                var base = +(params.base || 0);
                newCatId = +newCatId || 0;
                var tree = jQuery("#<?php echo $id ?>").removeClass('dragover-target');
                var dLi = jQuery('#' + idp + tid.join('-'));
                var curLi = dLi.parent().closest('li[id^="' + idp + 'cat-"]');
                var curCat = curLi.length ? +curLi.attr('id').substr((idp + 'cat-').length) : base;
                var tLi = jQuery('#' + idp + 'cat-' + newCatId).removeClass('dragover-target');
                wpfb_fbDragCat<?php echo $jss ?> = 0;
                if (newCatId === curCat || (tid[0] === 'cat' && +tid[1] === newCatId))
                    return; // already there, or dropped on itself: nothing to do
                tLi.css({cursor: 'wait'});
                jQuery.ajax({
                    url: wpfbConf.ajurl, type: "POST", dataType: "json",
                    data: {wpfb_action: "change-category", new_cat_id: newCatId, id: tid[1], type: tid[0]},
                    success: function (data) {
                        if (!data || data.error) {
                            alert(data && data.error ? data.error : 'Error');
                            return;
                        }
                        // The server answers with the category it actually used: the root when the
                        // target no longer exists (deleted in another tab).
                        var catId = ('undefined' !== typeof data.cat_id) ? +data.cat_id : newCatId;
                        var toRoot = (catId === base || catId === 0);
                        var cLi = toRoot ? jQuery() : jQuery('#' + idp + 'cat-' + catId);
                        var tUl = toRoot ? tree : cLi.children('ul').first();
                        if (!toRoot && (cLi.hasClass('expandable') || !cLi.length)) {
                            dLi.remove();
                            jQuery('.hitarea', cLi).first().click(); // reload shows the moved row
                        } else if (tUl.length) {
                            dLi.appendTo(tUl);
                        } else {
                            dLi.remove();
                        }
                        <?php if (!empty($args['onCategoryChanged'])) echo $args['onCategoryChanged'] . '(tid, catId);'; ?>
                    },
                    complete: function () { tLi.css({cursor: ''}); }
                });
            }
            <?php } ?>

            var wpfb_fbDOMModHandle<?php echo $jss ?> = function() {
                wpfb_fbDOMModTimeout<?php echo $jss ?> = -1;

                <?php if ($drag_drop) { ?>
                jQuery("#<?php echo $id ?> li:not([draggable]):not([id$='-0'])")
                    .attr('draggable', 'true')
                    .on('dragend', function (e) {
                        jQuery(e.currentTarget).removeClass('dragged');
                    })
                    .on('dragstart', function (e) {
                        // Only the row this handler belongs to may start its drag. When the pointer
                        // went down on a descendant <li> that is not (yet) draggable, the browser
                        // makes this row the drag source (dragstart fires here, e.target is this row
                        // or a draggable <img>/<a> below it) and the whole folder would move instead
                        // of the child. Check both the event target and what is under the pointer.
                        var oe = e.originalEvent || e, under = ('number' === typeof oe.clientX && (oe.clientX || oe.clientY)) ? document.elementFromPoint(oe.clientX, oe.clientY) : null;
                        if (jQuery(e.target).closest('li')[0] !== this || (under && jQuery(under).closest('li')[0] !== this)) {
                            e.preventDefault();
                            return false;
                        }
                        var li = jQuery(this), t = (li.attr('id').indexOf('-cat-') >= 0 ? 'cat' : 'file'), id = wpfb_fileBrowserTargetId(e, t);

                        if (id >= 0) {
                            e.stopPropagation();
                            li.addClass('dragged');
                            var dt = e.originalEvent.dataTransfer;
                            dt.effectAllowed = (t === 'cat') ? 'move' : 'linkMove';
                            dt.clearData();
                            try {
                                dt.setData("application/x-wpfilebase-item", t + "-" + id);
                                dt.setData("application/x-wpfilebase-" + t + "-" + id, '' + id);
                            } catch (e) { // on IE, only text/URL data format is allowed
                                dt.setData("Text", "application/x-wpfilebase-item=" + t + "-" + id);
                                wpfb_tvaUseDataText = true;
                            }
                            try {
                                dt.setDragImage(li.find('img')[0], 10, 10);
                            } catch (e) {
                            }
                        }
                    })
                    .on('dragenter', function(e) {
                        // open category
                        var li = this;
                        li._openTimer = setTimeout(function() {
                            if (jQuery(li).hasClass('expandable'))
                                jQuery('.hitarea', li).click();
                        }, 600);
                    })
                    .on('dragleave', function (e) {
                        var li = this;
                        jQuery(li).removeClass('dragover-target');
                        wpfb_fbDragCat<?php echo $jss ?> = 0;
                        if(li._openTimer) {
                            clearTimeout(li._openTimer);
                            li._openTimer = false;
                        }
                    })
                    .on('drop', function (e) {
                        var tid = wpfb_fbDraggedItem<?php echo $jss ?>(e.originalEvent.dataTransfer);
                        if (!tid)
                            return true; // not ours (e.g. files from the desktop: Plupload's)
                        e.preventDefault();
                        e.stopPropagation();
                        jQuery(this).removeClass('dragover-target');
                        wpfb_fbMove<?php echo $jss ?>(tid, wpfb_fileBrowserTargetId(e, 'cat'));
                        return false;
                    });
                <?php } /* drag_drop */ ?>
                jQuery("#<?php echo $id ?> a.add-file:not(.file-input)").each(function (i, el) {
                    var fileInput = new moxie.file.FileInput({
                        multiple: true,
                        //container: '<?php echo $id ?>',
                        browse_button: el
                    });

                    jQuery(el).addClass('file-input');

                    var params = wpfb_getFileBrowserParams('<?php echo $id ?>');

                    fileInput.onchange = function (event) {
                        var up = jQuery("#<?php echo $id ?>").data('uploader');
                        var cat_id = wpfb_fileBrowserTargetId(jQuery(el).parent(), 'cat') || params.base || 0;
                        up.settings.multipart_params["btn_cat_id"] = cat_id;
                        up.addFile(fileInput.files);
                    };
                    fileInput.init();
                });
            }

            jQuery(document).ready(function () {

                jQuery("#<?php echo $id ?>").on('dragover', dragOverHandler);

                wpfb_fbDragCat<?php echo $jss ?> = 0;
                // Re-arm drag handles / inline add buttons whenever the tree changes (async loads,
                // inline new category, uploads). Mutation events (DOMSubtreeModified) are gone from
                // Chromium 127+, so observe the subtree instead; same 100 ms debounce as before.
                var wpfb_fbTree<?php echo $jss ?> = document.getElementById('<?php echo $id ?>');
                if (wpfb_fbTree<?php echo $jss ?> && 'undefined' !== typeof MutationObserver) {
                    new MutationObserver(function () {
                        if (wpfb_fbDOMModTimeout<?php echo $jss ?> >= 0)
                            window.clearTimeout(wpfb_fbDOMModTimeout<?php echo $jss ?>);
                        wpfb_fbDOMModTimeout<?php echo $jss ?> = window.setTimeout(wpfb_fbDOMModHandle<?php echo $jss ?>, 100);
                    }).observe(wpfb_fbTree<?php echo $jss ?>, {childList: true, subtree: true});
                }
                jQuery("#<?php echo $id ?>")
                    <?php if ($drag_drop) { ?>
                    .on('dragover', function (e) {
                        // pointer over the tree background: the drop target is this browser's root
                        if (e.target.id != '<?php echo $id ?>')
                            return;
                        var params = wpfb_getFileBrowserParams('<?php echo $id ?>');
                        wpfb_fbDragCat<?php echo $jss ?> = params.base || 0;
                    })
                    .on('dragleave', function (e) {
                        var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                        jQuery('#' + idp + 'cat-' + wpfb_fbDragCat<?php echo $jss ?>).removeClass('dragover-target');
                        wpfb_fbDragCat<?php echo $jss ?> = 0;
                        if (e.target.id == '<?php echo $id ?>')
                            jQuery(this).removeClass('dragover-target');
                    })
                    .on('drop', function (e) {
                        // Drop that no row handled: on the tree background, a placeholder or an
                        // inline "add" row. The target is the enclosing folder, or the root.
                        var tid = wpfb_fbDraggedItem<?php echo $jss ?>(e.originalEvent.dataTransfer);
                        if (!tid)
                            return true;
                        e.preventDefault();
                        wpfb_fbMove<?php echo $jss ?>(tid, wpfb_fileBrowserTargetId(e, 'cat'));
                        return false;
                    })
                    .before('<' + 'div class="wpfb-drag-drop-hint">+ DRAG &amp; DROP enabled<' + '/div>');
                <?php } /* drag_drop */ ?>
                ;

                wpfb_fbDOMModHandle<?php echo $jss ?>();
            });

            var callbacks<?php echo $jss ?> = {
                filesQueued: function (up, files) {
                    var cat_id = wpfb_fbDragCat<?php echo $jss ?>;
                    if (up.settings.multipart_params["btn_cat_id"]) {
                        cat_id = up.settings.multipart_params["btn_cat_id"];
                        up.settings.multipart_params["btn_cat_id"] = null;
                    }

                    up.settings.multipart_params["cat_id"] = cat_id; // actually presets is used (see below)!
                    up.settings.multipart_params["tpl_tag"] = '<?php echo $tpl_tag; ?>';

                    <?php if (!empty($args['uploadParamsFilter'])) echo 'up.settings.multipart_params = ' . $args['uploadParamsFilter'] . '(up.settings.multipart_params, files);'; ?>

                    cat_id = up.settings.multipart_params["cat_id"];
                    up.settings.multipart_params["presets"] = (up.settings.multipart_params["presets"] ? '&' : '') + "file_category=" + cat_id;

                    // open the category
                    var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                    var li = jQuery('#' + idp + 'cat-' + cat_id);
                    if (li.hasClass('expandable'))
                        jQuery('.hitarea', li).click();

                    jQuery('#' + idp + 'cat-' + cat_id).removeClass('dragover-target');
                    wpfb_fbDragCat<?php echo $jss ?> = 0;
                },
                fileQueued: function (up, file) {
                    var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                    var cat_id = up.settings.multipart_params["cat_id"];
                    var toRoot = (cat_id === 0 || cat_id == wpfb_getFileBrowserParams('<?php echo $id ?>').base);
                    var catUl = toRoot ? jQuery('#<?php echo $id ?>') : jQuery('#' + idp + 'cat-' + cat_id).children('ul').first();
                    if (catUl.length) {
                        var catUploadUl = toRoot ? catUl : catUl.nextAll('ul.uploads');
                        if (!catUploadUl.length)
                            catUploadUl = jQuery('<ul class="uploads"></ul>').insertAfter(catUl);
                        catUploadUl.append(
                            '<li id="' + file.dom_id + '" class="wpfb-treeview-upload">' +
                            '<' + 'img src="<?php echo site_url(WPINC . '/images/crystal/default.png'); ?>" alt="Loading..." style="height:1.2em;margin-right:0.3em;" /' + '>' +
                            '<' + 'span class="filename">' + file.name + '<' + '/span><' + 'span class="error"><' + '/span> ' +
                            '<' + 'div class="loading" style="background-image:url(<?php echo admin_url('images/loading.gif'); ?>);width:1.2em;height:1.2em;background-size:contain;display:inline-block;vertical-align:sub;"><' + '/div>' +
                            '<' + 'span class="percent">0%<' + '/span>' +
                            '<' + '/li>');
                    }

                    <?php if (!empty($args['onFileQueued'])) echo $args['onFileQueued'] . '(file, up.settings.multipart_params);'; ?>
                },
                success: function (file, serverData) {
                    var item = jQuery('#' + file.dom_id);
                    if (serverData.tpl) {
                        item.html(serverData.tpl);
                        var idp = wpfb_getFileBrowserIDP('<?php echo $id ?>');
                        item.attr('id', idp + 'file-' + serverData.file_id);
                        item.append('<' + 'span class="ok"><?php _e('Upload OK!', 'wp-filebase') ?><' + '/span>');
                    } else {
                        var url = serverData.file_cur_user_can_edit ? serverData.file_edit_url : serverData.file_download_url;
                        jQuery('.filename', item).html('<' + 'a href="' + url + '" target="_blank">' + serverData.file_display_name + '<' + '/a>');
                        jQuery('img', item).attr('src', serverData.file_thumbnail_url);
                        jQuery('.loading,.percent', item).hide();
                    }

                    <?php if (!empty($args['onSuccess'])) echo $args['onSuccess'] . '(file,serverData);'; ?>
                }
            };
            //]]>
        </script>
        <?php
        wpfb_loadclass('PLUploader');
        $uploader = new WPFB_PLUploader();
        $cb_prefix = 'callbacks' . $jss . '.';
        $uploader->js_files_queued = $cb_prefix . 'filesQueued';
        $uploader->js_file_queued = $cb_prefix . 'fileQueued';
        $uploader->js_upload_success = $cb_prefix . 'success';
        $uploader->post_params['file_add_now'] = true;
        $uploader->Init($id);
    }

}
