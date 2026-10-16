/* WP-Filebase editor button — TinyMCE 4 plugin API (WordPress 3.9+). */
(function () {
	tinymce.PluginManager.add('wpfilebase', function (editor, url) {
		editor.addButton('wpfbInsertTag', {
			title: 'WP-Filebase',
			image: url + '/images/btn.gif',
			onclick: function () {
				var postId = '';
				try { postId = tinymce.DOM.get('post_ID').value; } catch (ex) {}
				var src = ajaxurl.replace('admin-ajax.php', 'admin.php?wpfilebase-screen=editor-plugin')
					.replace('editor-plugin?', 'editor-plugin&')
					+ '&post_id=' + postId
					+ '&content=' + encodeURIComponent(editor.selection.getContent());
				editor.windowManager.open({
					title: 'WP-Filebase',
					url: src,
					width: Math.min(1000, document.body.clientWidth - 100),
					height: document.body.clientHeight - 100
				}, { plugin_url: url });
			}
		});
	});
})();
