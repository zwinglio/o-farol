/**
 * Montador de e-mail (campanhas e templates): blocos que se adicionam,
 * arrastam, duplicam e removem; o JSON vai num campo oculto do formulário
 * e a prévia ao vivo é montada pelo PHP (admin-ajax farol_newsletter_render).
 *
 * Os blocos de texto usam o editor do WordPress (wp.editor). O TinyMCE não
 * sobrevive a ser movido no DOM, então é desligado antes de arrastar ou
 * subir/descer e religado depois.
 */
jQuery(function ($) {
	var cfg = window.farolNL;
	var $list = $('#farol-nl-blocks');
	var $input = $('#farol-nl-blocks-input');
	if (!cfg || !$list.length || !$input.length) {
		return;
	}
	var $form = $input.closest('form');
	var kind = $list.data('kind') || 'campaign';
	var titles = $.extend({}, cfg.titles || {});
	var seq = 0;

	(cfg.posts || []).forEach(function (p) {
		titles[p.id] = p.title;
	});

	var LABELS = {
		heading: 'Título',
		text: 'Texto',
		posts: 'Matérias escolhidas',
		latest: 'Matérias recentes',
		image: 'Imagem',
		button: 'Botão',
		divider: 'Divisor',
		spacer: 'Espaço'
	};
	var DEFAULTS = {
		heading: { text: '', size: 'lg' },
		text: { html: '' },
		posts: { ids: [], feature: true },
		latest: { count: 6, feature: true },
		image: { url: '', alt: '', link: '' },
		button: { label: 'Leia no site', url: '' },
		divider: {},
		spacer: {}
	};

	/* ------------------------- Campos ------------------------- */

	function field(label, $control, hint) {
		var $f = $('<label class="farol-nl-field"/>').append($('<span class="farol-nl-field-label"/>').text(label), $control);
		if (hint) {
			$f.append($('<span class="description"/>').text(hint));
		}
		return $f;
	}

	function text(name, value, attrs) {
		return $('<input/>', $.extend({ type: 'text', 'class': 'widefat', 'data-field': name }, attrs || {})).val(value == null ? '' : value);
	}

	function check(name, checked, label) {
		return $('<label class="farol-nl-check-field"/>').append(
			$('<input/>', { type: 'checkbox', 'data-field': name }).prop('checked', !!checked),
			' ' + label
		);
	}

	function iconButton(icon, action, label) {
		return $('<button/>', { type: 'button', 'class': 'button-link farol-nl-icon', 'data-action': action, title: label, 'aria-label': label })
			.append($('<span/>', { 'class': 'dashicons dashicons-' + icon, 'aria-hidden': 'true' }));
	}

	function decode(html) {
		return $('<textarea/>').html(html).text();
	}

	/* ------------------------- Editor de texto ------------------------- */

	function initEditors($scope) {
		if (!window.wp || !wp.editor) {
			return;
		}
		$scope.find('textarea[data-field="html"]').each(function () {
			var id = this.id;
			wp.editor.initialize(id, {
				tinymce: {
					wpautop: true,
					toolbar1: 'formatselect,bold,italic,link,unlink,bullist,numlist,blockquote,alignleft,aligncenter,alignright',
					toolbar2: '',
					setup: function (ed) {
						ed.on('change keyup undo redo', schedule);
					}
				},
				quicktags: { buttons: 'strong,em,link,ul,ol,li' },
				mediaButtons: true
			});
		});
	}

	function removeEditors($scope) {
		if (!window.wp || !wp.editor) {
			return;
		}
		$scope.find('textarea[data-field="html"]').each(function () {
			wp.editor.remove(this.id);
		});
	}

	function editorContent(id) {
		var ed = window.tinymce && tinymce.get(id);
		return ed && !ed.isHidden() ? ed.getContent() : $('#' + id).val();
	}

	/* ------------------------- Seletor de matérias ------------------------- */

	function pickedItem(id) {
		return $('<li/>', { 'data-id': id }).append(
			$('<span class="farol-nl-grip" aria-hidden="true">⋮⋮</span>'),
			$('<span class="farol-nl-post-title"/>').text(titles[id] || '#' + id),
			iconButton('no-alt', 'unpick', 'Tirar')
		);
	}

	function postPicker(b) {
		var $picked = $('<ul class="farol-nl-picked" data-field="ids"/>');
		(b.ids || []).forEach(function (id) {
			$picked.append(pickedItem(id));
		});
		$picked.sortable({ items: '> li', handle: '.farol-nl-grip', update: sync });

		var $results = $('<ul class="farol-nl-results"/>');
		var $search = $('<input/>', { type: 'search', 'class': 'widefat', placeholder: 'Buscar matéria pelo título…' });
		var timer;

		function show(posts) {
			var have = $picked.children().map(function () {
				return +$(this).data('id');
			}).get();
			$results.empty();
			posts.filter(function (p) {
				return have.indexOf(p.id) === -1;
			}).slice(0, 8).forEach(function (p) {
				$results.append(
					$('<li/>').append(
						$('<button/>', { type: 'button', 'class': 'button-link farol-nl-pick', 'data-id': p.id }).text(p.title),
						$('<small/>').text(p.date || '')
					)
				);
			});
			if (!$results.children().length) {
				$results.append($('<li class="farol-nl-empty"/>').text('Nada encontrado.'));
			}
		}

		$search.on('focus', function () {
			if (!$search.val()) {
				show(cfg.posts || []);
			}
		}).on('input', function () {
			var q = $search.val().trim();
			clearTimeout(timer);
			if (!q) {
				show(cfg.posts || []);
				return;
			}
			timer = setTimeout(function () {
				wp.apiFetch({ path: '/wp/v2/posts?per_page=10&_fields=id,title,date&search=' + encodeURIComponent(q) }).then(function (posts) {
					show(posts.map(function (p) {
						var title = decode(p.title.rendered);
						titles[p.id] = title;
						return { id: p.id, title: title, date: p.date ? p.date.slice(0, 10).split('-').reverse().join('/') : '' };
					}));
				});
			}, 250);
		});

		$results.on('click', '.farol-nl-pick', function () {
			$picked.append(pickedItem($(this).data('id')));
			$(this).closest('li').remove();
			sync();
		});
		$picked.on('click', '[data-action="unpick"]', function () {
			$(this).closest('li').remove();
			sync();
		});

		return $('<div class="farol-nl-picker"/>').append(
			$picked,
			$('<p class="description farol-nl-picked-empty"/>').text('Nenhuma matéria ainda — busque abaixo. Arraste para reordenar.'),
			$search,
			$results,
			check('feature', b.feature, 'Primeira em destaque (foto e título maior)')
		);
	}

	/* ------------------------- Blocos ------------------------- */

	function build(b) {
		b = $.extend({}, DEFAULTS[b.type] || {}, b);
		var $el = $('<div class="farol-nl-block"/>').attr('data-type', b.type);
		var $body = $('<div class="farol-nl-block-body"/>');

		$el.append(
			$('<div class="farol-nl-block-head"/>').append(
				$('<span class="farol-nl-grip" aria-hidden="true">⋮⋮</span>'),
				$('<strong/>').text(LABELS[b.type] || b.type),
				$('<span class="farol-nl-block-actions"/>').append(
					iconButton('arrow-up-alt2', 'up', 'Subir'),
					iconButton('arrow-down-alt2', 'down', 'Descer'),
					iconButton('admin-page', 'duplicate', 'Duplicar'),
					iconButton('no-alt', 'remove', 'Remover')
				)
			),
			$body
		);

		switch (b.type) {
			case 'heading':
				$body.append(
					text('text', b.text, { placeholder: 'Ex.: Destaques da semana' }),
					$('<select data-field="size"/>').append(
						$('<option value="lg">Grande</option>'),
						$('<option value="md">Médio</option>')
					).val(b.size)
				);
				break;
			case 'text':
				$body.append($('<textarea/>', { id: 'farol-nl-text-' + (++seq), 'data-field': 'html', rows: 6, 'class': 'widefat' }).val(b.html || ''));
				break;
			case 'posts':
				$body.append(postPicker(b));
				break;
			case 'latest':
				$body.append(
					field('Quantas', $('<input/>', { type: 'number', min: 1, max: 12, 'data-field': 'count', 'class': 'small-text' }).val(b.count)),
					check('feature', b.feature, 'Primeira em destaque (foto e título maior)'),
					$('<p class="description"/>').text(
						'No resumo automático: as publicadas desde a última edição (sem nada novo, a edição não sai). Numa campanha: as mais recentes no momento em que for salva.'
					)
				);
				break;
			case 'image':
				var $url = text('url', b.url, { placeholder: 'https://…' });
				$body.append(
					field('Imagem', $('<span class="farol-nl-inline"/>').append(
						$url,
						$('<button type="button" class="button" data-action="media">Biblioteca</button>')
					)),
					field('Texto alternativo', text('alt', b.alt)),
					field('Link (opcional)', text('link', b.link, { placeholder: 'https://…' }))
				);
				break;
			case 'button':
				$body.append(
					field('Texto do botão', text('label', b.label)),
					field('Link', text('url', b.url, { placeholder: kind === 'confirmation' ? '{{link_confirmacao}}' : 'https://…' }))
				);
				break;
			case 'divider':
				$body.append($('<p class="description"/>').text('Linha fina separando seções.'));
				break;
			case 'spacer':
				$body.append($('<p class="description"/>').text('Espaço em branco.'));
				break;
		}
		return $el;
	}

	function collectOne($b) {
		var o = { type: $b.data('type') };
		$b.find('[data-field]').each(function () {
			var $f = $(this);
			var k = $f.data('field');
			if (k === 'ids') {
				o.ids = $f.children('li[data-id]').map(function () {
					return +$(this).data('id');
				}).get();
			} else if (k === 'html') {
				o.html = editorContent(this.id);
			} else if ($f.is(':checkbox')) {
				o[k] = $f.is(':checked');
			} else {
				o[k] = $f.val();
			}
		});
		return o;
	}

	function collect() {
		return $list.children('.farol-nl-block').map(function () {
			return collectOne($(this));
		}).get();
	}

	function add(b, $after) {
		var $el = build(b);
		$after && $after.length ? $after.after($el) : $list.append($el);
		initEditors($el);
		return $el;
	}

	function load(blocks) {
		removeEditors($list);
		$list.empty();
		(blocks || []).forEach(function (b) {
			add(b);
		});
		sync();
	}

	/* ------------------------- Sincronia e prévia ------------------------- */

	var previewTimer;
	var previewXhr;

	function sync() {
		$input.val(JSON.stringify(collect()));
		$('.farol-nl-blocks-empty').prop('hidden', !!$list.children().length);
		$list.find('.farol-nl-picker').each(function () {
			$(this).find('.farol-nl-picked-empty').toggle(!$(this).find('.farol-nl-picked li').length);
		});
		schedule();
	}

	function schedule() {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(preview, 500);
	}

	function preview() {
		var data = {
			action: 'farol_newsletter_render',
			_ajax_nonce: cfg.nonce,
			kind: kind,
			subject: $('#farol-nl-subject').val() || '',
			preheader: $('#farol-nl-preheader').val() || '',
			blocks: JSON.stringify(collect())
		};
		var $lists = $form.find('input[name="list_ids[]"]');
		if ($lists.length && $('#farol-nl-audience').length) {
			var ids = $lists.filter(':checked').map(function () {
				return this.value;
			}).get();
			data.list_ids = ids.length ? ids : [0];
		}
		previewXhr && previewXhr.abort();
		previewXhr = $.post(cfg.ajax, data).done(function (res) {
			if (!res || !res.success) {
				return;
			}
			$('#farol-nl-preview').attr('srcdoc', res.data.html);
			$('#farol-nl-preview-subject').text(res.data.subject || '—');
			if (res.data.audience !== null && res.data.audience !== undefined) {
				$('#farol-nl-audience').text(res.data.audience + (res.data.audience === 1 ? ' destinatário' : ' destinatários'));
			}
		});
	}

	/* ------------------------- Eventos ------------------------- */

	$list.sortable({
		items: '> .farol-nl-block',
		handle: '> .farol-nl-block-head .farol-nl-grip',
		placeholder: 'farol-nl-placeholder',
		forcePlaceholderSize: true,
		start: function (e, ui) {
			removeEditors(ui.item);
		},
		stop: function (e, ui) {
			initEditors(ui.item);
			sync();
		}
	});

	$list.on('click', '[data-action]', function () {
		var action = $(this).data('action');
		var $b = $(this).closest('.farol-nl-block');
		if (action === 'remove') {
			removeEditors($b);
			$b.remove();
		} else if (action === 'up' || action === 'down') {
			var $to = action === 'up' ? $b.prev('.farol-nl-block') : $b.next('.farol-nl-block');
			if (!$to.length) {
				return;
			}
			removeEditors($b);
			action === 'up' ? $to.before($b) : $to.after($b);
			initEditors($b);
		} else if (action === 'duplicate') {
			add(collectOne($b), $b);
		} else if (action === 'media') {
			var $url = $b.find('[data-field="url"]');
			var frame = wp.media({ title: 'Escolher imagem', library: { type: 'image' }, multiple: false, button: { text: 'Usar imagem' } });
			frame.on('select', function () {
				var img = frame.state().get('selection').first().toJSON();
				$url.val((img.sizes && img.sizes.large ? img.sizes.large.url : img.url));
				var $alt = $b.find('[data-field="alt"]');
				$alt.val() || $alt.val(img.alt || '');
				sync();
			});
			frame.open();
			return;
		} else {
			return;
		}
		sync();
	});

	$list.on('input change', '[data-field]', sync);
	$form.on('input change', '#farol-nl-subject, #farol-nl-preheader, input[name="list_ids[]"]', schedule);

	$('.farol-nl-addbar').on('click', '[data-add]', function () {
		var $el = add({ type: $(this).data('add') });
		sync();
		$('html, body').animate({ scrollTop: $el.offset().top - 80 }, 200);
	});

	// "Começar de um template" (só na campanha).
	$('#farol-nl-apply').on('click', function () {
		var id = +$('#farol-nl-start').val();
		var t = (cfg.templates || []).filter(function (x) {
			return x.id === id;
		})[0];
		if (!t) {
			return;
		}
		if ($list.children().length && !window.confirm('Trocar os blocos atuais pelos do template?')) {
			return;
		}
		$('#farol-nl-template-id').val(t.id);
		$('#farol-nl-subject').val() || $('#farol-nl-subject').val(t.subject);
		$('#farol-nl-preheader').val() || $('#farol-nl-preheader').val(t.preheader);
		load(t.blocks);
	});

	$form.on('submit', function () {
		window.tinymce && tinymce.triggerSave();
		$input.val(JSON.stringify(collect()));
	});

	load(cfg.blocks);
});
