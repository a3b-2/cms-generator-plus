/**
 * CMSKart Product Generator -- Admin JS v2.0
 * UN UNICO IIFE. Todas las funciones al mismo scope.
 */
(function ($, ADMIN) {
    'use strict';

    if (typeof ADMIN === 'undefined' || !ADMIN.ajaxurl) return;

    var ajax  = ADMIN.ajaxurl;
    var nonce = ADMIN.nonce;

    /* 
       UTILIDADES GLOBALES
     */

    function toast(msg, type) {
        $('.ckg-toast').remove();
        var $t = $('<div class="ckg-toast ckg-toast--' + (type || 'ok') + '">' + msg + '</div>');
        $('body').append($t);
        setTimeout(function () { $t.addClass('ckg-toast-show'); }, 30);
        setTimeout(function () { $t.removeClass('ckg-toast-show'); setTimeout(function () { $t.remove(); }, 400); }, 5000);
    }

    function spin(text) { return '<span class="ckg-spin"></span> ' + text; }

    function updateCount(fieldId, countId, max) {
        var len = $('#' + fieldId).val().length;
        $('#' + countId).text(len).css('color', len > max ? '#c0392b' : '#50575e');
    }

    function getOllamaContext() {
        var homos = [];
        $('#homo-body .ckg-row').each(function () {
            var t = $(this).find('input[name="homo_tipo[]"]').val();
            var c = $(this).find('input[name="homo_codigo[]"]').val();
            if (t) homos.push(t + (c ? ' ' + c : ''));
        });
        return {
            product_name:   $('#product_name').val().trim(),
            brand:          $('#brand').val().trim(),
            sku:            $('#sku').val().trim(),
            homologaciones: homos
        };
    }

    function renderImgPreview() {
        var $p = $('#ckg-img-preview').empty();
        $('#images_urls').val().split('\n').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (url, i) {
            $p.append('<div class="ckg-img-thumb">' + (i === 0 ? '<span class="ckg-img-main-label">Principal</span>' : '') + '<img src="' + url + '" onerror="this.style.display=\'none\'">' + '<button type="button" class="ckg-img-remove" data-url="' + url + '"></button></div>');
        });
    }

    function calcCompleteness() {
        // Solo ejecutar en la pagina de creacion de producto
        if (!$('#product_name').length) return;
        var tabs = {
            basic:      { weight: 20, fields: ['#product_name', '#brand', '#sku', '[name="short_desc"]'] },
            images:     { weight: 15, check:  function () { var v = $('#images_urls').val(); return v ? v.split('\n').filter(Boolean).length : 0; } },
            variations: { weight: 10, check:  function () { return $('#var-body .ckg-row input[name="var_label[]"]').filter(function () { return this.value.trim(); }).length; } },
            homol:      { weight: 10, check:  function () { return $('#homo-body .ckg-row input[name="homo_tipo[]"]').filter(function () { return this.value.trim(); }).length; } },
            content:    { weight: 30, checks: [
                function () { return $('[name="intro"]').val().trim().length > 50; },
                function () { return $('#specs-body .ckg-row').filter(function () { return $(this).find('input[name="spec_key[]"]').val().trim(); }).length >= 3; },
                function () { return $('#faq-body .ckg-row').filter(function () { return $(this).find('input[name="faq_pregunta[]"]').val().trim(); }).length >= 3; },
                function () { return $('[name="conclusion"]').val().trim().length > 50; }
            ]},
            seo: { weight: 15, fields: ['#seo_title', '#seo_description', '#seo_keywords'] }
        };
        var total_w = 0, total_s = 0;
        Object.keys(tabs).forEach(function (tab) {
            var cfg = tabs[tab], pct = 0;
            total_w += cfg.weight;
            if (cfg.fields) {
                pct = Math.round(cfg.fields.filter(function (s) { var v = $(s).val(); return v && v.trim(); }).length / cfg.fields.length * 100);
            } else if (cfg.check) {
                pct = Math.min(100, cfg.check() * 33);
            } else if (cfg.checks) {
                pct = Math.round(cfg.checks.filter(function (fn) { return fn(); }).length / cfg.checks.length * 100);
            }
            total_s += pct * cfg.weight;
            var $el = $('#prog-' + tab);
            if ($el.length) {
                var color = pct >= 80 ? '#27ae60' : pct >= 50 ? '#f39c12' : '#c0392b';
                $el.text(pct + '%').css('color', color);
                $el.closest('.ckg-prog-tab').css('border-bottom-color', color);
            }
        });
        var overall = Math.round(total_s / (total_w * 100) * 100);
        $('#ckg-prog-fill').css({ width: overall + '%', background: overall >= 80 ? '#27ae60' : overall >= 50 ? '#f39c12' : '#c0392b' });
        $('#ckg-prog-label').text(overall + '% completado');
    }

    /* 
       loadFormData -- FUNCION CENTRAL
       Usada por: AutoFill, Clonar, Plantillas, Competidores, JSON
     */
    function loadFormData(data) {
        if (!data) return;

        // Campos basicos con ID
        var byId = { product_name: '#product_name', brand: '#brand', sku: '#sku',
                     seo_title: '#seo_title', seo_description: '#seo_description',
                     seo_keywords: '#seo_keywords', seo_secondary_keywords: '#seo_secondary_keywords' };
        Object.keys(byId).forEach(function (k) { if (data[k] !== undefined && data[k] !== '') $(byId[k]).val(data[k]); });

        // Campos con name=
        ['short_desc','intro','conclusion','catalog_url','catalog_label',
         'revision_date','compat_nota','attribute_name',
         'video_url','video_title','video_description'].forEach(function (n) {
            if (data[n]) $('[name="' + n + '"]').val(data[n]);
        });

        if (data.categories) $('input[name="categories"]').val(Array.isArray(data.categories) ? data.categories.join(', ') : data.categories);
        if (data.tags)       $('input[name="tags"]').val(Array.isArray(data.tags) ? data.tags.join(', ') : data.tags);
        if (data.images && data.images.length) { $('#images_urls').val(data.images.join('\n')); renderImgPreview(); }

        // Repeaters
        function fillRepeater(bodyId, tplId, items, fillFn) {
            if (!items || !items.length) return;
            var $tb = $('#' + bodyId).empty();
            items.forEach(function (item) {
                var tpl = $('#' + tplId).html();
                if (!tpl) return;
                var $r = $(tpl);
                fillFn($r, item);
                $tb.append($r);
            });
        }

        fillRepeater('homo-body', 'tmpl-homo-row', data.homologacion, function ($r, h) {
            $r.find('input[name="homo_tipo[]"]').val(h.tipo || '');
            $r.find('input[name="homo_codigo[]"]').val(h.codigo || '');
            $r.find('input[name="homo_desc[]"]').val(h.descripcion || '');
        });

        fillRepeater('var-body', 'tmpl-var-row', data.variations, function ($r, v) {
            if (data.attribute_name) $('#attribute_name').val(data.attribute_name);
            $r.find('input[name="var_label[]"]').val(v.label || '');
            $r.find('input[name="var_price[]"]').val(v.price || '');
            $r.find('input[name="var_sku[]"]').val(v.sku || '');
        });

        fillRepeater('specs-body', 'tmpl-spec-row', data.specs, function ($r, s) {
            $r.find('input[name="spec_key[]"]').val(s.key || '');
            $r.find('input[name="spec_value[]"]').val(s.value || '');
        });

        fillRepeater('faq-body', 'tmpl-faq-row', data.faq, function ($r, f) {
            $r.find('input[name="faq_pregunta[]"]').val(f.pregunta || '');
            $r.find('textarea[name="faq_resp[]"]').val(f.respuesta || '');
        });

        fillRepeater('caract-body', 'tmpl-caract-row', data.caracteristicas, function ($r, c) {
            $r.find('input[name="caract_titulo[]"]').val(c.titulo || '');
            $r.find('textarea[name="caract_texto[]"]').val(c.texto || '');
        });

        fillRepeater('catuso-body', 'tmpl-catuso-row', data.categorias_uso, function ($r, cu) {
            $r.find('input[name="catuso_titulo[]"]').val(cu.titulo || '');
            $r.find('textarea[name="catuso_texto[]"]').val(cu.texto || '');
        });

        fillRepeater('acc-body', 'tmpl-acc-row', data.accessories, function ($r, a) {
            $r.find('input[name="acc_name[]"]').val(a.name || '');
            $r.find('input[name="acc_desc[]"]').val(a.description || '');
            $r.find('input[name="acc_price[]"]').val(a.price || 0);
            $r.find('input[name="acc_image[]"]').val(a.image || '');
        });

        if (data.compatibilidad) {
            if (data.compatibilidad.chasis && data.compatibilidad.chasis.length)     $('#compat_chasis').val(data.compatibilidad.chasis.join('\n'));
            if (data.compatibilidad.categorias && data.compatibilidad.categorias.length) $('input[name="compat_cats"]').val(data.compatibilidad.categorias.join(', '));
        }
        if (data.badge_envio)     $('input[name="badge_envio"]').prop('checked', true);
        if (data.badge_garantia)  $('input[name="badge_garantia"]').prop('checked', true);
        if (data.badge_inter)     $('input[name="badge_inter"]').prop('checked', true);
        if (data.homo_badge_text) $('input[name="homo_badge_text"]').val(data.homo_badge_text);

        setTimeout(function () {
            updateCount('seo_title', 'ckg-title-count', 60);
            updateCount('seo_description', 'ckg-desc-count', 155);
            calcCompleteness();
            renderImgPreview();
        }, 200);
    }

    /* 
       TABS
     */
    $(document).on('click', '.ckg-tab', function () {
        var t = $(this).data('target');
        $('.ckg-tab').removeClass('active');
        $('.ckg-tab-panel').removeClass('active');
        $(this).addClass('active');
        $('#' + t).addClass('active');
        if (t === 'tab-seo') initSnippetPreview();
        if (t === 'tab-ai')  addGenerateAllBtn();
    });

    $(document).on('click', '.ckg-prog-tab', function () {
        $('.ckg-tab[data-target="' + $(this).data('tab') + '"]').trigger('click');
    });

    /* 
       REPEATERS
     */
    $(document).on('click', '.ckg-rm', function () {
        var $tb = $(this).closest('tbody');
        $tb.find('.ckg-row').length > 1 ? $(this).closest('.ckg-row').remove() : alert('Debe quedar al menos una fila.');
    });
    $(document).on('click', '.ckg-add-row', function () {
        var tpl = $('#tmpl-' + $(this).data('tpl') + '-row').html();
        if (tpl) $('#' + $(this).data('body')).append(tpl);
    });
    $('#add-var').on('click', function () { $('#var-body').append($('#tmpl-var-row').html()); });
    $('#add-faq').on('click', function () { $('#faq-body').append($('#tmpl-faq-row').html()); });
    $('#add-acc').on('click', function () { $('#acc-body').append($('#tmpl-acc-row').html()); });

    /* 
       SEO COUNTERS + SNIPPET PREVIEW
     */
    $('#seo_title').on('input',       function () { updateCount('seo_title', 'ckg-title-count', 60); updateSnippetPreview(); });
    $('#seo_description').on('input', function () { updateCount('seo_description', 'ckg-desc-count', 155); updateSnippetPreview(); });
    $('#seo_keywords, #product_name, #product_slug').on('input', updateSnippetPreview);

    function sanitizeSlug(name) {
        return name.toLowerCase().replace(/[aaa]/g,'a').replace(/[eee]/g,'e').replace(/[iii]/g,'i').replace(/[ooo]/g,'o').replace(/[uuu]/g,'u').replace(/n/g,'n').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    }

    function updateSnippetPreview() {
        var $p = $('#snippet-preview');
        if (!$p.length) return;
        var title = $('#seo_title').val().trim() || $('#product_name').val().trim() + ' | CMSKart';
        var desc  = $('#seo_description').val().trim() || 'Descripcion del producto en CMSKart.es';
        var kw    = $('#seo_keywords').val().trim();
        var url   = 'https://cmskart.es/producto/' + (sanitizeSlug($('#product_name').val()) || 'nombre') + '/';

        function hl(text) {
            if (!kw) return $('<span>').text(text).html();
            var re = new RegExp('(' + kw.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
            return $('<span>').text(text).html().replace(re,'<strong>$1</strong>');
        }

        var tl = title.length, dl = desc.length;
        $p.find('#snip-title').html(hl(tl > 60 ? title.substr(0,57)+'...' : title));
        $p.find('#snip-url').text(url);
        $p.find('#snip-desc').html(hl(dl > 155 ? desc.substr(0,152)+'...' : desc));
        $p.find('#snip-title-len').text(tl+'/60').css('color', tl>=50&&tl<=60?'#27ae60':tl>60?'#c0392b':'#e67e22');
        $p.find('#snip-desc-len').text(dl+'/155').css('color', dl>=140&&dl<=155?'#27ae60':dl>155?'#c0392b':'#e67e22');
    }

    function initSnippetPreview() {
        if ($('#snippet-preview').length) return;
        $('#tab-seo .ckg-card').append(
            '<hr><h3> Vista previa en Google</h3>' +
            '<div id="snippet-preview" class="ckg-snippet-preview">' +
            '<div style="display:flex;align-items:center;gap:8px"><span id="snip-title" class="snip-title"></span><span id="snip-title-len" class="ckg-len-badge"></span></div>' +
            '<div id="snip-url" class="snip-url"></div>' +
            '<div style="display:flex;align-items:flex-start;gap:8px"><div id="snip-desc" class="snip-desc" style="flex:1"></div><span id="snip-desc-len" class="ckg-len-badge"></span></div>' +
            '</div>'
        );
        updateSnippetPreview();
    }

    /* 
       IMAGENES
     */
    $('#images_urls').on('input', renderImgPreview);
    $(document).on('click', '.ckg-img-remove', function () {
        var u = $(this).data('url');
        $('#images_urls').val($('#images_urls').val().split('\n').filter(function (l) { return l.trim() !== u; }).join('\n'));
        renderImgPreview();
    });
    $('#ckg-open-gallery-picker').on('click', function () {
        var _media = wp.media({ title:'Imagenes', button:{text:'Anadir'}, multiple:true, library:{type:'image'} });
        _media.on('select', function () {
            var urls = [];
            _media.state().get('selection').each(function (a) { urls.push(a.toJSON().url); });
            var cur = $('#images_urls').val().trim();
            $('#images_urls').val(cur ? cur+'\n'+urls.join('\n') : urls.join('\n'));
            renderImgPreview();
        });
        _media.open();
    });
    $(document).on('click', '.ckg-media-btn', function () {
        var $input = $(this).prev('.ckg-url-field');
        if (!$input.length) return;
        var _mBtn = wp.media({ title:'Seleccionar', button:{text:'Usar'}, multiple:false, library:{type:($(this).data('type')||'image').split(',')} });
        _mBtn.on('select', function () {
            var att = _mBtn.state().get('selection').first().toJSON();
            $input.val(att.url).trigger('change');
            var $w = $input.closest('td,.ckg-media-row');
            $w.find('.ckg-media-thumb-preview').remove();
            if (/\.(jpg|jpeg|png|gif|webp)/i.test(att.url)) $w.append('<span class="ckg-media-thumb-preview"><img src="'+att.url+'"></span>');
        });
        _mBtn.open();
    });

    /* 
       COMPLETITUD
     */
    $(document).on('input change', 'input, textarea, select', function () {
        clearTimeout(window._ckgProgTimer);
        window._ckgProgTimer = setTimeout(calcCompleteness, 300);
    });

    /* 
       ATRIBUTOS WOOCOMMERCE
     */
    var _wcAttrs = [];
    $('#btn-load-attrs').on('click', function () {
        var $btn = $(this).text('Cargando...').prop('disabled', true);
        $.post(ajax, { action:'ckg_get_attributes', nonce:nonce }, function (r) {
            $btn.text('Cargar atributos').prop('disabled', false);
            if (!r.success || !r.data.length) { alert('Sin atributos en WooCommerce.'); return; }
            _wcAttrs = r.data;
            var $s = $('#attr-selector').empty().append('<option value="">-- Seleccionar --</option>');
            r.data.forEach(function (a, i) { $s.append('<option value="'+i+'">'+a.label+' ('+a.terms.length+')</option>'); });
            $('#attr-selector-wrap').show();
        }).fail(function () { $btn.text('Cargar atributos').prop('disabled', false); });
    });
    $('#attr-selector').on('change', function () {
        var idx = $(this).val(), $pre = $('#attr-terms-preview').empty();
        if (idx === '' || !_wcAttrs[idx]) return;
        $pre.html('<div class="ckg-terms-grid">'+_wcAttrs[idx].terms.map(function(t){return '<span class="ckg-term-chip">'+t+'</span>';}).join('')+'</div><p class="ckg-terms-note">'+_wcAttrs[idx].terms.length+' variaciones.</p>');
    });
    $('#btn-import-attr').on('click', function () {
        var idx = $('#attr-selector').val();
        if (idx === '') { alert('Selecciona un atributo.'); return; }
        var attr = _wcAttrs[idx];
        $('#attribute_name').val(attr.label);
        var $tb = $('#var-body').empty();
        (attr.terms.length ? attr.terms : ['']).forEach(function (t) {
            var $r = $($('#tmpl-var-row').html());
            $r.find('input[name="var_label[]"]').val(t);
            $tb.append($r);
        });
        $('#attr-selector-wrap').hide();
        $('.ckg-tab[data-target="tab-variations"]').trigger('click');
        toast(' "'+attr.label+'" importado con '+attr.terms.length+' variaciones.');
    });

    /* 
       COMPATIBILIDAD WC
     */
    $('#btn-load-compat').on('click', function () {
        var $btn = $(this).text('Cargando...').prop('disabled', true);
        Promise.all([$.post(ajax,{action:'ckg_get_categories',nonce:nonce}), $.post(ajax,{action:'ckg_get_brands',nonce:nonce})]).then(function (res) {
            $btn.text('Cargar categorias y marcas').prop('disabled', false);
            var cats = res[0].success ? res[0].data : [];
            var brands = res[1].success ? res[1].data : [];
            var $cl = $('#compat-cats-list').empty(), $bl = $('#compat-brands-list').empty();
            cats.length ? cats.forEach(function(c){$cl.append('<label class="ckg-chk-label"><input type="checkbox" class="compat-cat-chk" value="'+c.name+'"> '+(c.parent>0?'> ':'')+c.name+'</label>');}) : $cl.html('<em>Sin categorias.</em>');
            brands.length ? brands.forEach(function(b){$bl.append('<label class="ckg-chk-label"><input type="checkbox" class="compat-brand-chk" value="'+b.name+'"> '+b.name+'</label>');}) : $bl.html('<em>Sin marcas.</em>');
            $('#compat-wc-panel').show();
        }).catch(function () { $btn.text('Cargar categorias y marcas').prop('disabled', false); });
    });
    $('#btn-apply-cats').on('click', function () {
        var sel = $('.compat-cat-chk:checked').map(function(){return this.value;}).get();
        if (!sel.length) { alert('Marca al menos una.'); return; }
        var cur = $('#compat_cats').val().trim();
        $('#compat_cats').val(cur ? cur+', '+sel.join(', ') : sel.join(', '));
        $('.compat-cat-chk:checked').prop('checked', false);
        toast(' '+sel.length+' categorias anadidas.');
    });
    $('#btn-apply-brands').on('click', function () {
        var sel = $('.compat-brand-chk:checked').map(function(){return this.value;}).get();
        if (!sel.length) { alert('Marca al menos una.'); return; }
        var cur = $('#compat_chasis').val().trim();
        $('#compat_chasis').val(cur ? cur+'\n'+sel.join('\n') : sel.join('\n'));
        $('.compat-brand-chk:checked').prop('checked', false);
        toast(' '+sel.length+' marcas anadidas.');
    });

    /* 
       BUSQUEDA DE PRODUCTOS (COMPLEMENTOS)
     */
    function loadCategoriesInSelect(sel) {
        $.post(ajax, { action:'ckg_get_categories', nonce:nonce }, function (r) {
            if (!r.success) return;
            var $s = $(sel).empty().append('<option value="">Todas las categorias</option>');
            r.data.forEach(function(c){ $s.append('<option value="'+c.id+'">'+(c.parent>0?'> ':'')+c.name+'</option>'); });
        });
    }
    function doProductSearch() {
        var q = $('#ps-query').val().trim(), cat = $('#ps-category').val();
        if (!q && !cat) { alert('Nombre o categoria requeridos.'); return; }
        $('#ps-results').html('<div class="ckg-loading"> Buscando...</div>');
        $('#btn-add-selected-products').hide();
        $.post(ajax, { action:'ckg_search_products', nonce:nonce, query:q, category_id:cat, per_page:24 }, function (r) {
            if (!r.success || !r.data.length) { $('#ps-results').html('<p class="ckg-no-results">Sin resultados.</p>'); return; }
            var html = '<div class="ckg-ps-grid">';
            r.data.forEach(function(p){
                html += '<label class="ckg-ps-card"><input type="checkbox" class="ps-product-chk" data-name="'+p.name+'" data-price="'+p.price+'" data-image="'+p.image+'" data-image-full="'+(p.image_full||p.image)+'" data-sku="'+(p.sku||'')+'">'+( p.image?'<img src="'+p.image+'" alt="">':'<div class="ckg-ps-no-img"></div>')+'<span class="ckg-ps-name">'+p.name+'</span><span class="ckg-ps-price">'+(p.price>0?p.price.toFixed(2).replace('.',',')+'EUR':'--')+'</span></label>';
            });
            $('#ps-results').html(html+'</div>');
        }).fail(function(){ $('#ps-results').html('<p class="ckg-no-results">Error.</p>'); });
    }
    $('#btn-init-product-search').on('click', function () {
        $('#product-search-panel').toggle();
        if ($('#ps-category option').length <= 1) loadCategoriesInSelect('#ps-category');
    });
    $(document).on('change', '.ps-product-chk', function () { $('#btn-add-selected-products').toggle($('.ps-product-chk:checked').length > 0); });
    $('#btn-do-search').on('click', doProductSearch);
    $('#ps-query').on('keypress', function (e) { if (e.key === 'Enter') { e.preventDefault(); doProductSearch(); } });
    $('#btn-add-selected-products').on('click', function () {
        var $tb = $('#acc-body'), count = 0;
        $('.ps-product-chk:checked').each(function () {
            var $r = $($('#tmpl-acc-row').html());
            var _price = parseFloat($(this).data('price')) || 0;
            $r.find('input[name="acc_name[]"]').val($(this).data('name'));
            $r.find('input[name="acc_desc[]"]').val($(this).data('sku') ? 'Ref: '+$(this).data('sku') : '');
            $r.find('input[name="acc_price[]"]').val(_price > 0 ? _price.toFixed(2) : '');
            $r.find('input[name="acc_image[]"]').val($(this).data('image-full') || $(this).data('image') || '');
            $tb.append($r); count++;
        });
        $('.ps-product-chk').prop('checked', false);
        $('#btn-add-selected-products').hide();
        $('#product-search-panel').hide();
        toast(' '+count+' complemento(s) anadido(s).');
    });

    /* 
       PLANTILLAS + HOMOLOGACIONES POR MARCA
     */
    $('#btn-load-template').on('click', function () {
        var id = $('#template-selector').val();
        if (!id) { alert('Selecciona una plantilla.'); return; }
        if (!confirm('Aplicar plantilla? Se rellenaran los campos vacios.')) return;
        var $btn = $(this).html(spin('Cargando...')).prop('disabled', true);
        $.post(ajax, { action:'ckg_load_template', nonce:nonce, template_id:id }, function (r) {
            $btn.html(' Aplicar plantilla').prop('disabled', false);
            if (!r.success) { alert(r.data.message); return; }
            loadFormData(r.data);
            toast(' Plantilla aplicada. Revisa Homologacion y Contenido SEO.');
        }).fail(function(){ $btn.html(' Aplicar plantilla').prop('disabled', false); });
    });

    var _homoTimer;
    $('#brand').on('input', function () {
        clearTimeout(_homoTimer);
        var brand = $(this).val().trim();
        if (brand.length < 3) return;
        _homoTimer = setTimeout(function () {
            $.post(ajax, { action:'ckg_get_brand_homos', nonce:nonce, brand:brand }, function (r) {
                if (!r.success || !r.data.length) return;
                if (!confirm(r.data.length+' homologaciones detectadas para "'+brand+'". Anadirlas?')) return;
                loadFormData({ homologacion: r.data });
                toast(' Homologaciones de '+brand+' anadidas.');
            });
        }, 800);
    });

    /* 
       CATEGORIAS WC EN "PARA QUE CATEGORIA?"
     */
    $('#btn-load-catuso-wc').on('click', function () {
        var $btn = $(this).html(spin('Cargando...')).prop('disabled', true);
        $.post(ajax, { action:'ckg_get_categories', nonce:nonce }, function (r) {
            $btn.html(' Cargar categorias de WooCommerce').prop('disabled', false);
            if (!r.success || !r.data.length) { alert('Sin categorias.'); return; }
            var $list = $('#catuso-wc-list').empty();
            r.data.forEach(function(c){ $list.append('<label class="ckg-chk-label"><input type="checkbox" class="catuso-wc-chk" value="'+c.name+'"> '+(c.parent>0?'> ':'')+c.name+'</label>'); });
            $('#catuso-wc-panel').show();
        }).fail(function(){ $btn.html(' Cargar categorias de WooCommerce').prop('disabled', false); });
    });
    $('#btn-apply-catuso-wc').on('click', function () {
        var sel = $('.catuso-wc-chk:checked').map(function(){return this.value;}).get();
        if (!sel.length) { alert('Marca al menos una.'); return; }
        var $tb = $('#catuso-body');
        if ($tb.find('.ckg-row').length===1 && !$tb.find('input[name="catuso_titulo[]"]').val()) $tb.empty();
        sel.forEach(function(name){ var $r=$($('#tmpl-catuso-row').html()); $r.find('input[name="catuso_titulo[]"]').val(name); $tb.append($r); });
        $('.catuso-wc-chk:checked').prop('checked', false);
        $('#catuso-wc-panel').hide();
        toast(' '+sel.length+' categorias anadidas.');
    });

    /* 
       OLLAMA / LLM -- MEJORAR CAMPOS INDIVIDUALES
     */
    $(document).on('click', '.ckg-improve-btn', function () {
        var $btn    = $(this);
        var field   = $btn.data('field');
        var section = $btn.data('section') || 'caracteristica';
        var $target = $('#'+field).length ? $('#'+field) : $('[name="'+field+'"]').first();
        var content = $target.val().trim();
        var ctx     = getOllamaContext();
        var isSeo   = (section==='seo_title'||section==='seo_description');

        if (!content && isSeo) {
            if (!ctx.product_name) { toast(' Rellena el nombre del producto primero.','warn'); $('.ckg-tab[data-target="tab-basic"]').trigger('click'); return; }
            $btn.html(spin('Generando...')).prop('disabled', true);
            $.post(ajax, { action:'ckg_generate_seo_from_scratch', nonce:nonce, section:section, product_name:ctx.product_name, brand:ctx.brand, sku:ctx.sku, short_desc:$('[name="short_desc"]').val(), homologaciones:ctx.homologaciones }, function (r) {
                $btn.html('').prop('disabled', false);
                if (!r.success) { toast(' '+r.data.message,'err'); return; }
                $target.val(r.data.text).trigger('input').addClass('ckg-field-improved');
                setTimeout(function(){ $target.removeClass('ckg-field-improved'); }, 2500);
                toast(' Generado ('+r.data.text.length+' chars).');
            }).fail(function(){ $btn.html('').prop('disabled', false); toast(' Error.','err'); });
            return;
        }
        if (!content) { toast(' El campo esta vacio.','warn'); return; }
        $btn.html(spin('Mejorando...')).prop('disabled', true);
        $.post(ajax, { action:'ckg_ollama_improve', nonce:nonce, section:section, content:content, product_name:ctx.product_name, brand:ctx.brand, sku:ctx.sku, homologaciones:ctx.homologaciones }, function (r) {
            $btn.html('').prop('disabled', false);
            if (!r.success) { toast(' '+r.data.message,'err'); return; }
            $target.val(r.data.improved).trigger('input').addClass('ckg-field-improved');
            setTimeout(function(){ $target.removeClass('ckg-field-improved'); }, 2500);
            toast(' Mejorado con IA.');
        }).fail(function(){ $btn.html('').prop('disabled', false); toast(' Error.','err'); });
    });

    /*  Mejorar TODO  */
    $('#btn-improve-all').on('click', function () {
        var $btn = $(this), ctx = getOllamaContext(), fields = {};
        var fm = { short_desc:$('[name="short_desc"]').val(), intro:$('[name="intro"]').val(), conclusion:$('[name="conclusion"]').val(), compat_nota:$('[name="compat_nota"]').val(), seo_title:$('#seo_title').val(), seo_description:$('#seo_description').val() };
        Object.keys(fm).forEach(function(k){ if (fm[k]&&fm[k].trim()) fields[k]=fm[k]; });
        if (!Object.keys(fields).length) { toast(' Sin campos con contenido.','warn'); return; }
        if (!confirm('Ollama mejorara los campos de texto. Continuar?')) return;
        $btn.html(spin('Mejorando todo...')).prop('disabled', true);
        $.post(ajax, { action:'ckg_ollama_improve_all', nonce:nonce, fields:fields, product_name:ctx.product_name, brand:ctx.brand, sku:ctx.sku, homologaciones:ctx.homologaciones }, function (r) {
            $btn.html(' Mejorar TODO con Ollama').prop('disabled', false);
            if (!r.success) { toast(' '+r.data.message,'err'); return; }
            var res = r.data.results;
            if (res.short_desc)      $('[name="short_desc"]').val(res.short_desc);
            if (res.intro)           $('[name="intro"]').val(res.intro);
            if (res.conclusion)      $('[name="conclusion"]').val(res.conclusion);
            if (res.compat_nota)     $('[name="compat_nota"]').val(res.compat_nota);
            if (res.seo_title)       $('#seo_title').val(res.seo_title).trigger('input');
            if (res.seo_description) $('#seo_description').val(res.seo_description).trigger('input');
            toast(' '+Object.keys(res).length+' campo(s) mejorados.');
        }).fail(function(){ $btn.html(' Mejorar TODO con Ollama').prop('disabled', false); toast(' Error.','err'); });
    });

    /*  Test conexion  */
    $('#btn-test-ollama').on('click', function () {
        var $btn = $(this).html(spin('Probando...')).prop('disabled', true);
        $('#ollama-status').html('');
        $.post(ajax, { action:'ckg_ollama_ping', nonce:nonce }, function (r) {
            $btn.html(' Probar conexion').prop('disabled', false);
            if (!r.success) { $('#ollama-status').html('<span class="ckg-err"> Error.</span>'); return; }
            var d = r.data;
            $('#ollama-status').html(d.online
                ? '<span class="ckg-ok">'+(d.message||' Conectado')+'</span>'+(d.models&&d.models.length?' - '+d.models.map(function(m){return '<span class="ckg-term-chip">'+m+'</span>';}).join(' '):'')
                : '<span class="ckg-err"> '+(d.message||'Sin conexion')+'</span>');
        }).fail(function(){ $btn.html(' Probar conexion').prop('disabled', false); $('#ollama-status').html('<span class="ckg-err"> Error.</span>'); });
    });

    /*  FAQ: generar respuestas  */
    $('#btn-generate-faq-answers').on('click', function () {
        var $btn = $(this).html(spin('Generando...')).prop('disabled', true);
        var ctx = getOllamaContext();
        if (!ctx.product_name) { toast(' Nombre del producto requerido.','warn'); $btn.html(' Generar respuestas vacias con Ollama').prop('disabled', false); return; }
        var empty = [];
        $('#faq-body .ckg-row').each(function(i){ var p=$(this).find('input[name="faq_pregunta[]"]').val().trim(), r=$(this).find('textarea[name="faq_resp[]"]').val().trim(); if (p&&!r) empty.push({index:i,pregunta:p}); });
        if (!empty.length) { toast('i Todas las preguntas ya tienen respuesta.'); $btn.html(' Generar respuestas vacias con Ollama').prop('disabled', false); return; }
        $.post(ajax, { action:'ckg_generate_faq_answers', nonce:nonce, product_name:ctx.product_name, brand:ctx.brand, questions:empty.map(function(e){return e.pregunta;}), homologaciones:ctx.homologaciones }, function (r) {
            $btn.html(' Generar respuestas vacias con Ollama').prop('disabled', false);
            if (!r.success) { toast(' '+r.data.message,'err'); return; }
            var ans = r.data.answers, $rows = $('#faq-body .ckg-row');
            empty.forEach(function(e,i){ if (ans[i]) $($rows[e.index]).find('textarea[name="faq_resp[]"]').val(ans[i]).addClass('ckg-field-improved'); });
            setTimeout(function(){ $('.ckg-field-improved').removeClass('ckg-field-improved'); }, 2500);
            toast(' '+ans.length+' respuestas generadas.');
        }).fail(function(){ $btn.html(' Generar respuestas vacias con Ollama').prop('disabled', false); });
    });

    /*  LSI Keywords  */
    $('#btn-generate-lsi').on('click', function () {
        var $btn = $(this).html(spin('Generando...')).prop('disabled', true);
        var ctx = getOllamaContext();
        if (!ctx.product_name) { toast(' Nombre requerido.','warn'); $btn.html(' Generar 12 LSI keywords con Ollama').prop('disabled', false); return; }
        var specs=[],homos=[];
        $('#specs-body .ckg-row').each(function(){ var k=$(this).find('input[name="spec_key[]"]').val(); if(k) specs.push({key:k,value:$(this).find('input[name="spec_value[]"]').val()}); });
        $('#homo-body .ckg-row').each(function(){ var t=$(this).find('input[name="homo_tipo[]"]').val(); if(t) homos.push({tipo:t,codigo:$(this).find('input[name="homo_codigo[]"]').val()}); });
        $.post(ajax, { action:'ckg_generate_lsi', nonce:nonce, product_name:ctx.product_name, brand:ctx.brand, homologaciones:homos, specs:specs }, function (r) {
            $btn.html(' Generar 12 LSI keywords con Ollama').prop('disabled', false);
            if (!r.success) { $('#lsi-status').text(' '+r.data.message); return; }
            var kws = r.data.keywords;
            $('#lsi-status').text(kws.length+' keywords -- clic para anadir:');
            var $chips = $('#lsi-chips').empty();
            kws.forEach(function(kw){ $chips.append('<button type="button" class="ckg-lsi-chip" data-kw="'+kw+'">'+kw+'</button>'); });
            $chips.append('<button type="button" class="button ckg-lsi-add-all">+ Anadir todas</button>');
        }).fail(function(){ $btn.html(' Generar 12 LSI keywords con Ollama').prop('disabled', false); });
    });
    $(document).on('click', '.ckg-lsi-chip', function(){ var kw=$(this).data('kw'),cur=$('#seo_secondary_keywords').val().trim(); $('#seo_secondary_keywords').val(cur?cur+', '+kw:kw); $(this).addClass('ckg-lsi-chip--added').prop('disabled',true); });
    $(document).on('click', '.ckg-lsi-add-all', function(){ var all=$('.ckg-lsi-chip').map(function(){return $(this).data('kw');}).get(); $('#seo_secondary_keywords').val(all.join(', ')); $('.ckg-lsi-chip').addClass('ckg-lsi-chip--added').prop('disabled',true); toast(' '+all.length+' keywords anadidas.'); });

    /* 
       ANALISIS SEO
     */
    $('#btn-seo-analyze').on('click', function () {
        var $btn=$(this).html(spin('Analizando...')).prop('disabled',true);
        var ctx=getOllamaContext();
        var data={product_name:ctx.product_name,brand:ctx.brand,sku:ctx.sku,short_desc:$('[name="short_desc"]').val(),intro:$('[name="intro"]').val(),conclusion:$('[name="conclusion"]').val(),seo_title:$('#seo_title').val(),seo_description:$('#seo_description').val(),seo_keywords:$('#seo_keywords').val(),seo_secondary_keywords:$('#seo_secondary_keywords').val(),images:$('#images_urls').val().split('\n').filter(Boolean),specs:[],faq:[],homologacion:[],variations:[],caracteristicas:[]};
        $('#specs-body .ckg-row').each(function(){var k=$(this).find('input[name="spec_key[]"]').val();if(k) data.specs.push({key:k,value:$(this).find('input[name="spec_value[]"]').val()});});
        $('#faq-body .ckg-row').each(function(){var p=$(this).find('input[name="faq_pregunta[]"]').val();if(p) data.faq.push({pregunta:p,respuesta:$(this).find('textarea[name="faq_resp[]"]').val()});});
        $('#homo-body .ckg-row').each(function(){var t=$(this).find('input[name="homo_tipo[]"]').val();if(t) data.homologacion.push({tipo:t,codigo:$(this).find('input[name="homo_codigo[]"]').val()});});
        $('#var-body .ckg-row').each(function(){var l=$(this).find('input[name="var_label[]"]').val();if(l) data.variations.push({label:l,price:$(this).find('input[name="var_price[]"]').val()});});
        $.post(ajax,{action:'ckg_seo_analyze',nonce:nonce,form_data:JSON.stringify(data)},function(r){
            $btn.html(' Analizar SEO').prop('disabled',false);
            if(!r.success){toast(' Error.','err');return;}
            var d=r.data;
            $('#seo-score-num').text(d.score);
            $('#seo-score-circle').css({'border-color':d.color,'color':d.color});
            $('#seo-grade').text(d.grade+' - '+d.word_count+' palabras');
            var html='';
            d.checks.forEach(function(c){html+='<div class="ckg-check-item ckg-check-'+c.status+'">'+(c.status==='ok'?'':c.status==='warn'?'':'')+' '+c.msg+(c.pts>0?' <small>(+'+c.pts+'pts)</small>':'')+' </div>';});
            $('#seo-checklist').html(html);
            $('#seo-summary').html('<strong>'+d.summary+'</strong>');
        }).fail(function(){$btn.html(' Analizar SEO').prop('disabled',false);});
    });

    /* 
       ANALISIS COMPETITIVO
     */
    $('#btn-competitive-seo').on('click', function () {
        var kw=$('#seo_keywords').val().trim();
        if(!kw){toast(' Focus Keyword requerida.','warn');return;}
        var $btn=$(this).html(spin('Analizando...')).prop('disabled',true);
        $('#competitive-results').html('<div class="ckg-loading"> Buscando en Google para "'+kw+'"...</div>');
        var ctx=getOllamaContext();
        var our={product_name:ctx.product_name,brand:ctx.brand,seo_keywords:kw,seo_title:$('#seo_title').val(),seo_description:$('#seo_description').val(),intro:$('[name="intro"]').val(),specs:[],faq:[],homologacion:[],images:$('#images_urls').val().split('\n').filter(Boolean)};
        $('#specs-body .ckg-row').each(function(){var k=$(this).find('input[name="spec_key[]"]').val();if(k) our.specs.push({key:k,value:$(this).find('input[name="spec_value[]"]').val()});});
        $('#faq-body .ckg-row').each(function(){var p=$(this).find('input[name="faq_pregunta[]"]').val();if(p) our.faq.push({pregunta:p});});
        $.post(ajax,{action:'ckg_competitive_seo',nonce:nonce,keyword:kw,form_data:JSON.stringify(our)},function(r){
            $btn.html(' Analizar vs competidores en Google').prop('disabled',false);
            if(!r.success){$('#competitive-results').html('<span class="ckg-err"> '+r.data.message+'</span>');return;}
            renderCompetitorResults(r.data);
        }).fail(function(){$btn.html(' Analizar vs competidores en Google').prop('disabled',false);$('#competitive-results').html('<span class="ckg-err"> Error.</span>');});
    });

    function renderCompetitorResults(data) {
        var scores=data.scores||{},comps=data.competitors||[],recs=data.recommendations||'';
        var oc=scores.overall||0,ocCol=oc>=80?'#27ae60':oc>=60?'#f39c12':'#c0392b',adv=scores.advantage||0;
        var html='<div class="ckg-comp-analysis">';
        html+='<div class="ckg-comp-score-header"><div class="ckg-comp-score-block"><div class="ckg-comp-score-circle" style="border-color:'+ocCol+';color:'+ocCol+'">'+oc+'</div><div class="ckg-comp-score-label">Tu producto</div></div><div class="ckg-comp-vs">vs</div><div class="ckg-comp-score-block"><div class="ckg-comp-score-circle" style="border-color:#50575e;color:#50575e">'+(scores.comp_avg||55)+'</div><div class="ckg-comp-score-label">Media Google</div></div><div class="ckg-comp-advantage" style="color:'+(adv>=0?'#27ae60':'#c0392b')+'">'+(adv>=0?'':'')+' '+Math.abs(adv)+' pts</div></div>';
        // Boton aplicar todo
        var syn=data.synthesis||{};
        if(syn.intro||syn.conclusion){
            html+='<div class="ckg-comp-synth" style="background:#1a1a2e"><h4 style="color:#f39c12"> Aplicar al formulario</h4>';
            if(syn.intro)      html+='<button type="button" class="button button-primary ckg-apply-synth" data-field="intro"      data-value="'+encodeURIComponent(syn.intro)+'"> Introduccion</button> ';
            if(syn.conclusion) html+='<button type="button" class="button button-primary ckg-apply-synth" data-field="conclusion" data-value="'+encodeURIComponent(syn.conclusion)+'"> Conclusion</button>';
            html+='</div>';
        }
        if(scores.details&&scores.details.length){
            html+='<table class="ckg-comp-metrics-table"><thead><tr><th>Metrica</th><th>Tuyo</th><th>Comp.</th><th></th></tr></thead><tbody>';
            scores.details.forEach(function(d){html+='<tr class="ckg-comp-row-'+d.status+'"><td>'+d.label+'</td><td>'+d.format+'</td><td>'+d.format_avg+'</td><td>'+(d.status==='ok'?'':d.status==='warn'?'':'')+'</td></tr>';});
            html+='</tbody></table>';
        }
        if(syn.specs&&syn.specs.length){
            html+='<div class="ckg-comp-synth"><h4> Specs ('+syn.specs.length+')</h4><table class="ckg-comp-specs-table"><thead><tr><th>Param</th><th>Valor</th><th></th></tr></thead><tbody>';
            syn.specs.forEach(function(s){html+='<tr><td>'+s.key+'</td><td>'+s.value+'</td><td><input type="checkbox" class="comp-spec-chk" data-key="'+encodeURIComponent(s.key)+'" data-val="'+encodeURIComponent(s.value)+'" checked></td></tr>';});
            html+='</tbody></table><button type="button" class="button button-primary" id="btn-apply-comp-specs"> Anadir seleccionadas</button></div>';
        }
        if(syn.faq_parsed&&syn.faq_parsed.length){
            html+='<div class="ckg-comp-synth"><h4> FAQ ('+syn.faq_parsed.length+')</h4><ul class="ckg-comp-faq-list">';
            syn.faq_parsed.forEach(function(f){html+='<li><strong>'+f.pregunta+'</strong><br>'+f.respuesta+'</li>';});
            html+='</ul><button type="button" class="button button-primary" id="btn-apply-comp-faq" data-faq="'+encodeURIComponent(JSON.stringify(syn.faq_parsed))+'"> Aplicar FAQ</button></div>';
        }
        if(recs) html+='<div class="ckg-comp-recs"><h4> Recomendaciones:</h4><div class="ckg-comp-recs-text">'+recs.replace(/\n/g,'<br>')+'</div></div>';
        html+='</div>';
        $('#competitive-results').html(html);
    }

    // ?? Aplicar intro/conclusion/seo al formulario ??????????????????
    $(document).on('click', '.ckg-apply-synth', function () {
        var field = decodeURIComponent($(this).data('field') || '');
        var value = decodeURIComponent($(this).data('value') || '');

        // Buscar por ID primero, luego por name
        var $target = $('#' + field).length ? $('#' + field) : $('[name="' + field + '"]');

        if ($target.length) {
            $target.val(value).addClass('ckg-field-improved');
            setTimeout(function () { $target.removeClass('ckg-field-improved'); }, 2000);

            // Si aplicas la intro y el resumen corto esta vacio, lo rellena
            if (field === 'intro' && !$('[name="short_desc"]').val().trim()) {
                $('[name="short_desc"]').val(value.replace(/<[^>]+>/g, '').substring(0, 160) + '...');
            }

            toast('OK: ' + field + ' transferida al formulario.');

            // Navegar al tab correcto segun el campo
            if (field === 'intro' || field === 'conclusion') {
                $('.ckg-tab[data-target="tab-content"]').trigger('click');
            } else if (field === 'seo_title' || field === 'seo_description' || field === 'seo_keywords') {
                $('.ckg-tab[data-target="tab-seo"]').trigger('click');
            }
        } else {
            toast('Campo "' + field + '" no encontrado en el formulario.', 'warn');
        }
    });

    // ?? Aplicar especificaciones tecnicas al formulario ???????????????
    $(document).on('click', '#btn-apply-comp-specs', function () {
        var count = 0;
        $('.comp-spec-chk:checked').each(function () {
            var key = decodeURIComponent($(this).data('key') || '');
            var val = decodeURIComponent($(this).data('val') || '');
            if (!key) return;
            var $r = $($('#tmpl-spec-row').html());
            $r.find('input[name="spec_key[]"]').val(key);
            $r.find('input[name="spec_value[]"]').val(val);
            $('#specs-body').append($r);
            count++;
        });
        if (count > 0) {
            toast('OK: ' + count + ' especificaciones anadidas.');
            if (typeof calcCompleteness === 'function') calcCompleteness();
            $('.ckg-tab[data-target="tab-content"]').trigger('click');
        } else {
            toast('Selecciona al menos una especificacion.', 'warn');
        }
    });

    // ?? Aplicar FAQ al formulario ?????????????????????????????????????
    $(document).on('click', '#btn-apply-comp-faq', function () {
        var faqData;
        try { faqData = JSON.parse(decodeURIComponent($(this).data('faq') || '[]')); } catch(e) { faqData = []; }
        if (!faqData.length) { toast('Sin FAQ para aplicar.', 'warn'); return; }

        var $tb = $('#faq-body');
        // Limpiar solo si la tabla esta vacia o tiene una sola fila vacia
        if ($tb.find('.ckg-row').length <= 1 && !$tb.find('input[name="faq_pregunta[]"]').val().trim()) {
            $tb.empty();
        }

        faqData.forEach(function (f) {
            var $r = $($('#tmpl-faq-row').html());
            $r.find('input[name="faq_pregunta[]"]').val(f.pregunta || '');
            $r.find('textarea[name="faq_resp[]"]').val(f.respuesta || '');
            $tb.append($r);
        });

        toast('OK: ' + faqData.length + ' FAQ aplicadas.');
        if (typeof calcCompleteness === 'function') calcCompleteness();
        $('.ckg-tab[data-target="tab-content"]').trigger('click');
    });

    /* 
       AUTOFILL INLINE (boton en tab Basico)
     */
    function addAutoFillBtn() {
        if ($('#btn-autofill-inline').length) return;
        var $card = $('#tab-basic .ckg-card');
        if (!$card.length) return;
        var pplxBtn = ADMIN.perplexity_available
            ? '<button type="button" class="button" id="btn-perplexity-search" style="background:#fff;border-color:#2272d3;color:#2272d3;font-weight:600" title="Busca specs, precio y homologaciones en internet en tiempo real"> Buscar con Perplexity</button>'
            : '';
        $card.find('h2').after(
            '<div class="ckg-autofill-banner">' +
            '<span> <strong>Modo Express:</strong></span>' +
            '<input type="url" id="autofill-url-inline" class="regular-text" placeholder="URL del fabricante o competidor...">' +
            '<button type="button" class="button button-primary" id="btn-autofill-inline"> Auto-rellenar todo</button>' +
            pplxBtn +
            '<span id="autofill-inline-status" style="font-size:12px;margin-left:8px"></span>' +
            '</div>'
        );
    }
    setTimeout(addAutoFillBtn, 300);
    $(document).on('click', '.ckg-tab[data-target="tab-basic"]', function () { setTimeout(addAutoFillBtn, 100); });

    $(document).on('click','#btn-autofill-inline', function(){
        var url   = $('#autofill-url-inline').val().trim();
        var name  = $('#product_name').val().trim();
        var brand = $('#brand').val().trim();
        if (!url && !name) { alert('URL o nombre del producto requerido.'); return; }

        var $btn    = $(this).html(spin('Analizando URL...')).prop('disabled', true);
        var $status = $('#autofill-inline-status');
        var $log    = $('#autofill-log');

        $status.html('<span style="color:#2271b1">Iniciando pipeline completo...</span>');
        if (!$log.length) {
            $('<div id="autofill-log" style="margin-top:8px;font-size:11px;max-height:120px;overflow-y:auto;background:#f6f7f7;padding:8px;border-radius:4px;display:none"></div>')
                .insertAfter($status);
        }
        $('#autofill-log').show().html('');

        var steps = [
            { key: 'scrape',  label: 'Scrapeando URL con Playwright...' },
            { key: 'qdrant',  label: 'Consultando Qdrant (PDFs fabricante)...' },
            { key: 'llm',     label: 'Generando contenido con IA...' },
            { key: 'fill',    label: 'Rellenando formulario...' },
        ];
        var si = 0;
        var timer = setInterval(function() {
            if (si < steps.length) {
                $status.html('<span style="color:#2271b1">' + spin(steps[si].label) + '</span>');
                si++;
            }
        }, 3000);

        $.post(ajax, {
            action:     'ckg_express_autofill',
            nonce:      nonce,
            url:        url,
            name_hint:  name,
            brand_hint: brand,
            prompt_id:  1,
        }, function(r) {
            clearInterval(timer);
            $btn.html(' Auto-rellenar todo').prop('disabled', false);

            if (!r.success) {
                $status.html('<span style="color:#c0392b"> Error: ' + r.data.message + '</span>');
                if (r.data.log) $('#autofill-log').html(r.data.log.join('<br>'));
                return;
            }

            var d = r.data;
            // Mostrar log del pipeline
            if (d.log && d.log.length) {
                $('#autofill-log').html(d.log.join('<br>'));
            }

            // Rellenar formulario con los datos del pipeline
            if (d.data) {
                loadFormData(d.data);
                var resumen = [];
                if (d.data.product_name) resumen.push('Nombre: ' + d.data.product_name);
                if (d.data.specs && d.data.specs.length) resumen.push(d.data.specs.length + ' specs');
                if (d.data.faq && d.data.faq.length) resumen.push(d.data.faq.length + ' FAQ');
                if (d.data.images && d.data.images.length) resumen.push(d.data.images.length + ' imgs');
                $status.html('<span style="color:#27ae60"> Completado ? ' + resumen.join(' | ') + '</span>');
                toast(' Pipeline Express completado. Revisa cada pestana antes de guardar.');
            }
        }).fail(function(xhr) {
            clearInterval(timer);
            $btn.html(' Auto-rellenar todo').prop('disabled', false);
            $status.html('<span style="color:#c0392b"> Error ' + xhr.status + '</span>');
        });
    });

    /*  Datos pendientes del Modo Express (transient WP)  */
    if (ADMIN.pending_autofill && ADMIN.pending_autofill.product_name && $('#product_name').length) {
        var $banner=$('<div class="notice notice-warning" style="margin:8px 0 16px;padding:12px 16px;display:flex;align-items:center;gap:16px"><span> <strong>Modo Express:</strong> Datos generados para <em>'+ADMIN.pending_autofill.product_name+'</em> pendientes.</span><button type="button" class="button button-primary" id="btn-apply-pending"> Aplicar</button><button type="button" class="button" id="btn-discard-pending"></button></div>');
        $('.ckg-wrap h1').after($banner);
        $('#btn-apply-pending').on('click',function(){loadFormData(ADMIN.pending_autofill);$banner.remove();toast(' Datos del Modo Express aplicados.');$('.ckg-tab[data-target="tab-basic"]').trigger('click');});
        $('#btn-discard-pending').on('click',function(){$banner.remove();});
    }

    /* 
       MODO EXPRESS (pagina separada)
     */
    $('#btn-express-run').on('click',function(){
        var url=$('#express-url').val().trim(),name=$('#express-name').val().trim(),brand=$('#express-brand').val().trim(),tpl=$('#express-template').val();
        if(!url&&!name){alert('URL o nombre requerido.');return;}
        var $btn=$(this).prop('disabled',true).html(spin('Procesando pipeline completo...'));
        var $prog=$('#express-progress').show();
        var steps=['step-scrape','step-template','step-images','step-llm','step-seo'];
        var si=0,timer=setInterval(function(){if(si>0)$('#'+steps[si-1]).addClass('ckg-step--done').removeClass('ckg-step--active');if(si<steps.length){$('#'+steps[si]).addClass('ckg-step--active');si++;}},2000);
        $.post(ajax,{action:'ckg_express_autofill',nonce:nonce,url:url,name_hint:name,brand_hint:brand,prompt_id:1},function(r){
            clearInterval(timer);
            steps.forEach(function(s){$('#'+s).addClass('ckg-step--done').removeClass('ckg-step--active');});
            $btn.prop('disabled',false).html(' Rellenar formulario automaticamente');
            if(!r.success){$prog.hide();$('#express-status').html('<span class="ckg-err"> '+r.data.message+'</span>');if(r.data.log)$('#express-status').after('<div style="font-size:11px;margin-top:4px">'+r.data.log.join('<br>')+'</div>');return;}
            var d=r.data.data;
            $.post(ajax,{action:'ckg_store_autofill',nonce:nonce,data:JSON.stringify(d)},function(sr){
                if(sr.success){
                    $('#express-result-panel').show();
                    var s='<ul>';
                    if(d.product_name)               s+='<li> Nombre: <strong>'+d.product_name+'</strong></li>';
                    if(d.specs&&d.specs.length)       s+='<li> '+d.specs.length+' especificaciones</li>';
                    if(d.faq&&d.faq.length)           s+='<li> '+d.faq.length+' FAQ</li>';
                    if(d.images&&d.images.length)     s+='<li> '+d.images.length+' imagenes</li>';
                    if(d.homologacion&&d.homologacion.length) s+='<li> '+d.homologacion.length+' homologaciones</li>';
                    if(d.seo_title)                   s+='<li> SEO Title</li>';
                    $('#express-summary').html(s+'</ul>');
                }
            });
            $prog.hide();
        }).fail(function(){clearInterval(timer);$btn.prop('disabled',false).html(' Rellenar formulario automaticamente');$prog.hide();});
    });

    /* 
       CLONAR PRODUCTO
     */
    $('#btn-init-clone-search').on('click',function(){
        var $btn=$(this).html(spin('...')).prop('disabled',true);
        if($('#clone-category-filter option').length<=1) loadCategoriesInSelect('#clone-category-filter');
        $.post(ajax,{action:'ckg_search_products',nonce:nonce,query:$('#clone-product-search').val().trim(),category_id:$('#clone-category-filter').val(),per_page:12},function(r){
            $btn.html(' Buscar').prop('disabled',false);
            if(!r.success||!r.data.length){$('#clone-search-results').html('<p class="ckg-no-results">Sin resultados.</p>');return;}
            var html='<div class="ckg-ps-grid">';
            r.data.forEach(function(p){html+='<div class="ckg-clone-card">'+(p.image?'<img src="'+p.image+'" alt="">':'<div class="ckg-ps-no-img"></div>')+'<div class="ckg-clone-name">'+p.name+'</div><button type="button" class="button button-primary btn-do-clone" data-id="'+p.id+'"> Clonar</button></div>';});
            $('#clone-search-results').html(html+'</div>');
        }).fail(function(){$btn.html(' Buscar').prop('disabled',false);});
    });
    $('#clone-product-search').on('keypress',function(e){if(e.key==='Enter'){e.preventDefault();$('#btn-init-clone-search').trigger('click');}});
    $(document).on('click','.btn-do-clone,.btn-clone-from-history',function(){
        var pid=$(this).data('id')||$(this).data('product-id');
        if(!pid)return;
        if(!confirm('Cargar datos en el formulario?'))return;
        var $btn=$(this).html(spin('Cargando...')).prop('disabled',true);
        $.post(ajax,{action:'ckg_clone_product',nonce:nonce,product_id:pid},function(r){
            $btn.html(' Clonar').prop('disabled',false);
            if(!r.success){toast(' '+r.data.message,'err');return;}
            loadFormData(r.data);
            toast(' Datos cargados.');
            $('.ckg-tab[data-target="tab-basic"]').trigger('click');
        }).fail(function(){$btn.html(' Clonar').prop('disabled',false);});
    });

    /* 
       EXPORT / IMPORT JSON
     */
    $('#btn-export-json').on('click',function(){
        var data={};
        $('form [name]').each(function(){var n=$(this).attr('name');if(!n||n.startsWith('_')||n==='action')return;if($(this).is(':checkbox,:radio')){if($(this).is(':checked'))data[n]=$(this).val();}else data[n]=$(this).val();});
        $.post(ajax,{action:'ckg_export_json',nonce:nonce,form_data:JSON.stringify(data)},function(r){if(!r.success){toast(' Error.','err');return;}$('#export-json-output').val(r.data.json);$('#export-json-panel').show();});
    });
    $('#btn-copy-json').on('click',function(){navigator.clipboard.writeText($('#export-json-output').val()).then(function(){toast(' JSON copiado.');});});
    $('#btn-show-import-json').on('click',function(){$('#import-json-panel').toggle();});
    $('#btn-import-json').on('click',function(){
        var json=$('#import-json-input').val().trim();
        if(!json){alert('Pega el JSON primero.');return;}
        try{var p=JSON.parse(json),d=p.data||p;if(!d||typeof d!=='object'){alert('JSON no reconocido.');return;}loadFormData(d);$('#import-json-panel').hide();toast(' Formulario cargado desde JSON.');$('.ckg-tab[data-target="tab-basic"]').trigger('click');}
        catch(e){alert('JSON no valido: '+e.message);}
    });

    /* 
       IMPORTADOR CSV
     */
    function getImportFD(){
        var fd=new FormData(),fi=document.getElementById('csv-file');
        fd.append('nonce',nonce);
        if(fi&&fi.files.length) fd.append('csv_file',fi.files[0]);
        fd.append('autofill_llm',$('input[name="autofill_llm"]').is(':checked')?'1':'0');
        fd.append('import_images',$('input[name="import_images"]').is(':checked')?'1':'0');
        fd.append('product_status',$('input[name="product_status"]:checked').val()||'draft');
        return fd;
    }
    $('#btn-preview-import').on('click',function(){
        if(!(document.getElementById('csv-file') && document.getElementById('csv-file').files && document.getElementById('csv-file').files.length)){alert('Selecciona un archivo.');return;}
        var $btn=$(this).text('Cargando...').prop('disabled',true);
        var fd=getImportFD();fd.append('action','ckg_batch_preview');
        $.ajax({url:ajax,type:'POST',data:fd,processData:false,contentType:false,success:function(r){
            $btn.text(' Vista previa').prop('disabled',false);
            if(!r.success){$('#import-preview').html('<p class="ckg-err"> '+r.data.message+'</p>').show();return;}
            var rows=r.data.rows,html='<h3>'+rows.length+' productos:</h3><table class="ckg-history-table"><thead><tr><th>#</th><th>Nombre</th><th>Marca</th><th>Precio</th></tr></thead><tbody>';
            rows.forEach(function(row,i){html+='<tr><td>'+(i+1)+'</td><td>'+(row.name||'--')+'</td><td>'+(row.brand||'--')+'</td><td>'+(row.price||'--')+'</td></tr>';});
            $('#import-preview').html(html+'</tbody></table>').show();
        },error:function(){$btn.text(' Vista previa').prop('disabled',false);}});
    });
    $('#btn-execute-import').on('click',function(){
        if(!(document.getElementById('csv-file') && document.getElementById('csv-file').files && document.getElementById('csv-file').files.length)){alert('Selecciona un archivo.');return;}
        if(!confirm('Crear todos los productos? No se puede deshacer.'))return;
        var $btn=$(this).html(spin('Importando...')).prop('disabled',true);
        var fd=getImportFD();fd.append('action','ckg_batch_execute');
        $.ajax({url:ajax,type:'POST',data:fd,processData:false,contentType:false,timeout:600000,success:function(r){
            $btn.html(' Importar').prop('disabled',false);
            if(!r.success){$('#import-results').html('<p class="ckg-err"> '+r.data.message+'</p>').show();return;}
            var d=r.data,html='<h3> '+d.created+' creados, '+d.failed+' fallidos</h3><ul>';
            if(d.results) d.results.forEach(function(res){html+='<li> '+res.name+' -- <a href="'+res.edit_url+'">Editar</a></li>';});
            if(d.errors)  d.errors.forEach(function(err){html+='<li style="color:#c0392b"> '+err+'</li>';});
            $('#import-results').html(html+'</ul>').show();
        },error:function(){$btn.html(' Importar').prop('disabled',false);}});
    });

    /* 
       IMAGENES DEL FABRICANTE
     */
    $('#btn-import-manufacturer-images').on('click',function(){
        var url=$('#manufacturer_img_url').val().trim(),pname=$('#product_name').val().trim();
        if(!url){alert('URL requerida.');return;}if(!pname){alert('Nombre del producto requerido.');return;}
        var $btn=$(this).html(spin('Descargando...')).prop('disabled',true);
        $.post(ajax,{action:'ckg_import_manufacturer_images',nonce:nonce,url:url,product_name:pname,post_id:0},function(r){
            $btn.html(' Importar imagenes del fabricante').prop('disabled',false);
            if(!r.success){$('#mfr-img-status').html('<span class="ckg-err"> '+r.data.message+'</span>');return;}
            var d=r.data;
            $('#mfr-img-status').html(' '+d.message);
            var cur=$('#images_urls').val().trim();
            $('#images_urls').val(cur?cur+'\n'+d.urls.join('\n'):d.urls.join('\n'));
            renderImgPreview();
            var html='<div class="ckg-mfr-img-grid-wrap">';
            d.imported.forEach(function(img){html+='<div class="ckg-mfr-img-card"><img src="'+img.thumb+'"><small>WebP </small></div>';});
            $('#mfr-img-preview').html(html+'</div>');
            toast(' '+d.count+' imagenes a WebP 600x600.');
            $('.ckg-tab[data-target="tab-images"]').trigger('click');
        }).fail(function(){$btn.html(' Importar imagenes del fabricante').prop('disabled',false);});
    });

    /* 
       EXTRACTOR ATRIBUTOS COMPETIDORES
     */
    $('#btn-extract-attrs').on('click',function(){
        var urls=$('#attr-extract-urls').val().trim();if(!urls){alert('Introduce al menos una URL.');return;}
        var $btn=$(this).html(spin('Extrayendo...')).prop('disabled',true);
        var $res=$('#attr-extract-results').html('<div class="ckg-loading">Analizando...</div>');
        $.post(ajax,{action:'ckg_extract_attributes',nonce:nonce,urls:urls},function(r){
            $btn.html(' Extraer atributos').prop('disabled',false);
            if(!r.success){$res.html('<span class="ckg-err"> '+r.data.message+'</span>');return;}
            var attrs=r.data.attributes;
            if(!Object.keys(attrs).length){$res.html('<p>Sin atributos.</p>');return;}
            var html='<div class="ckg-attr-grid">';
            Object.keys(attrs).forEach(function(lbl){var vals=attrs[lbl];html+='<div class="ckg-attr-card"><label class="ckg-attr-card-header"><input type="checkbox" class="attr-create-chk" data-label="'+lbl+'" data-values="'+encodeURIComponent(JSON.stringify(vals))+'" checked> <strong>'+lbl+'</strong> <small>('+vals.length+')</small></label><div class="ckg-attr-values">'+vals.slice(0,6).map(function(v){return '<span class="ckg-term-chip">'+v+'</span>';}).join('')+'</div></div>';});
            $res.html(html+'</div><button type="button" class="button button-primary" id="btn-create-attrs" style="margin-top:10px"> Crear en WooCommerce</button>');
        }).fail(function(){$btn.html(' Extraer atributos').prop('disabled',false);});
    });
    $(document).on('click','#btn-create-attrs',function(){
        var $btn=$(this).html(spin('Creando...')).prop('disabled',true),toSend={};
        $('.attr-create-chk:checked').each(function(){toSend[$(this).data('label')]=JSON.parse(decodeURIComponent($(this).data('values')));});
        if(!Object.keys(toSend).length){alert('Selecciona al menos uno.');$btn.html(' Crear en WooCommerce').prop('disabled',false);return;}
        $.post(ajax,{action:'ckg_create_attributes',nonce:nonce,attributes:toSend},function(r){
            $btn.html(' Crear en WooCommerce').prop('disabled',false);
            if(!r.success){toast(' '+r.data.message,'err');return;}
            toast(' '+Object.keys(r.data.created||{}).length+' creados.');
        }).fail(function(){$btn.html(' Crear en WooCommerce').prop('disabled',false);});
    });

    /* 
       DETECTAR CONFIG OLLAMA + GPU + LLM PROVIDER
     */
    $('#btn-detect-llm').on('click',function(){
        var $btn=$(this).html(spin('Escaneando...')).prop('disabled',true);
        $.post(ajax,{action:'ckg_detect_llm_configs',nonce:nonce},function(r){
            $btn.html(' Detectar configuracion Ollama de otros plugins').prop('disabled',false);
            $('#llm-detect-results').show();
            var $list=$('#llm-configs-list').empty();
            if(!r.success||!r.data.configs.length){$list.html('<p class="ckg-no-results">Sin configuraciones.</p>');return;}
            r.data.configs.forEach(function(cfg){$list.append('<div class="ckg-llm-config-card"><div class="ckg-llm-cfg-info"><div class="ckg-llm-cfg-plugin"><strong>'+cfg.option_key+'</strong></div><div class="ckg-llm-cfg-url"> '+cfg.endpoint+'</div>'+(cfg.model?'<div class="ckg-llm-cfg-model"> '+cfg.model+'</div>':'')+'</div><button type="button" class="button button-primary btn-use-llm-config" data-option-key="'+cfg.option_key+'" data-subkey="'+(cfg.subkey||'')+'" data-model-subkey="'+(cfg.model_subkey||'')+'" data-model="'+(cfg.model||'')+'" data-endpoint="'+cfg.endpoint+'"> Usar esta</button></div>');});
        }).fail(function(){$btn.html(' Detectar configuracion Ollama de otros plugins').prop('disabled',false);});
    });
    $(document).on('click','.btn-use-llm-config',function(){
        var $btn=$(this).html(spin('Guardando...')).prop('disabled',true);
        $('#shared_llm_option_key').val($(this).data('option-key'));
        $.post(ajax,{action:'ckg_save_shared_llm',nonce:nonce,option_key:$(this).data('option-key'),subkey:$(this).data('subkey'),model_subkey:$(this).data('model-subkey'),model:$(this).data('model')},function(r){
            $btn.html(' En uso').prop('disabled',true);
            if(r.data&&r.data.message) $('#llm-save-status').html('<span style="color:'+(r.data.status==='online'?'#27ae60':'#e67e22')+'">'+r.data.message+'</span>');
        }).fail(function(){$btn.html(' Usar esta').prop('disabled',false);});
    });
    $(document).on('click','#btn-clear-shared-llm',function(e){e.preventDefault();if(!confirm('Volver a configuracion manual?'))return;$.post(ajax,{action:'ckg_save_shared_llm',nonce:nonce,option_key:'',subkey:'',model_subkey:'',model:''},function(){location.reload();});});
    $(document).on('change','input[name="gpu_profile"]',function(){$('#custom-gpu-row').toggle($(this).val()==='custom');$('.ckg-gpu-card').removeClass('ckg-gpu-card--active');$(this).closest('.ckg-gpu-card').addClass('ckg-gpu-card--active');});
    $(document).on('change','input[name="llm_provider"]',function(){var pid=$(this).val();$('.ckg-provider-card').removeClass('ckg-provider-card--active');$(this).closest('.ckg-provider-card').addClass('ckg-provider-card--active');$('.ckg-provider-panel').hide();$('#panel-'+pid).show();$('#btn-test-ollama').text(' Probar conexion con '+$(this).closest('.ckg-provider-card').find('.ckg-provider-name').text());});
    $(document).on('click','.ckg-toggle-key',function(){var $i=$(this).prev('.ckg-apikey-input');$i.attr('type',$i.attr('type')==='password'?'text':'password');$(this).text($i.attr('type')==='password'?'':'');});
    $(document).on('focus','.ckg-apikey-input',function(){if(/^[?]+$/.test($(this).val()))$(this).val('');});

    /* 
       WEB STORY
     */
    $(document).on('click','#btn-create-story',function(){
        var $btn=$(this).html(spin('Generando...')).prop('disabled',true);
        $.post(ajax,{action:'ckg_create_web_story',nonce:nonce,product_id:$(this).data('product-id')},function(r){
            $btn.prop('disabled',false);
            if(!r.success){$('#story-creation-status').html('<span class="ckg-err"> '+r.data.message+'</span>');$btn.html(' Reintentar');return;}
            $btn.hide();$('#story-creation-status').html(' <strong>Web Story creada</strong> -- <a href="'+r.data.story_url+'" target="_blank">Ver</a> | <a href="'+r.data.edit_url+'" target="_blank">Editar</a>');
            $('#web-story-panel').css('border-color','#27ae60');
        }).fail(function(){$btn.html(' Reintentar').prop('disabled',false);});
    });

    /* 
       GENERAR TODO DESDE CERO
     */
    function addGenerateAllBtn() {
        if($('#btn-generate-full').length) return;
        $('.ckg-ai-action-box').append('<hr style="border-color:rgba(255,255,255,.1);margin:14px 0"><p style="color:#ecf0f1;font-size:12px;margin:0 0 10px">O genera TODO desde cero:</p><button type="button" class="button" id="btn-generate-full" style="background:#f39c12;border-color:#d68910;color:#fff;font-weight:700"> Generar TODO desde cero</button>');
    }
    $(document).on('click','#btn-generate-full',function(){
        var $btn=$(this),pname=$('#product_name').val().trim(),brand=$('#brand').val().trim(),ctx=getOllamaContext();
        if(!pname){toast(' Nombre requerido.','warn');return;}
        if(!confirm('Generar todo el contenido para "'+pname+'"?'))return;
        $btn.html(spin('Generando...')).prop('disabled',true);
        var fields={short_desc:pname+(brand?' - '+brand:'')+'.',intro:pname+'.',conclusion:pname+'.',seo_title:'',seo_description:''};
        $.post(ajax,{action:'ckg_ollama_improve_all',nonce:nonce,fields:fields,product_name:pname,brand:brand,sku:$('#sku').val(),homologaciones:ctx.homologaciones},function(r){
            $btn.html(' Generar TODO desde cero').prop('disabled',false);
            if(!r.success){toast(' '+r.data.message,'err');return;}
            var res=r.data.results;
            if(res.short_desc)      $('[name="short_desc"]').val(res.short_desc);
            if(res.intro)           $('[name="intro"]').val(res.intro);
            if(res.conclusion)      $('[name="conclusion"]').val(res.conclusion);
            if(res.seo_title)       $('#seo_title').val(res.seo_title).trigger('input');
            if(res.seo_description) $('#seo_description').val(res.seo_description).trigger('input');
            setTimeout(function(){$('#btn-generate-faq-answers').trigger('click');},600);
            setTimeout(calcCompleteness,1200);
            toast(' Contenido generado.');
        }).fail(function(){$btn.html(' Generar TODO desde cero').prop('disabled',false);});
    });

    /* 
       LINKING INTERNO
     */
    $('#btn-get-related').on('click',function(){
        var $btn=$(this).html(spin('Buscando...')).prop('disabled',true);
        var cats=$('#categories').val().split(',').map(function(s){return s.trim();}).filter(Boolean);
        $.post(ajax,{action:'ckg_get_related_products',nonce:nonce,product_name:$('#product_name').val(),categories:cats},function(r){
            $btn.html(' Buscar productos relacionados').prop('disabled',false);
            if(!r.success||!r.data.length){$('#related-products-panel').html('<p>Sin resultados.</p>').show();return;}
            var html='<div class="ckg-related-grid">';
            r.data.forEach(function(p){html+='<div class="ckg-related-card">'+(p.image?'<img src="'+p.image+'">':'')+'<div class="ckg-related-info"><strong>'+p.name+'</strong></div><button type="button" class="button button-small ckg-copy-link" data-link="&lt;a href=&quot;'+p.permalink+'&quot;&gt;'+p.name+'&lt;/a&gt;"></button></div>';});
            $('#related-products-panel').html(html+'</div>').show();
        }).fail(function(){$btn.html(' Buscar productos relacionados').prop('disabled',false);});
    });
    $(document).on('click','.ckg-copy-link',function(){navigator.clipboard.writeText($(this).data('link')).then(function(){toast(' Enlace copiado.');});});

    /* 
       INIT
     */
    $(document).ready(function () {
        setTimeout(calcCompleteness, 500);

        // ?? Panel de precios en tab Variaciones ?????????????????????????
        // Calcular descuento y margen en tiempo real
        $(document).on('input', '#base_price, #sale_price, #cost_price', function () {
            var base = parseFloat($('#base_price').val()) || 0;
            var sale = parseFloat($('#sale_price').val()) || 0;
            var cost = parseFloat($('#cost_price').val()) || 0;
            if (base > 0 && sale > 0 && sale < base) {
                $('#discount-badge').text('-' + Math.round((1 - sale/base)*100) + '% dto');
            } else { $('#discount-badge').text(''); }
            if (base > 0 && cost > 0 && cost < base) {
                var m = Math.round((base-cost)/base*100);
                $('#cost-margin-badge').text('Margen: ' + m + '%')
                    .css('color', m>=30?'#27ae60':m>=15?'#f39c12':'#c0392b');
            } else { $('#cost-margin-badge').text(''); }
        });

        // Propagar precio base a todas las variaciones
        $(document).on('click', '#btn-propagate-price-simple', function () {
            var base = $('#base_price').val().trim();
            var sale = $('#sale_price').val().trim();
            if (!base) { toast('Introduce el precio base primero.', 'warn'); return; }
            $('#var-body input[name="var_price[]"]').val(base);
            if (sale) $('#var-body input[name="var_sale[]"]').val(sale);
            toast('Precio ' + base + ' EUR aplicado a todas las variaciones.');
        });

        // Al enviar: aplicar base_price a variaciones sin precio
        $('form').on('submit', function () {
            var base = parseFloat($('#base_price').val()) || 0;
            var sale = parseFloat($('#sale_price').val()) || 0;
            if (!base) return;
            var hasVars = $('#var-body .ckg-row').length > 0;
            if (!hasVars) {
                var $r = $($('#tmpl-var-row').html());
                $r.find('input[name="var_label[]"]').val('Estandar');
                $r.find('input[name="var_price[]"]').val(base.toFixed(2));
                if (sale > 0) $r.find('input[name="var_sale[]"]').val(sale.toFixed(2));
                $('#var-body').append($r);
            } else {
                $('#var-body .ckg-row').each(function () {
                    var $p = $(this).find('input[name="var_price[]"]');
                    if (!parseFloat($p.val())) $p.val(base.toFixed(2));
                    var $s = $(this).find('input[name="var_sale[]"]');
                    if (sale > 0 && !parseFloat($s.val())) $s.val(sale.toFixed(2));
                });
            }
        });

        // ?? Pagina de Enriquecimiento ????????????????????????????????????

        // Cargar estadisticas al entrar en la pagina
        if ($('#enrich-stats-panel').length) {
            loadEnrichStats();
        }

        function loadEnrichStats() {
            $.post(ajax, { action: 'ckg_enricher_status', nonce: nonce }, function (r) {
                if (!r.success) return;
                var s = r.data.stats;
                $('#stat-total').text(s.total_products || 0);
                $('#stat-enriched').text(s.enriched || 0);
                $('#stat-pending').text((s.total_products - s.enriched) || 0);
                $('#stat-queue').text(s.pending_queue || 0);
            });
        }

        $('#btn-refresh-enrich-stats').on('click', loadEnrichStats);

        // Analizar productos pendientes
        $('#btn-enrich-analyze').on('click', function () {
            var $btn = $(this).text('Analizando...').prop('disabled', true);
            var $res = $('#enrich-analyze-result');
            $.post(ajax, {
                action:      'ckg_enricher_analyze',
                nonce:       nonce,
                category_id: $('#enrich-category').val(),
                only_empty:  $('#enrich-only-empty').is(':checked') ? 1 : 0,
                only_basic:  $('#enrich-only-basic').is(':checked') ? 1 : 0,
                limit:       $('#enrich-limit').val(),
                max_score:   $('#enrich-max-score').val() || 100,
                mode:        $('#enrich-mode').val() || 'normal',
                min_days:    $('#enrich-min-days').val() || 30,
                prompt_id:   $('#enrich-prompt-id').val() || 1,
            }, function (r) {
                $btn.text('Analizar productos').prop('disabled', false);
                if (!r.success) { $res.text('Error: ' + r.data.message); return; }
                var count = r.data.count;
                if (count === 0) {
                    $res.text('No hay productos pendientes con los filtros seleccionados.');
                    $('#enrich-start-panel').hide();
                } else {
                    $res.text(count + ' productos encontrados listos para enriquecer.');
                    $('#enrich-start-panel').show().data('ids', r.data.ids);
                }
            }).fail(function () { $btn.text('Analizar productos').prop('disabled', false); });
        });

        // Iniciar cola
        $('#btn-enrich-start').on('click', function () {
            var ids = $('#enrich-start-panel').data('ids') || [];
            if (!ids.length) { toast('Analiza primero los productos.', 'warn'); return; }
            if (!confirm('Se enriqueceran ' + ids.length + ' productos con Ollama. El proceso puede tardar varios minutos. Puedes cerrar esta pagina y volver.')) return;

            var $btn = $(this).text('Iniciando...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_enricher_start', nonce: nonce, ids: JSON.stringify(ids) }, function (r) {
                $btn.text('Iniciar enriquecimiento').prop('disabled', false);
                if (!r.success) { toast('Error: ' + r.data.message, 'err'); return; }
                $('#enrich-start-panel').hide();
                $('#enrich-progress-panel').show();
                $('#enrich-log').empty();
                runEnrichmentLoop(r.data.status.total || ids.length);
            }).fail(function () { $btn.text('Iniciar enriquecimiento').prop('disabled', false); });
        });

        var _enrichRunning = false;

        function runEnrichmentLoop(total) {
            _enrichRunning = true;
            function step() {
                if (!_enrichRunning) return;
                $.post(ajax, { action: 'ckg_enricher_process', nonce: nonce }, function (r) {
                    if (!r.success) { appendEnrichLog('Error AJAX'); return; }
                    var d = r.data;
                    var status = d.status || {};
                    var processed = (status.processed || 0) + (status.failed || 0);
                    var pct = total > 0 ? Math.round(processed / total * 100) : 0;
                    $('#enrich-prog-fill').css('width', pct + '%');
                    $('#enrich-prog-label').text(processed + ' / ' + total + ' procesados (' + pct + '%)');
                    $('#enrich-current').text('Ultimo: ' + (status.last_name || ''));
                    if (d.last_result) {
                        var lr = d.last_result;
                        var logLine = (lr.success ? 'OK' : 'ERROR') + ' - ' + (lr.name || 'ID ' + (lr.product_id || ''));
                    if (lr.success && lr.product_id) {
                        logLine += ' <a href="#" class="ckg-restore-backup" data-id="' + lr.product_id + '" style="font-size:11px;margin-left:8px;color:#c0392b">Restaurar</a>';
                    }
                    appendEnrichLog(logLine, true);
                    }
                    if (d.done || !_enrichRunning) {
                        _enrichRunning = false;
                        $('#enrich-prog-label').text('Completado: ' + processed + ' productos procesados.');
                        if (status.failed > 0) {
                            appendEnrichLog(status.failed + ' errores. Ver log para detalles.');
                        }
                        loadEnrichStats();
                    } else {
                        setTimeout(step, 800);
                    }
                }).fail(function (xhr) {
                    var errMsg = 'Error de conexion.';
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.data && parsed.data.message) {
                            errMsg = 'Error: ' + parsed.data.message;
                        }
                    } catch(e) {}
                    appendEnrichLog(errMsg);
                    // Reintentar solo si es error de red, no si es error PHP
                    if (_enrichRunning && xhr.status === 0) {
                        setTimeout(step, 3000);
                    } else if (_enrichRunning && xhr.status >= 500) {
                        appendEnrichLog('Error del servidor. Deteniendo cola.');
                        _enrichRunning = false;
                        $('#btn-enrich-stop').text('Detenido').prop('disabled', true);
                    }
                });
            }
            step();
        }

        function appendEnrichLog(msg) {
            var $log = $('#enrich-log');
            $log.append('<div>' + new Date().toLocaleTimeString('es-ES') + ' -- ' + msg + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }

        $('#btn-enrich-stop').on('click', function () {
            _enrichRunning = false;
            $(this).text('Detenido').prop('disabled', true);
            appendEnrichLog('Proceso detenido por el usuario.');
        });

        $('#btn-enrich-clear').on('click', function () {
            if (!confirm('Limpiar la cola?')) return;
            $.post(ajax, { action: 'ckg_enricher_clear', nonce: nonce }, function () {
                toast('Cola limpiada.'); loadEnrichStats();
                $('#enrich-progress-panel').hide();
                $('#enrich-start-panel').hide();
            });
        });

        // ?? Migracion de atributos ????????????????????????????????????

        $('#btn-attr-analyze').on('click', function () {
            var $btn = $(this).text('Analizando...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_attr_analyze', nonce: nonce }, function (r) {
                $btn.text('Analizar atributos').prop('disabled', false);
                if (!r.success) { toast('Error.', 'err'); return; }
                var d = r.data;
                var html = '<p><strong>' + d.total + ' atributos en WooCommerce:</strong> ' +
                    d.keep.length + ' a conservar, ' + d.migrate.length + ' candidatos a migrar.</p>';

                if (d.keep.length) {
                    html += '<h4 style="color:#27ae60">Conservar (' + d.keep.length + ') -- son variaciones reales:</h4><ul>';
                    d.keep.forEach(function(a){ html += '<li><code>pa_' + a.slug + '</code> -- ' + a.label + ' (' + a.term_count + ' terminos)</li>'; });
                    html += '</ul>';
                }

                if (d.migrate.length) {
                    html += '<h4 style="color:#e67e22">Candidatos a migrar como specs (' + d.migrate.length + '):</h4>';
                    html += '<p style="font-size:12px;color:#888">Sus valores se moveran a los metadatos del producto antes de eliminar el atributo.</p>';
                    html += '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead>';
                    html += '<tr><th style="background:#2c3e50;color:#fff;padding:6px 10px;text-align:left">Atributo</th><th style="background:#2c3e50;color:#fff;padding:6px">Terminos</th><th style="background:#2c3e50;color:#fff;padding:6px">Migrar</th></tr></thead><tbody>';
                    d.migrate.forEach(function(a) {
                        html += '<tr><td style="padding:5px 10px;border-bottom:1px solid #eee"><code>pa_' + a.slug + '</code> -- ' + a.label + '</td>';
                        html += '<td style="padding:5px;text-align:center;border-bottom:1px solid #eee">' + a.term_count + '</td>';
                        html += '<td style="padding:5px;text-align:center;border-bottom:1px solid #eee"><input type="checkbox" class="attr-migrate-chk" data-id="' + a.id + '" checked></td></tr>';
                    });
                    html += '</tbody></table>';
                    html += '<div style="margin-top:12px;display:flex;gap:8px">';
                    html += '<button type="button" class="button button-primary" id="btn-attr-do-backup">1. Hacer backup primero</button>';
                    html += '<button type="button" class="button" id="btn-attr-do-migrate" disabled style="border-color:#c0392b;color:#c0392b">2. Migrar seleccionados</button>';
                    html += '</div>';
                    html += '<div id="attr-migrate-status" style="margin-top:8px;font-size:13px"></div>';
                }

                $('#attr-analysis-panel').html(html).show();
            }).fail(function () { $btn.text('Analizar atributos').prop('disabled', false); });
        });

        $(document).on('click', '#btn-attr-do-backup', function () {
            var $btn = $(this).text('Haciendo backup...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_attr_backup', nonce: nonce }, function (r) {
                $btn.prop('disabled', false);
                if (r.success) {
                    $btn.text('Backup OK (' + r.data.date + ')');
                    $('#btn-attr-do-migrate').prop('disabled', false);
                    toast('Backup completado. Ya puedes migrar.');
                } else {
                    $btn.text('1. Hacer backup primero');
                    toast('Error en backup.', 'err');
                }
            });
        });

        $(document).on('click', '#btn-attr-do-migrate', function () {
            var ids = $('.attr-migrate-chk:checked').map(function(){ return $(this).data('id'); }).get();
            if (!ids.length) { toast('Selecciona al menos un atributo.', 'warn'); return; }
            if (!confirm('Se migraran ' + ids.length + ' atributos. Sus valores se guardaran en los productos antes de eliminarlos. Tienes backup?')) return;

            var $btn = $(this).prop('disabled', true);
            var $st  = $('#attr-migrate-status').text('Migrando 0 / ' + ids.length + '...');
            var done = 0;

            function migrateNext(remaining) {
                if (!remaining.length) {
                    $st.html('<span style="color:#27ae60">Migracion completada. ' + done + ' atributos procesados.</span>');
                    $btn.text('Completado');
                    toast('Atributos migrados correctamente. Limpia la cache de WooCommerce.');
                    return;
                }
                var id = remaining.shift();
                $.post(ajax, { action: 'ckg_attr_migrate_one', nonce: nonce, attribute_id: id }, function (r) {
                    done++;
                    $st.text('Migrando ' + done + ' / ' + ids.length + '...' + (r.success ? ' OK: ' + (r.data.attribute||'') : ' ERROR'));
                    setTimeout(function(){ migrateNext(remaining); }, 300);
                }).fail(function(){ done++; setTimeout(function(){ migrateNext(remaining); }, 500); });
            }
            migrateNext(ids.slice());
        });

        // Geocodificador de tienda en Ajustes
        $('#btn-geocode-store').on('click', function () {
            var city     = $('input[name="store_city"]').val().trim();
            var province = $('input[name="store_province"]').val().trim();
            var country  = $('input[name="store_country"]').val().trim() || 'Espana';
            var place    = [city, province, country].filter(Boolean).join(', ');

            if (!place || place === country) { alert('Introduce la ciudad o provincia primero.'); return; }

            var $btn = $(this).html('<span class="ckg-spin"></span> Buscando...').prop('disabled', true);
            var $st  = $('#geocode-status').text('Consultando OpenStreetMap...');

            $.post(ajax, { action: 'ckg_geocode', nonce: nonce, place: place }, function (r) {
                $btn.html(' Geocodificar direccion').prop('disabled', false);
                if (!r.success) {
                    $st.html('<span style="color:#c0392b"> ' + r.data.message + '</span>');
                    return;
                }
                var d = r.data;
                $('#store_lat').val(d.lat.toFixed(6));
                $('#store_lng').val(d.lng.toFixed(6));
                $st.html('<span style="color:#27ae60"> ' + d.display_name.split(',').slice(0,3).join(',') + '</span>');
            }).fail(function () {
                $btn.html(' Geocodificar direccion').prop('disabled', false);
                $st.text(' Error de conexion.');
            });
        });

        // Diagnostico de campos sociales en un producto
        $(document).on('click', '#btn-scan-social-meta', function () {
            var $btn = $(this).text('Escaneando...').prop('disabled', true);
            var pid  = $('#scan-product-id').val().trim() || 0;
            var $res = $('#social-meta-scan-results');

            $.post(ajax, { action: 'ckg_scan_social_meta', nonce: nonce, product_id: pid }, function (r) {
                $btn.text('Escanear campos sociales').prop('disabled', false);
                if (!r.success) { $res.html('<p class="ckg-err">' + r.data.message + '</p>'); return; }

                var d = r.data;
                var html = '<div style="font-size:13px">';
                html += '<p><strong>Producto:</strong> ' + d.product_name + ' (ID ' + d.product_id + ') &mdash; ' + d.total_meta + ' campos en total</p>';

                // Campos rellenos
                if (d.filled_fields.length) {
                    html += '<details open><summary style="cursor:pointer;font-weight:700;color:#27ae60">Campos rellenos por plugins sociales (' + d.filled_fields.length + ')</summary>';
                    html += '<table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12px">';
                    html += '<thead><tr><th style="background:#2c3e50;color:#fff;padding:6px 10px;text-align:left">Campo</th><th style="background:#2c3e50;color:#fff;padding:6px 10px">Plugin</th><th style="background:#2c3e50;color:#fff;padding:6px 10px">Valor</th></tr></thead><tbody>';
                    d.filled_fields.forEach(function (f) {
                        html += '<tr><td style="padding:5px 10px;border-bottom:1px solid #eee;font-family:monospace">' + f.key + '</td>';
                        html += '<td style="padding:5px 10px;border-bottom:1px solid #eee;color:#888">' + f.plugin + '</td>';
                        html += '<td style="padding:5px 10px;border-bottom:1px solid #eee;word-break:break-all">' + (f.value || '--') + '</td></tr>';
                    });
                    html += '</tbody></table></details>';
                } else {
                    html += '<p style="color:#c0392b">Sin campos de plugins sociales rellenos. El relleno automatico no ha funcionado o no se ha creado ningun producto con el plugin todavia.</p>';
                }

                // Campos vacios
                if (d.empty_fields.length) {
                    html += '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700;color:#e67e22">Campos vacios de plugins sociales (' + d.empty_fields.length + ') - estos son los que hay que rellenar</summary>';
                    html += '<table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12px">';
                    html += '<thead><tr><th style="background:#2c3e50;color:#fff;padding:6px 10px;text-align:left">Campo</th><th style="background:#2c3e50;color:#fff;padding:6px 10px">Plugin</th></tr></thead><tbody>';
                    d.empty_fields.forEach(function (f) {
                        html += '<tr><td style="padding:5px 10px;border-bottom:1px solid #eee;font-family:monospace">' + f.key + '</td>';
                        html += '<td style="padding:5px 10px;border-bottom:1px solid #eee;color:#888">' + f.plugin + '</td></tr>';
                    });
                    html += '</tbody></table></details>';
                }

                html += '</div>';
                $res.html(html);
            }).fail(function () { $btn.text('Escanear campos sociales').prop('disabled', false); });
        });

        // Buscar campos exactos en base de datos
        $(document).on('click', '#btn-find-social-fields', function () {
            var $btn = $(this).text('Buscando...').prop('disabled', true);
            var pid  = $('#scan-product-id').val().trim() || 0;
            $.post(ajax, { action: 'ckg_find_social_fields', nonce: nonce, product_id: pid }, function (r) {
                $btn.text('Buscar campos exactos en BD').prop('disabled', false);
                if (!r.success) { $('#social-meta-scan-results').html('<p class="ckg-err">' + r.data.message + '</p>'); return; }
                var d = r.data;
                if (!d.fields.length) {
                    $('#social-meta-scan-results').html('<p style="color:#c0392b">No se encontraron campos de Facebook/Google/Pinterest en el producto ' + d.product_id + '. Crea un producto con el plugin primero.</p>');
                    return;
                }
                var html = '<p><strong>Campos encontrados en "' + d.product_name + '" (ID ' + d.product_id + '):</strong></p>';
                html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
                html += '<thead><tr><th style="background:#2c3e50;color:#fff;padding:6px 10px;text-align:left">Clave en BD (meta_key)</th><th style="background:#2c3e50;color:#fff;padding:6px 10px">Valor actual</th></tr></thead><tbody>';
                d.fields.forEach(function (f) {
                    var val = f.meta_value || '<em style="color:#aaa">vacio</em>';
                    if (val.length > 60) val = val.substr(0,60) + '...';
                    var rowColor = f.meta_value ? '' : 'background:#fff8e7';
                    html += '<tr style="' + rowColor + '"><td style="padding:5px 10px;border-bottom:1px solid #eee;font-family:monospace">' + f.meta_key + '</td><td style="padding:5px 10px;border-bottom:1px solid #eee">' + val + '</td></tr>';
                });
                html += '</tbody></table>';
                html += '<p style="font-size:11px;color:#888;margin-top:8px">Las filas en amarillo estan vacias. Comparte esta lista para corregir los nombres de campos.</p>';
                $('#social-meta-scan-results').html(html);
            }).fail(function () { $btn.text('Buscar campos exactos en BD').prop('disabled', false); });
        });

        // Detector de plugins sociales en la pagina de ajustes
        $('#btn-check-social-plugins').on('click', function () {
            var $btn = $(this).html('<span class="ckg-spin"></span> Detectando...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_detect_social_plugins', nonce: nonce }, function (r) {
                $btn.html(' Detectar plugins').prop('disabled', false);
                if (!r.success) return;
                var plugins = r.data.plugins;
                var active  = plugins.filter(function(p){ return p.active; });
                var html = '<div class="ckg-social-plugins-grid">';
                plugins.forEach(function (p) {
                    html += '<div class="ckg-social-plugin-item ' + (p.active ? 'ckg-spi--active' : 'ckg-spi--inactive') + '">';
                    html += (p.active ? '' : '') + ' ' + p.label;
                    html += '</div>';
                });
                html += '</div>';
                html += '<p style="margin-top:10px;font-size:13px;color:#50575e">';
                html += '<strong>' + active.length + ' de ' + plugins.length + ' integraciones activas.</strong> ';
                html += 'Los campos de las integraciones activas se rellenan automaticamente al crear cada producto.</p>';
                $('#social-plugins-status').html(html);
            }).fail(function () { $btn.html(' Detectar plugins').prop('disabled', false); });
        });
        if ($('#story-plugin-status').length) {
            $.post(ajax,{action:'ckg_check_web_stories_plugin',nonce:nonce},function(r){
                if(r.success) $('#story-plugin-status').html(r.data.active?'<span style="color:#27ae60;font-size:12px;"> Web Stories activo</span>':'<span style="color:#e67e22;font-size:12px;"> <a href="plugin-install.php?s=web+stories&tab=search&type=term">Instalar Web Stories</a></span>');
            });
        }
    });

    /* 
       VIDEO PREVIEW EN EL ADMIN
     */
    $(document).on('input change', '#video_url, [name="video_url"]', function () {
        var url = $(this).val().trim();
        var $wrap = $('#video-preview-wrap');
        var $inner = $('#video-preview-inner');

        if (!url) { $wrap.hide(); $inner.html(''); return; }

        var embedHtml = '';

        // YouTube
        var ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_\-]{11})/);
        if (ytMatch) {
            embedHtml = '<div class="ckg-video-preview-responsive"><iframe src="https://www.youtube-nocookie.com/embed/' + ytMatch[1] + '?rel=0" frameborder="0" allowfullscreen></iframe></div>';
        }

        // Vimeo
        var vmMatch = url.match(/vimeo\.com\/(\d+)/);
        if (!embedHtml && vmMatch) {
            embedHtml = '<div class="ckg-video-preview-responsive"><iframe src="https://player.vimeo.com/video/' + vmMatch[1] + '" frameborder="0" allowfullscreen></iframe></div>';
        }

        // Video directo
        if (!embedHtml && /\.(mp4|webm|ogg)(\?.*)?$/i.test(url)) {
            embedHtml = '<video controls style="width:100%;max-width:480px;border-radius:6px"><source src="' + url + '"></video>';
        }

        if (embedHtml) {
            $inner.html(embedHtml);
            $wrap.show();
        } else {
            $inner.html('<p style="font-size:12px;color:#888"> No se puede previsualizar esta URL. Se usara oEmbed de WordPress.</p>');
            $wrap.show();
        }
    });

    /* 
       PERPLEXITY -- BUSQUEDA EN TIEMPO REAL
     */

    // Boton "Buscar con Perplexity" en el banner AutoFill
    $(document).on('click', '#btn-perplexity-search', function () {
        var name  = $('#product_name').val().trim();
        var brand = $('#brand').val().trim();
        if (!name) { toast(' Rellena el nombre del producto primero.', 'warn'); $('#product_name').focus(); return; }

        var $btn = $(this).html(spin('Buscando en internet...')).prop('disabled', true);
        var $st  = $('#autofill-inline-status').html('<em>Perplexity esta buscando "' + name + (brand?' '+brand:'') + '" en tiempo real...</em>');

        $.post(ajax, { action: 'ckg_perplexity_search', nonce: nonce, product_name: name, brand: brand, focus: 'full' }, function (r) {
            $btn.html(' Buscar con Perplexity').prop('disabled', false);
            if (!r.success) { $st.html('<span style="color:#c0392b"> ' + r.data.message + '</span>'); return; }

            var d = r.data.data;
            if (!d._parsed) {
                $st.html('<span style="color:#e67e22"> Datos obtenidos pero no estructurados. Usa el AutoFill normal con la URL.</span>');
                return;
            }

            // Aplicar datos al formulario
            var toLoad = {};
            if (d.product_name && !$('#product_name').val().trim()) toLoad.product_name = d.product_name;
            if (d.brand && !$('#brand').val().trim())               toLoad.brand        = d.brand;
            if (d.short_desc)    toLoad.short_desc    = d.short_desc;
            if (d.intro)         toLoad.intro         = d.intro;
            if (d.specs && d.specs.length)           toLoad.specs         = d.specs;
            if (d.homologaciones && d.homologaciones.length) toLoad.homologacion = d.homologaciones.map(function(h) { return { tipo: h.tipo||'', codigo: h.codigo||'', descripcion: '' }; });
            if (d.faq && d.faq.length)               toLoad.faq           = d.faq;
            loadFormData(toLoad);

            // Panel de resultados adicionales
            var extraHtml = '';

            // Precios encontrados
            if (d.competitors_prices && d.competitors_prices.length) {
                extraHtml += '<div class="ckg-pplx-panel"><h4> Precios de mercado encontrados:</h4><ul>';
                d.competitors_prices.forEach(function(p) {
                    extraHtml += '<li><strong>' + (p.tienda||'Tienda') + '</strong>: ' + (p.precio||'--') + (p.url?' &nbsp;<a href="'+p.url+'" target="_blank"></a>':'') + '</li>';
                });
                extraHtml += '</ul></div>';
            }

            // Fuentes usadas por Perplexity
            if (r.data.sources && r.data.sources.length) {
                extraHtml += '<div class="ckg-pplx-panel"><h4> Fuentes consultadas:</h4><ul>';
                r.data.sources.slice(0,5).forEach(function(src) { extraHtml += '<li><a href="'+src+'" target="_blank" style="font-size:11px">'+src.replace(/^https?:\/\//,'').split('/')[0]+'</a></li>'; });
                extraHtml += '</ul></div>';
            }

            var filledCount = Object.keys(toLoad).length;
            $st.html('<span style="color:#27ae60"> ' + filledCount + ' campos rellenados desde internet</span>');
            if (extraHtml) {
                if ($('#pplx-extra-results').length) $('#pplx-extra-results').html(extraHtml);
                else $('#tab-basic .ckg-autofill-banner').after('<div id="pplx-extra-results">' + extraHtml + '</div>');
            }
            toast(' Perplexity encontro datos reales para "' + name + '".');
        }).fail(function () {
            $btn.html(' Buscar con Perplexity').prop('disabled', false);
            $st.html('<span style="color:#c0392b"> Error de conexion.</span>');
        });
    });

    /*  Verificar homologaciones en tiempo real  */
    // Anadir boton de verificacion junto a cada fila de homologacion
    function addHomoVerifyBtn($row, index) {
        if (!ADMIN.perplexity_available) return;
        if ($row.find('.btn-verify-homo').length) return;
        $row.find('td:last').append(
            '<button type="button" class="button button-small btn-verify-homo" data-index="' + index + '" title="Verificar con Perplexity si esta homologacion sigue vigente en 2025" style="margin-left:4px;border-color:#2272d3;color:#2272d3"> Verificar</button>' +
            '<span class="homo-verify-status" style="font-size:11px;margin-left:4px"></span>'
        );
    }

    $(document).on('click', '.btn-verify-homo', function () {
        var $row = $(this).closest('.ckg-row');
        var tipo   = $row.find('input[name="homo_tipo[]"]').val().trim();
        var codigo = $row.find('input[name="homo_codigo[]"]').val().trim();
        var pname  = $('#product_name').val().trim();

        if (!tipo) { toast(' Rellena el tipo de homologacion primero.', 'warn'); return; }

        var $btn = $(this).html(spin('...')).prop('disabled', true);
        var $st  = $row.find('.homo-verify-status').text('Verificando...');

        $.post(ajax, { action: 'ckg_verify_homologacion', nonce: nonce, product_name: pname, tipo: tipo, codigo: codigo }, function (r) {
            $btn.html(' Verificar').prop('disabled', false);
            if (!r.success) { $st.html('<span style="color:#c0392b"> ' + r.data.message + '</span>'); return; }
            var d = r.data;
            if (d.vigente === true) {
                $st.html('<span style="color:#27ae60"> Vigente' + (d.expira && d.expira !== 'N/A' ? ' hasta ' + d.expira : '') + '</span>');
            } else if (d.vigente === false) {
                $st.html('<span style="color:#c0392b"> Expirada' + (d.version_actual ? ' -- Usar: ' + d.version_actual : '') + '</span>');
                if (d.version_actual) {
                    toast(' Homologacion ' + tipo + ' ' + codigo + ' expirada. Version actual: ' + d.version_actual, 'warn');
                }
            } else {
                $st.html('<span style="color:#e67e22">i ' + (d.nota || 'Sin datos claros') + '</span>');
            }
        }).fail(function () {
            $btn.html(' Verificar').prop('disabled', false);
            $st.text(' Error');
        });
    });

    // Anadir botones de verificacion cuando se anade una fila de homo
    $(document).on('ckg:homo:added', function () {
        if (!ADMIN.perplexity_available) return;
        $('#homo-body .ckg-row').each(function (i) { addHomoVerifyBtn($(this), i); });
    });

    /*  Precios de mercado (tab SEO o tab Basico)  */
    // Boton en la barra AutoFill para buscar precios rapidamente
    $(document).on('click', '#btn-perplexity-prices', function () {
        var name  = $('#product_name').val().trim();
        var brand = $('#brand').val().trim();
        if (!name) { toast(' Nombre del producto requerido.', 'warn'); return; }

        var $btn = $(this).html(spin('Buscando precios...')).prop('disabled', true);
        $.post(ajax, { action: 'ckg_perplexity_prices', nonce: nonce, product_name: name, brand: brand }, function (r) {
            $btn.html(' Precios de mercado').prop('disabled', false);
            if (!r.success) { toast(' ' + r.data.message, 'err'); return; }
            var prices = r.data.prices || [];
            if (!prices.length) { toast('i No se encontraron precios disponibles.'); return; }
            var html = '<div class="ckg-pplx-panel"><h4> Precios de mercado actuales para "' + name + '":</h4><table style="width:100%;border-collapse:collapse">';
            html += '<thead><tr><th style="text-align:left;padding:6px;background:#2c3e50;color:#fff">Tienda</th><th style="text-align:left;padding:6px;background:#2c3e50;color:#fff">Precio</th><th style="padding:6px;background:#2c3e50;color:#fff">Link</th></tr></thead><tbody>';
            prices.forEach(function (p) {
                html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #eee">' + (p.tienda||'--') + '</td><td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:700;color:#c0392b">' + (p.precio||'--') + '</td><td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:center">' + (p.url?'<a href="'+p.url+'" target="_blank"></a>':'--') + '</td></tr>';
            });
            if (r.data.precio_medio) html += '<tr><td colspan="3" style="padding:6px 8px;background:#f8f9fa;font-size:12px">Precio medio: <strong>' + r.data.precio_medio + '</strong></td></tr>';
            html += '</tbody></table></div>';
            if ($('#pplx-extra-results').length) $('#pplx-extra-results').append(html);
            else $('#tab-basic .ckg-autofill-banner').after('<div id="pplx-extra-results">' + html + '</div>');
            toast(' Precios de mercado obtenidos en tiempo real.');
        }).fail(function () { $btn.html(' Precios de mercado').prop('disabled', false); });
    });

    /*  FAQ real de usuarios  */
    $(document).on('click', '#btn-perplexity-faq', function () {
        var name  = $('#product_name').val().trim();
        var brand = $('#brand').val().trim();
        if (!name) { toast(' Nombre del producto requerido.', 'warn'); return; }

        var $btn = $(this).html(spin('Buscando preguntas reales...')).prop('disabled', true);
        $.post(ajax, { action: 'ckg_perplexity_faq', nonce: nonce, product_name: name, brand: brand }, function (r) {
            $btn.html(' FAQ reales de usuarios').prop('disabled', false);
            if (!r.success) { toast(' ' + r.data.message, 'err'); return; }
            var faq = r.data.faq || [];
            if (!faq.length) { toast('i No se encontraron preguntas.'); return; }
            if (!confirm('Perplexity encontro ' + faq.length + ' preguntas reales de usuarios. Anadirlas a la pestana FAQ?')) return;
            var $tb = $('#faq-body').empty();
            faq.forEach(function (f) {
                var $r = $($('#tmpl-faq-row').html());
                $r.find('input[name="faq_pregunta[]"]').val(f.pregunta || '');
                $r.find('textarea[name="faq_resp[]"]').val(f.respuesta || '');
                $tb.append($r);
            });
            toast(' ' + faq.length + ' FAQ reales anadidas.');
            $('.ckg-tab[data-target="tab-content"]').trigger('click');
        }).fail(function () { $btn.html(' FAQ reales de usuarios').prop('disabled', false); });
    });

    /*  Botones Perplexity en el tab IA (si esta configurado)  */
    if (ADMIN.perplexity_available) {
        $(document).on('click', '.ckg-tab[data-target="tab-ai"]', function () {
            setTimeout(function () {
                if ($('#btn-pplx-group').length) return;
                var $aiBox = $('.ckg-ai-action-box');
                if (!$aiBox.length) return;
                $aiBox.after(
                    '<div id="btn-pplx-group" class="ckg-pplx-actions-box">' +
                    '<h4> Perplexity -- Busqueda en tiempo real</h4>' +
                    '<p>Perplexity busca en internet ahora mismo. Los datos son actuales, no de entrenamiento.</p>' +
                    '<div class="ckg-pplx-btns">' +
                    '<button type="button" class="button" id="btn-perplexity-prices"> Precios de mercado</button>' +
                    '<button type="button" class="button" id="btn-perplexity-faq"> FAQ reales de usuarios</button>' +
                    '</div></div>'
                );
            }, 100);
        });
    }

    /* 
       AUTO-GUARDADO DE BORRADOR (localStorage)
       Guarda el estado del formulario cada 2 minutos
     */
    var _autosaveTimer, _autosaveKey = 'ckg_draft_' + (ADMIN.post_id || 'new');

    function autosaveDraft() {
        try {
            var data = {};
            var basicIds = ['product_name','brand','sku','seo_title','seo_description','seo_keywords','seo_secondary_keywords'];
            basicIds.forEach(function(id) { var v=$('#'+id).val(); if(v) data[id]=v; });
            var nameFields = ['short_desc','intro','conclusion','compat_nota','catalog_url'];
            nameFields.forEach(function(n){ var v=$('[name="'+n+'"]').val(); if(v) data[n]=v; });
            var imgs = $('#images_urls').val().split('\n').filter(Boolean);
            if (imgs.length) data.images = imgs;
            // Specs
            var specs=[];
            $('#specs-body .ckg-row').each(function(){var k=$(this).find('input[name="spec_key[]"]').val();if(k) specs.push({key:k,value:$(this).find('input[name="spec_value[]"]').val()});});
            if (specs.length) data.specs = specs;
            // FAQ
            var faq=[];
            $('#faq-body .ckg-row').each(function(){var p=$(this).find('input[name="faq_pregunta[]"]').val();if(p) faq.push({pregunta:p,respuesta:$(this).find('textarea[name="faq_resp[]"]').val()});});
            if (faq.length) data.faq = faq;
            // Homo
            var homos=[];
            $('#homo-body .ckg-row').each(function(){var t=$(this).find('input[name="homo_tipo[]"]').val();if(t) homos.push({tipo:t,codigo:$(this).find('input[name="homo_codigo[]"]').val(),descripcion:''});});
            if (homos.length) data.homologacion = homos;

            if (Object.keys(data).length > 1) {
                data._saved_at = new Date().toLocaleTimeString('es-ES', {hour:'2-digit',minute:'2-digit'});
                localStorage.setItem(_autosaveKey, JSON.stringify(data));
                $('#autosave-indicator').text(' Borrador guardado a las ' + data._saved_at).show();
                setTimeout(function() { $('#autosave-indicator').fadeOut(1000); }, 3000);
            }
        } catch(e) {}
    }

    // Guardar cada 2 minutos si hay contenido
    if ($('#product_name').length) {
        _autosaveTimer = setInterval(autosaveDraft, 120000);

        // Indicador de auto-guardado
        if (!$('#autosave-indicator').length) {
            $('form').first().before('<div id="autosave-indicator" style="display:none;font-size:11px;color:#50575e;padding:4px 0;text-align:right"></div>');
        }

        // Recuperar borrador al cargar (si no hay datos pendientes del Express)
        if (!ADMIN.pending_autofill) {
            try {
                var saved = localStorage.getItem(_autosaveKey);
                if (saved && !$('#product_name').val().trim()) {
                    var d = JSON.parse(saved);
                    if (d && d.product_name) {
                        var $recoverBanner = $('<div class="notice notice-info" style="margin:8px 0 16px;padding:12px 16px;display:flex;align-items:center;gap:16px"><span> Hay un borrador guardado de <strong>' + d.product_name + '</strong> (guardado a las ' + (d._saved_at||'?') + ')</span><button type="button" class="button button-primary" id="btn-recover-draft">? Recuperar borrador</button><button type="button" class="button" id="btn-discard-draft"> Descartar</button></div>');
                        $('.ckg-wrap h1').after($recoverBanner);
                        $('#btn-recover-draft').on('click', function () { loadFormData(d); $recoverBanner.remove(); toast(' Borrador recuperado.'); });
                        $('#btn-discard-draft').on('click', function () { localStorage.removeItem(_autosaveKey); $recoverBanner.remove(); });
                    }
                }
            } catch(e) {}
        }

        // Limpiar borrador al enviar el formulario con exito
        $('form').on('submit', function () { try { localStorage.removeItem(_autosaveKey); } catch(e) {} });
    }

    /* 
       AUTO-SLUG EN TIEMPO REAL
     */
    $('#product_name').on('input', function () {
        var name  = $(this).val().trim();
        var $slug = $('[name="product_slug"], #product_slug');
        if ($slug.length && !$slug.data('manual')) {
            var slug = sanitizeSlug(name);
            $slug.val(slug);
        }
        // Tambien actualizar titulo de la pagina en el snippet preview
        updateSnippetPreview();
    });

    $('[name="product_slug"], #product_slug').on('input', function () {
        // Marcar como manual para no sobreescribir
        $(this).data('manual', $(this).val().trim() !== '');
    });

    /* 
       ANADIR BOTONES DE VERIFICACION AL CARGAR HOMOS
     */
    if (ADMIN.perplexity_available) {
        setTimeout(function () {
            $('#homo-body .ckg-row').each(function (i) { addHomoVerifyBtn($(this), i); });
        }, 800);
    }

    /* 
       VALIDACION + FEEDBACK ANTES DE ENVIAR EL FORMULARIO
     */
    $('form').on('submit', function (e) {
        // Solo interceptar el formulario principal de creacion
        if (!$(this).find('input[name="action"][value="ckg_create_product"]').length) return;

        var name = $('#product_name').val().trim();
        if (!name) {
            e.preventDefault();
            toast(' El nombre del producto es obligatorio.', 'warn');
            $('.ckg-tab[data-target="tab-basic"]').trigger('click');
            $('#product_name').focus().addClass('ckg-field-error');
            setTimeout(function(){ $('#product_name').removeClass('ckg-field-error'); }, 3000);
            return;
        }

        var hasVariation = $('#var-body .ckg-row input[name="var_label[]"]')
            .filter(function(){ return this.value.trim(); }).length > 0;
        var hasPrice = $('#var-body .ckg-row input[name="var_price[]"]')
            .filter(function(){ return parseFloat(this.value) > 0; }).length > 0;

        if (!hasVariation) {
            e.preventDefault();
            toast(' Anade al menos una variacion con nombre en la pestana Variaciones.', 'warn');
            $('.ckg-tab[data-target="tab-variations"]').trigger('click');
            return;
        }

        if (!hasPrice) {
            if (!confirm(' Ninguna variacion tiene precio.\n\nCrear el producto igualmente?')) {
                e.preventDefault();
                return;
            }
        }

        // Auto-guardado antes de enviar
        try { localStorage.removeItem('ckg_draft_new'); } catch(ex){}

        // Deshabilitar boton y mostrar progreso
        var $btn = $('button[type="submit"], input[type="submit"]', this).first();
        $btn.prop('disabled', true)
            .val(' Creando producto...')
            .html('<span class="ckg-spin"></span> Creando producto...');

        // Barra de progreso de creacion
        var $bar = $('<div id="ckg-create-progress" style="position:fixed;top:0;left:0;right:0;height:4px;background:#c0392b;z-index:99999;animation:ckg-progress-slide 3s ease-in-out infinite"></div>');
        $('body').append($bar);
        $('<style>@keyframes ckg-progress-slide{0%{width:0%}50%{width:70%}90%{width:90%}100%{width:95%}}</style>').appendTo('head');
    });

    /* 
       PERSISTIR DATOS DEL FORMULARIO AL CAMBIAR DE PESTANA
       Evita perder cambios si el servidor no los guardo
     */
    // Marcar el formulario como "sucio" cuando se modifica algo
    var _formDirty = false;
    $(document).on('input change', 'input, textarea, select', function(){
        _formDirty = true;
    });

    // Advertir antes de cerrar/navegar si hay cambios sin guardar
    $(window).on('beforeunload', function(){
        if (_formDirty && $('#product_name').length && !$('#ckg-create-progress').length) {
            return 'Seguro que quieres salir? Los cambios no guardados se perderan.';
        }
    });

    // Limpiar al enviar correctamente
    $('form').on('submit', function(){
        setTimeout(function(){ _formDirty = false; $(window).off('beforeunload'); }, 100);
    });

    /* ======================================================================
       IMPORTAR SPECS DESDE TEXTO DE FABRICANTE
    ====================================================================== */

    // Toggle del panel
    $(document).on('click', '#toggle-import-specs', function () {
        var $panel = $('#import-specs-panel');
        var $icon  = $('#toggle-import-specs-icon');
        if ($panel.is(':visible')) {
            $panel.slideUp(200);
            $icon.text('+');
        } else {
            $panel.slideDown(200);
            $icon.text('-');
        }
    });

    // Parsear con IA (Ollama/LLM)
    $(document).on('click', '#btn-parse-specs-text', function () {
        var text = $('#specs-import-text').val().trim();
        if (!text) { toast('Pega primero el texto del fabricante.', 'warn'); return; }

        var $btn  = $(this).text('Extrayendo...').prop('disabled', true);
        var $st   = $('#parse-specs-status').text('Procesando con IA...');

        $.post(ajax, {
            action:       'ckg_parse_specs_text',
            nonce:        nonce,
            text:         text,
            product_name: $('#product_name').val().trim(),
        }, function (r) {
            $btn.text('Extraer especificaciones con IA').prop('disabled', false);
            if (!r.success) {
                $st.text('Error: ' + r.data.message);
                toast(r.data.message, 'warn');
                return;
            }
            applyParsedSpecs(r.data.specs);
            $st.text(r.data.message);
            toast(r.data.message);
        }).fail(function () {
            $btn.text('Extraer especificaciones con IA').prop('disabled', false);
            $st.text('Error de conexion.');
        });
    });

    // Parsear sin IA (linea por linea -- formato "Clave: Valor")
    $(document).on('click', '#btn-parse-specs-manual', function () {
        var text = $('#specs-import-text').val().trim();
        if (!text) { toast('Pega primero el texto del fabricante.', 'warn'); return; }

        var specs = [];
        var lines = text.split('\n');
        lines.forEach(function (line) {
            line = line.trim();
            if (!line) return;
            // Intentar split por : o - o |
            var separators = [':', ' - ', ' | ', '	'];
            for (var i = 0; i < separators.length; i++) {
                var idx = line.indexOf(separators[i]);
                if (idx > 2 && idx < line.length - 1) {
                    var key = line.substring(0, idx).trim().replace(/^[-*??]\s*/, '');
                    var val = line.substring(idx + separators[i].length).trim();
                    if (key && val && key.length < 60 && key.length > 1) {
                        specs.push({ key: key, value: val });
                        return;
                    }
                }
            }
            // Si no tiene separador y tiene mas de 3 palabras, tratar como descripcion
        });

        if (!specs.length) {
            toast('No se encontraron pares Clave: Valor en el texto.', 'warn');
            return;
        }

        applyParsedSpecs(specs);
        $('#parse-specs-status').text(specs.length + ' especificaciones parseadas manualmente.');
        toast(specs.length + ' especificaciones anadidas a la tabla.');
    });

    // Funcion comun: rellenar la tabla de specs
    function applyParsedSpecs(specs) {
        if (!specs || !specs.length) return;

        // Preguntar si reemplazar o anadir
        var existing = $('#specs-body .ckg-row').length;
        var replace  = existing === 0 || confirm(
            'Hay ' + existing + ' especificaciones en la tabla.\n' +
            'OK = Reemplazar todas\nCancelar = Anadir a las existentes'
        );

        if (replace) {
            $('#specs-body').empty();
        }

        specs.forEach(function (s) {
            var $r = $($('#tmpl-spec-row').html());
            $r.find('input[name="spec_key[]"]').val(s.key || '');
            $r.find('input[name="spec_value[]"]').val(s.value || '');
            $('#specs-body').append($r);
        });

        // Ir al tab de contenido para ver el resultado
        setTimeout(function () {
            $('#import-specs-panel').slideUp(200);
            $('#toggle-import-specs-icon').text('+');
        }, 1500);
    }

    /* ======================================================================
       PANEL DE PRECIO EN TAB VARIACIONES
    ====================================================================== */

    // Calcular descuento y margen en tiempo real
    $(document).on('input', '#base_price, #sale_price, #cost_price', function () {
        var base = parseFloat($('#base_price').val()) || 0;
        var sale = parseFloat($('#sale_price').val()) || 0;
        var cost = parseFloat($('#cost_price').val()) || 0;

        // Descuento
        if (base > 0 && sale > 0 && sale < base) {
            var disc = Math.round((1 - sale / base) * 100);
            $('#discount-badge').text('-' + disc + '% dto');
        } else {
            $('#discount-badge').text('');
        }

        // Margen sobre precio de venta
        var pvp = (sale > 0 && sale < base) ? sale : base;
        if (pvp > 0 && cost > 0 && cost < pvp) {
            var margin = Math.round((pvp - cost) / pvp * 100);
            var color  = margin >= 30 ? '#27ae60' : margin >= 15 ? '#f39c12' : '#c0392b';
            $('#cost-margin-badge').text('Margen: ' + margin + '%').css('color', color);
        } else {
            $('#cost-margin-badge').text('');
        }
    });

    // Propagar precio a todas las variaciones
    $(document).on('click', '#btn-propagate-price-simple', function () {
        var base = $('#base_price').val().trim();
        var sale = $('#sale_price').val().trim();
        if (!base || parseFloat(base) <= 0) {
            toast('Introduce el precio base primero.', 'warn');
            return;
        }
        $('#var-body input[name="var_price[]"]').val(base);
        if (sale && parseFloat(sale) > 0) {
            $('#var-body input[name="var_sale[]"]').val(sale);
        }
        var count = $('#var-body .ckg-row').length;
        toast('Precio ' + base + ' EUR aplicado a ' + count + ' variaciones.');
    });

    // Al cargar datos (AutoFill, clonar, edicion) -- rellenar precio si viene
    var _orig_loadFormData = loadFormData;
    loadFormData = function(data) {
        _orig_loadFormData(data);
        if (data.base_price && parseFloat(data.base_price) > 0) {
            $('#base_price').val(parseFloat(data.base_price).toFixed(2)).trigger('input');
        }
        if (data.sale_price && parseFloat(data.sale_price) > 0) {
            $('#sale_price').val(parseFloat(data.sale_price).toFixed(2)).trigger('input');
        }
    };

    /* ======================================================================
       COMPETIDORES -- Analizar URLs y sintetizar con Ollama
    ====================================================================== */
    $('#btn-analyze-competitors').on('click', function () {
        var $btn    = $(this).prop('disabled', true).text('Analizando...');
        var urls    = $('#competitor-urls').val().trim();
        var name    = $('#product_name').val().trim();
        var section = $('#competitor-section').val() || 'all';
        var $res    = $('#competitor-results');

        if (!urls) {
            toast('Introduce al menos una URL de competidor (una por linea).', 'warn');
            $btn.prop('disabled', false).text('Analizar competidores');
            return;
        }
        var urlCount = urls.split('\n').filter(Boolean).length;
        $res.html('<div class="ckg-loading">Descargando y analizando ' + urlCount + ' URL(s)... puede tardar 30-60 segundos.</div>');

        $.post(ajax, {
            action: 'ckg_analyze_competitors',
            nonce:  nonce,
            urls:   urls,
            product_name: name,
            section: section,
        }, function (r) {
            $btn.prop('disabled', false).text('Analizar competidores');
            if (!r.success) { $res.html('<div class="ckg-err" style="padding:12px">Error: ' + r.data.message + '</div>'); return; }

            var raw = r.data.raw       || {};
            var syn = r.data.synthesis || {};
            var html = '<div class="ckg-comp-analysis">';

            // URLs analizadas
            var urls_ok = Object.keys(raw);
            html += '<div style="margin-bottom:14px"><strong>' + urls_ok.length + ' URLs analizadas:</strong> ';
            urls_ok.forEach(function(u){ html += '<span class="ckg-term-chip">' + (raw[u].domain || u) + '</span> '; });
            html += '</div>';

            // Imagen del producto de competidores
            if (syn.product_image) {
                html += '<div class="ckg-comp-synth">';
                html += '<strong>Imagen encontrada en competidor:</strong><br>';
                html += '<div style="display:flex;align-items:flex-start;gap:12px;margin-top:8px">';
                html += '<img src="' + syn.product_image + '" style="max-width:120px;max-height:120px;border-radius:4px;border:1px solid #e0e0e0" onerror="this.style.display=none">';
                html += '<div>';
                if (syn.images_found && syn.images_found.length > 1) {
                    html += '<p style="font-size:12px;color:#888;margin:0 0 6px">' + syn.images_found.length + ' imagenes encontradas</p>';
                    html += '<button type="button" class="button button-small" id="btn-import-comp-images" data-images="' + encodeURIComponent(JSON.stringify(syn.images_found)) + '">Importar imagenes</button>';
                } else {
                    html += '<button type="button" class="button button-small" id="btn-import-comp-images" data-images="' + encodeURIComponent(JSON.stringify([syn.product_image])) + '">Usar esta imagen</button>';
                }
                html += '</div></div></div>';
            }

            // Variaciones encontradas
            if (syn.variations_found && syn.variations_found.length) {
                html += '<div class="ckg-comp-synth"><strong>Variaciones/atributos (' + syn.variations_found.length + '):</strong> ';
                syn.variations_found.slice(0,10).forEach(function(v){ html += '<span class="ckg-term-chip">' + v.label + '</span> '; });
                html += '<br><button type="button" class="button button-small btn-apply-comp-variations" data-vars="' + encodeURIComponent(JSON.stringify(syn.variations_found)) + '" style="margin-top:6px">Aplicar variaciones</button></div>';
            }

            // Precios encontrados
            if (syn.prices_found && syn.prices_found.length) {
                html += '<div class="ckg-comp-synth"><strong>Precios encontrados:</strong> ' + syn.prices_found.join(' | ') + '</div>';
            }

            // Homologaciones detectadas
            if (syn.homologaciones_detected && syn.homologaciones_detected.length) {
                html += '<div class="ckg-comp-synth"><strong>Homologaciones detectadas:</strong> ';
                syn.homologaciones_detected.forEach(function(h){ html += '<span class="ckg-term-chip">' + h + '</span> '; });
                html += '</div>';
            }

            // -- Specs
            if (syn.specs && syn.specs.length) {
                html += '<div class="ckg-comp-synth">';
                html += '<h4>Especificaciones (' + syn.specs.length + '):</h4>';
                html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
                html += '<thead><tr>';
                html += '<th style="background:#2c3e50;color:#fff;padding:6px 10px;text-align:left">Parametro</th>';
                html += '<th style="background:#2c3e50;color:#fff;padding:6px 10px">Valor</th>';
                html += '<th style="background:#2c3e50;color:#fff;padding:6px 4px">Usar</th>';
                html += '</tr></thead><tbody>';
                syn.specs.forEach(function(s) {
                    var key = encodeURIComponent(s.key);
                    var val = encodeURIComponent(s.value);
                    html += '<tr>';
                    html += '<td style="padding:5px 10px;border-bottom:1px solid #eee">' + s.key + '</td>';
                    html += '<td style="padding:5px 10px;border-bottom:1px solid #eee">' + s.value + '</td>';
                    html += '<td style="padding:5px;text-align:center;border-bottom:1px solid #eee">';
                    html += '<input type="checkbox" class="comp-spec-chk" ';
                    html += 'data-key="' + key + '" data-val="' + val + '" checked>';
                    html += '</td></tr>';
                });
                html += '</tbody></table>';
                html += '<button type="button" class="button button-primary" ';
                html += 'id="btn-apply-comp-specs" style="margin-top:8px">';
                html += 'Anadir specs al formulario</button></div>';
            }

            // -- Intro (texto visible + boton)
            if (syn.intro) {
                var introText = syn.intro.replace(/\n/g, '<br>');
                html += '<div class="ckg-comp-synth">';
                html += '<h4>Introduccion generada:</h4>';
                html += '<div style="background:#f8f9fa;padding:10px;border-radius:4px;';
                html += 'font-size:13px;margin-bottom:8px;max-height:120px;overflow-y:auto">';
                html += introText + '</div>';
                html += '<button type="button" class="button button-primary ckg-apply-synth" ';
                html += 'data-field="intro" ';
                html += 'data-value="' + encodeURIComponent(syn.intro) + '">';
                html += 'Aplicar intro al formulario</button></div>';
            }

            // -- Conclusion (texto visible + boton)
            if (syn.conclusion) {
                var conclText = syn.conclusion.replace(/\n/g, '<br>');
                html += '<div class="ckg-comp-synth">';
                html += '<h4>Conclusion generada:</h4>';
                html += '<div style="background:#f8f9fa;padding:10px;border-radius:4px;';
                html += 'font-size:13px;margin-bottom:8px">';
                html += conclText + '</div>';
                html += '<button type="button" class="button button-primary ckg-apply-synth" ';
                html += 'data-field="conclusion" ';
                html += 'data-value="' + encodeURIComponent(syn.conclusion) + '">';
                html += 'Aplicar conclusion</button></div>';
            }

            // -- FAQ (parsear faq_text si no hay faq_parsed)
            var faq_arr = [];
            if (syn.faq_parsed && syn.faq_parsed.length) {
                faq_arr = syn.faq_parsed;
            } else if (syn.faq_text) {
                try {
                    var faq_clean = syn.faq_text.replace(/```json|```/g, '').trim();
                    var faq_dec = JSON.parse(faq_clean);
                    if (Array.isArray(faq_dec)) faq_arr = faq_dec;
                } catch(e) {}
            }
            if (faq_arr.length) {
                html += '<div class="ckg-comp-synth">';
                html += '<h4>FAQ (' + faq_arr.length + '):</h4>';
                html += '<ul style="margin:8px 0;padding-left:16px">';
                faq_arr.forEach(function(f) {
                    html += '<li style="margin-bottom:8px">';
                    html += '<strong>' + f.pregunta + '</strong><br>';
                    html += '<span style="font-size:12px;color:#50575e">' + f.respuesta + '</span>';
                    html += '</li>';
                });
                html += '</ul>';
                html += '<button type="button" class="button button-primary" ';
                html += 'id="btn-apply-comp-faq" ';
                html += 'data-faq="' + encodeURIComponent(JSON.stringify(faq_arr)) + '">';
                html += 'Aplicar FAQ al formulario</button></div>';
            }

            html += '</div>';
            $res.html(html);

        }).fail(function () {
            $btn.prop('disabled', false).text('Analizar competidores');
            $res.html('<div class="ckg-err" style="padding:12px">Error de conexion.</div>');
        });
    });

    $(document).on('click', '.btn-delete-attr', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!confirm('Eliminar atributo "' + name + '" sin migrar? Los datos se perderan.')) return;
        var $btn = $(this).prop('disabled', true).text('Eliminando...');
        $.post(ajax, { action:'ckg_delete_attribute', nonce:nonce, attr_id:id }, function (r) {
            if (r.success) { $btn.closest('tr').fadeOut(); toast('Atributo "' + name + '" eliminado.'); }
            else { $btn.prop('disabled',false).text('Eliminar'); toast('Error: ' + r.data.message,'err'); }
        });
    });

    $(document).on('click', '.btn-migrate-attr', function () {
        var $btn = $(this).prop('disabled',true).text('Migrando...');
        var id   = $(this).data('id');
        var name = $(this).data('name');
        $.post(ajax, { action:'ckg_migrate_attribute', nonce:nonce, attr_id:id }, function (r) {
            $btn.prop('disabled',false).text('Migrado');
            if (!r.success) { toast('Error: ' + r.data.message,'err'); return; }
            toast('"'+name+'" migrado a specs en ' + r.data.migrated + ' productos.');
            // Eliminar automaticamente si es seguro
            if (r.data.safe_to_delete) {
                $.post(ajax, { action:'ckg_delete_attribute', nonce:nonce, attr_id:id }, function(dr){
                    if (dr.success) $btn.closest('tr').fadeOut();
                });
            }
        });
    });

    // ?? Revision de homologaciones ????????????????????????????????????
    $(document).on('click', '#btn-review-homos', function () {
        var $btn   = $(this).prop('disabled',true).text('Revisando...');
        var limit  = parseInt($('#homo-review-limit').val()) || 10;
        var $res   = $('#homo-review-results');
        $res.html('<em>Consultando Perplexity... puede tardar 1-2 minutos para ' + limit + ' productos.</em>');

        $.ajax({ url:ajax, type:'POST', timeout:300000,
            data:{ action:'ckg_review_homos', nonce:nonce, limit:limit },
            success: function (r) {
                $btn.prop('disabled',false).text('Revisar homologaciones ahora');
                if (!r.success) { $res.html('<p class="ckg-err">Error: ' + r.data.message + '</p>'); return; }
                var d = r.data;
                var html = '<p><strong>' + d.reviewed + ' productos revisados. ' + d.total_alerts + ' alertas.</strong></p>';
                if (d.alerts.length) {
                    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
                    html += '<thead><tr><th style="background:#c0392b;color:#fff;padding:6px 10px;text-align:left">Producto</th><th style="background:#c0392b;color:#fff;padding:6px 10px">Homologacion</th><th style="background:#c0392b;color:#fff;padding:6px 10px">Nota</th><th style="background:#c0392b;color:#fff;padding:6px 10px">Accion</th></tr></thead><tbody>';
                    d.alerts.forEach(function (a) {
                        html += '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">'+a.product_name+'</td>';
                        html += '<td style="padding:6px 10px;border-bottom:1px solid #eee"><strong>'+a.tipo+' '+a.codigo+'</strong>'+(a.version_actual?'<br><small>Actual: '+a.version_actual+'</small>':'')+'</td>';
                        html += '<td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:12px">'+a.nota+'</td>';
                        html += '<td style="padding:6px 10px;border-bottom:1px solid #eee"><a href="'+a.edit_url+'" class="button button-small" target="_blank">Editar</a></td></tr>';
                    });
                    html += '</tbody></table>';
                } else {
                    html += '<p style="color:#27ae60">Todas las homologaciones revisadas estan vigentes.</p>';
                }
                $res.html(html);
            },
            error: function () { $btn.prop('disabled',false).text('Revisar homologaciones ahora'); $res.html('<p class="ckg-err">Error de conexion o timeout.</p>'); }
        });
    });

    // ?? Campo personalizado en checkout ?????????????????????????????
    $(document).on('click', '#btn-save-checkout-field', function () {
        var $btn = $(this).prop('disabled',true).text('Guardando...');
        $.post(ajax, {
            action:'ckg_save_checkout_field', nonce:nonce,
            product_id:    $('#checkout-field-product-id').val(),
            field_label:   $('#checkout-field-label').val(),
            field_hint:    $('#checkout-field-hint').val(),
            field_required:$('#checkout-field-required').is(':checked') ? 1 : 0,
        }, function (r) {
            $btn.prop('disabled',false).text('Guardar configuracion');
            if (!r.success) { $('#checkout-field-status').html('<span style="color:#c0392b">Error: '+r.data.message+'</span>'); return; }
            $('#checkout-field-status').html('<span style="color:#27ae60">'+r.data.message+'</span>');
            setTimeout(function(){ $('#checkout-field-status').text(''); }, 4000);
        });
    });

    // Modo edicion -- cargar producto existente
    $(document).on('click', '.btn-edit-with-plugin', function () {
        var pid = $(this).data('product-id') || $(this).data('id');
        if (!pid) return;

        // Si estamos en la pagina principal del plugin, cargar en formulario
        if (typeof loadFormData === 'function') {
            var $btn = $(this).text('Cargando...').prop('disabled', true);
            $.post(ajax, { action: 'ckg_load_product_for_edit', nonce: nonce, product_id: pid },
            function (r) {
                $btn.text('Editar con plugin').prop('disabled', false);
                if (!r.success) { toast('Error: ' + r.data.message, 'err'); return; }
                loadFormData(r.data);
                $('#edit_product_id').val(pid);
                $('.ckg-wrap h1').after(
                    '<div class="notice notice-warning" style="margin:8px 0 16px;padding:12px 16px">' +
                    '<strong>Modo edicion activo</strong> - ' + r.data.product_name +
                    ' (ID ' + pid + ')</div>'
                );
                $('.ckg-tab[data-target="tab-basic"]').trigger('click');
                window.scrollTo(0, 0);
                toast('Producto cargado para edicion.');
            }).fail(function () { $btn.text('Editar con plugin').prop('disabled', false); });
        } else {
            // Desde historial u otra pagina: redirigir al formulario con el producto
            window.location.href = ajaxurl.replace('admin-ajax.php', '')
                + 'admin.php?page=cmskart-product-generator&edit_product=' + pid;
        }
    });

    // Boton Editar en tabla historial
    setTimeout(function () {
        $('.ckg-hist-actions').each(function () {
            if ($(this).find('.btn-edit-with-plugin').length) return;
            var pid = $(this).closest('tr').find('[data-product-id]').data('product-id');
            if (!pid) return;
            $(this).prepend(
                '<button type="button" class="button button-small btn-edit-with-plugin" ' +
                'data-product-id="' + pid + '" ' +
                'style="border-color:#2271b1;color:#2271b1">Editar</button> '
            );
        });
    }, 800);

    // ?? Importar imagenes de competidores al formulario ?????????????
    $(document).on('click', '#btn-import-comp-images', function () {
        var images;
        try {
            images = JSON.parse(decodeURIComponent($(this).data('images') || '[]'));
        } catch(e) { images = []; }
        if (!images.length) { toast('Sin imagenes para importar.', 'warn'); return; }
        var cur = $('#images_urls').val().trim();
        var newUrls = images.join('\n');
        $('#images_urls').val(cur ? cur + '\n' + newUrls : newUrls);
        if (typeof renderImgPreview === 'function') renderImgPreview();
        toast(images.length + ' imagen(es) anadidas al formulario.');
        $('.ckg-tab[data-target="tab-images"]').trigger('click');
    });

    // Restaurar backup de un producto enriquecido
    $(document).on('click', '.ckg-restore-backup', function (e) {
        e.preventDefault();
        var pid = $(this).data('id');
        if (!confirm('Restaurar descripcion original del producto ID ' + pid + '?')) return;
        var $btn = $(this).text('Restaurando...').prop('disabled', true);
        $.post(ajax, { action: 'ckg_restore_backup', nonce: nonce, product_id: pid }, function (r) {
            if (r.success) {
                $btn.text('Restaurado').css('color', '#27ae60');
                toast('Backup restaurado: ' + r.data.message);
            } else {
                $btn.text('Error').prop('disabled', false);
                toast('Error: ' + r.data.message, 'err');
            }
        });
    });

    // Info del prompt seleccionado
    $(document).on('change', '#enrich-prompt-id', function () {
        var opt = $(this).find(':selected');
        var info = 'Modelo: ' + opt.data('modelo');
        if (opt.data('scraper') == 1) info += ' | Scraper: ON';
        if (opt.data('chroma')  == 1) info += ' | ChromaDB: ON';
        $('#prompt-enrich-info').text(info);
    }).trigger('change');

    // ?? Buscador de producto individual ??????????????????????????
    var _enrichSearchIds = [];

    $('#btn-enrich-search').on('click', function () {
        var q = $('#enrich-search-query').val().trim();
        if (q.length < 2) { toast('Escribe al menos 2 caracteres', 'err'); return; }
        var $btn = $(this).text('Buscando...').prop('disabled', true);
        $.post(ajax, { action: 'ckg_enrich_search_product', nonce: nonce, query: q },
        function (r) {
            $btn.text('Buscar').prop('disabled', false);
            if (!r.success) { toast(r.data.message, 'err'); return; }
            var results = r.data.results;
            var tbody = $('#enrich-search-tbody').empty();

            if (!results.length) {
                tbody.append('<tr><td colspan="5" style="padding:12px;text-align:center;color:#666">No se encontraron productos.</td></tr>');
                $('#enrich-search-results').show();
                $('#enrich-selected-btn, #btn-enrich-selected').hide();
                return;
            }

            results.forEach(function (p) {
                var score = p.seo_score || 0;
                var scoreColor = score >= 70 ? '#27ae60' : score >= 50 ? '#f39c12' : '#c0392b';
                var estado = p.enriched
                    ? '<span style="color:#27ae60">? Enriquecido</span>'
                    : '<span style="color:#999">? Pendiente</span>';
                var thumb = p.thumb
                    ? '<img src="' + p.thumb + '" style="width:32px;height:32px;object-fit:cover;border-radius:3px;margin-right:6px">'
                    : '<span style="display:inline-block;width:32px;height:32px;background:#eee;border-radius:3px;margin-right:6px"></span>';
                tbody.append(
                    '<tr>' +
                    '<td><input type="checkbox" class="enrich-search-cb" value="' + p.id + '"></td>' +
                    '<td>' + thumb + '<a href="' + p.edit_url + '" target="_blank">' + p.name + '</a></td>' +
                    '<td><code>' + (p.sku || '?') + '</code></td>' +
                    '<td><strong style="color:' + scoreColor + '">' + score + '</strong>/100</td>' +
                    '<td>' + estado + '</td>' +
                    '</tr>'
                );
            });

            $('#enrich-search-results').show();
            $('#enrich-search-count').text(results.length + ' productos encontrados');
            $('#btn-enrich-selected').hide();
            _enrichSearchIds = [];
        });
    });

    // Enter en el buscador
    $('#enrich-search-query').on('keypress', function (e) {
        if (e.which === 13) $('#btn-enrich-search').trigger('click');
    });

    // Check all
    $('#enrich-search-check-all').on('change', function () {
        $('.enrich-search-cb').prop('checked', this.checked).trigger('change');
    });

    // Actualizar bot?n enriquecer seleccionados
    $(document).on('change', '.enrich-search-cb', function () {
        _enrichSearchIds = $('.enrich-search-cb:checked').map(function () {
            return parseInt(this.value);
        }).get();
        if (_enrichSearchIds.length > 0) {
            $('#btn-enrich-selected').show().text('? Enriquecer ' + _enrichSearchIds.length + ' seleccionado' + (_enrichSearchIds.length > 1 ? 's' : ''));
        } else {
            $('#btn-enrich-selected').hide();
        }
    });

    // Enriquecer seleccionados
    $('#btn-enrich-selected').on('click', function () {
        if (!_enrichSearchIds.length) return;
        var promptId = parseInt($('#enrich-search-prompt-id').val()) || 1;
        var $btn = $(this).prop('disabled', true);
        var total = _enrichSearchIds.length;
        var done  = 0;
        var $prog = $('#enrich-search-progress').show();
        var $fill = $('#enrich-search-fill');
        var $lbl  = $('#enrich-search-label');

        function processNext() {
            if (done >= total) {
                $btn.prop('disabled', false);
                $lbl.text('Completado ? ' + done + ' productos enriquecidos');
                toast('? ' + done + ' productos enriquecidos');
                return;
            }
            var pid = _enrichSearchIds[done];
            $lbl.text('Procesando ' + (done + 1) + '/' + total + '...');
            $fill.css('width', Math.round(done / total * 100) + '%');

            $.post(ajax, {
                action:     'ckg_enricher_process',
                nonce:      nonce,
                product_id: pid,
                prompt_id:  promptId,
            }, function () {
                done++;
                processNext();
            }).fail(function () {
                done++;
                processNext();
            });
        }
        processNext();
    });

    // Toggle panel stale en enriquecimiento
    $(document).on('change', '#enrich-mode', function () {
        if ($(this).val() === 'stale') {
            $('#enrich-stale-opts').show();
            $('#enrich-only-basic').closest('tr').hide();
        } else {
            $('#enrich-stale-opts').hide();
            $('#enrich-only-basic').closest('tr').show();
        }
    });

    /* Progress bar CSS */
    /* 
       CHROMADB -- Deteccion duplicados, FAQ inteligente,
                  conocimiento de marcas, canibalizacion keywords
     */

    var _chromaAvailable = ADMIN.chroma_available || false;

    //  Panel de ajustes: ping y bulk index 

    $('#btn-chroma-ping').on('click', function () {
        var $btn = $(this).html(spin('Conectando...')).prop('disabled', true);
        var $st  = $('#chroma-status-text');
        $.post(ajax, { action: 'ckg_chroma_ping', nonce: nonce }, function (r) {
            $btn.html(' Verificar conexion ChromaDB').prop('disabled', false);
            if (!r.success || (!r.data.ok && !r.data.online)) {
                $st.html('<span style="color:#c0392b"> ChromaDB no responde. Comprueba que esta corriendo en el endpoint configurado.</span>');
                return;
            }
            var d = r.data;
            var apiLabel = d.api_version ? ' (API ' + d.api_version + ')' : '';
            $st.html('<span style="color:#27ae60"> ChromaDB ' + (d.version||d.chroma_version||'') + apiLabel + ' online</span>');
            _chromaAvailable = true;

            // Mostrar estadisticas de colecciones (puede venir en d.collections o d.stats.collections)
            var collections = d.collections || (d.stats && d.stats.collections) || {};
            if (collections && Object.keys(collections).length) {
                var html = '';
                Object.entries(collections).forEach(function(entry) { var col = entry[1]; var colName = entry[0];
                    var labels = {
                        ckg_products: ' Productos',
                        ckg_faq:      ' FAQ',
                        ckg_brands:   ' Marcas',
                        ckg_keywords: ' Keywords'
                    };
                    html += '<div class="ckg-chroma-stat">' +
                        (labels[col.name] || col.name) +
                        '<span class="ckg-chroma-count">' + col.count.toLocaleString() + '</span>' +
                        '</div>';
                });
                $('#chroma-stats-grid').html(html);
                $('#chroma-stats-panel').show();
            }
        }).fail(function () {
            $btn.html(' Verificar conexion ChromaDB').prop('disabled', false);
            $st.html('<span style="color:#c0392b"> Error de conexion.</span>');
        });
    });

    // Indexacion masiva del catalogo
    $('#btn-chroma-bulk-index').on('click', function () {
        if (!confirm('Indexar todos los productos WooCommerce en ChromaDB?\n\nPuede tardar varios minutos dependiendo del tamano del catalogo y la velocidad de Ollama.')) return;
        var $btn = $(this).prop('disabled', true).html(spin('Indexando...'));
        $('#chroma-bulk-progress').show();

        function runBatch(offset) {
            $.post(ajax, { action: 'ckg_chroma_bulk_index', nonce: nonce, offset: offset, batch: 10 }, function (r) {
                if (!r.success) {
                    $btn.html(' Indexar todo el catalogo').prop('disabled', false);
                    toast(' ' + r.data.message, 'err');
                    return;
                }
                var d = r.data;
                var pct = d.total > 0 ? Math.round((d.offset + d.indexed) / d.total * 100) : 100;
                $('#chroma-bulk-fill').css('width', pct + '%');
                $('#chroma-bulk-label').text((d.offset + d.indexed) + ' / ' + d.total + ' productos indexados (' + pct + '%)');

                if (!d.done) {
                    setTimeout(function() { runBatch(d.offset); }, 500);
                } else {
                    $btn.html(' Indexacion completada').prop('disabled', false);
                    toast(' ' + d.total + ' productos indexados en ChromaDB.');
                    _chromaAvailable = true;
                }
            }).fail(function () {
                $btn.html(' Indexar todo el catalogo').prop('disabled', false);
                toast(' Error durante la indexacion.', 'err');
            });
        }
        runBatch(0);
    });

    //  1. DETECCION DE DUPLICADOS al escribir el nombre 

    var _dupCheckTimer;
    if (_chromaAvailable) {
        $(document).on('input', '#product_name', function () {
            var name = $(this).val().trim();
            clearTimeout(_dupCheckTimer);
            if (name.length < 5) { $('#chroma-duplicates').hide(); return; }

            _dupCheckTimer = setTimeout(function () {
                $.post(ajax, { action: 'ckg_chroma_similar', nonce: nonce, query: name }, function (r) {
                    if (!r.success || !r.data.results.length) { $('#chroma-duplicates').hide(); return; }
                    var results = r.data.results;
                    var html = '<div class="ckg-chroma-dup-banner">';
                    html += '<strong> ' + results.length + ' producto(s) similar(es) en el catalogo:</strong> ';
                    html += 'Quieres editar uno de ellos en vez de crear uno nuevo?<br>';
                    results.forEach(function (p) {
                        html += '<div class="ckg-chroma-dup-item">';
                        html += '<span class="ckg-chroma-sim" title="Similitud semantica">' + p.similarity + '%</span> ';
                        html += '<strong>' + p.name + '</strong>';
                        if (p.brand) html += ' <span class="ckg-hist-brand">' + p.brand + '</span>';
                        if (p.price > 0) html += ' - ' + p.price.toFixed(2).replace('.', ',') + 'EUR';
                        html += ' <a href="' + p.edit_url + '" class="button button-small" target="_blank"> Editar</a>';
                        html += ' <button type="button" class="button button-small btn-clone-chroma" data-id="' + p.product_id + '"> Clonar como base</button>';
                        html += '</div>';
                    });
                    html += '<button type="button" class="button-link ckg-chroma-dup-dismiss" style="margin-top:6px;font-size:11px">Ignorar y crear nuevo producto</button>';
                    html += '</div>';

                    var $dup = $('#chroma-duplicates');
                    if (!$dup.length) {
                        $('#product_name').closest('tr').after('<tr id="chroma-duplicates-row"><td colspan="2"><div id="chroma-duplicates"></div></td></tr>');
                    }
                    $('#chroma-duplicates').html(html).show();
                });
            }, 800);
        });

        $(document).on('click', '.ckg-chroma-dup-dismiss', function () { $('#chroma-duplicates-row').hide(); });
        $(document).on('click', '.btn-clone-chroma', function () {
            var pid = $(this).data('id');
            $('#chroma-duplicates-row').hide();
            // Reusar el mismo handler de clonar
            $.post(ajax, { action: 'ckg_clone_product', nonce: nonce, product_id: pid }, function (r) {
                if (!r.success) { toast(' ' + r.data.message, 'err'); return; }
                loadFormData(r.data);
                toast(' Datos cargados desde producto similar. Modifica lo necesario.');
                $('.ckg-tab[data-target="tab-basic"]').trigger('click');
            });
        });
    }

    //  2. FAQ INTELIGENTE desde el catalogo 

    $(document).on('click', '#btn-chroma-suggest-faq', function () {
        var name  = $('#product_name').val().trim();
        var brand = $('#brand').val().trim();
        var cats  = $('input[name="categories"]').val().trim();
        if (!name) { toast(' Rellena el nombre del producto primero.', 'warn'); return; }

        var $btn = $(this).html(spin('Buscando FAQ similares...')).prop('disabled', true);
        $.post(ajax, {
            action:       'ckg_chroma_suggest_faq',
            nonce:        nonce,
            product_name: name,
            brand:        brand,
            category:     cats.split(',')[0].trim()
        }, function (r) {
            $btn.html(' Sugerir FAQ del catalogo').prop('disabled', false);
            if (!r.success || !r.data.faq.length) {
                toast('i Sin FAQ similares en el catalogo todavia. Crea mas productos primero.'); return;
            }
            var faq = r.data.faq;
            var html = '<div class="ckg-chroma-faq-suggestions">';
            html += '<h4 style="margin:0 0 10px"> ' + faq.length + ' FAQ similares encontradas en el catalogo</h4>';
            html += '<p style="font-size:12px;color:#888;margin:0 0 10px">Selecciona las que quieras anadir:</p>';
            faq.forEach(function (f, i) {
                html += '<label class="ckg-chroma-faq-item">';
                html += '<input type="checkbox" class="chroma-faq-chk" data-pregunta="' + encodeURIComponent(f.pregunta) + '" data-respuesta="' + encodeURIComponent(f.respuesta) + '" checked>';
                html += '<div class="ckg-chroma-faq-content">';
                html += '<span class="ckg-chroma-sim">' + f.similarity + '%</span> ';
                html += '<strong>' + f.pregunta + '</strong>';
                html += '<p style="font-size:12px;color:#666;margin:2px 0 0">' + f.respuesta.substr(0,100) + '...</p>';
                html += '<small style="color:#aaa">De: ' + (f.product_name||'?') + '</small>';
                html += '</div></label>';
            });
            html += '<button type="button" class="button button-primary" id="btn-apply-chroma-faq" style="margin-top:10px"> Anadir seleccionadas al formulario</button>';
            html += '</div>';

            var $panel = $('#chroma-faq-panel');
            if (!$panel.length) $('#btn-generate-faq-answers').closest('div').after('<div id="chroma-faq-panel"></div>');
            $('#chroma-faq-panel').html(html);
        }).fail(function () { $btn.html(' Sugerir FAQ del catalogo').prop('disabled', false); });
    });

    $(document).on('click', '#btn-apply-chroma-faq', function () {
        var $tb = $('#faq-body').empty();
        $('.chroma-faq-chk:checked').each(function () {
            var $r = $($('#tmpl-faq-row').html());
            $r.find('input[name="faq_pregunta[]"]').val(decodeURIComponent($(this).data('pregunta')));
            $r.find('textarea[name="faq_resp[]"]').val(decodeURIComponent($(this).data('respuesta')));
            $tb.append($r);
        });
        var count = $('.chroma-faq-chk:checked').length;
        $('#chroma-faq-panel').hide();
        toast(' ' + count + ' FAQ del catalogo anadidas.');
        $('.ckg-tab[data-target="tab-content"]').trigger('click');
    });

    //  3. CONOCIMIENTO DE MARCA al escribir en el campo Brand 

    var _brandKnowledgeTimer;
    if (_chromaAvailable) {
        $(document).on('input', '#brand', function () {
            var brand = $(this).val().trim();
            clearTimeout(_brandKnowledgeTimer);
            if (brand.length < 3) { $('#chroma-brand-knowledge').hide(); return; }

            _brandKnowledgeTimer = setTimeout(function () {
                $.post(ajax, { action: 'ckg_chroma_brand', nonce: nonce, brand: brand }, function (r) {
                    if (!r.success || !r.data.products_found) return;
                    var d = r.data;
                    if (!d.homos.length && !Object.keys(d.typical_specs||{}).length) return;

                    var html = '<div class="ckg-chroma-brand-tip">';
                    html += '<strong> Conocimiento acumulado de ' + brand + ' (' + d.products_found + ' productos):</strong><br>';

                    if (d.homos.length) {
                        html += '<span style="font-size:12px">Homologaciones habituales: ' +
                            d.homos.map(function(h){ return '<code>' + h + '</code>'; }).join(' - ') + '</span><br>';
                        html += '<button type="button" class="button button-small" id="btn-apply-brand-homos" data-homos="' +
                            encodeURIComponent(JSON.stringify(d.homos)) + '" style="margin-top:4px"> Aplicar homologaciones</button> ';
                    }
                    if (d.price_range) {
                        html += '<span style="font-size:12px">Rango de precios habitual: <strong>' + d.price_range + '</strong></span>';
                    }
                    html += '</div>';

                    var $kb = $('#chroma-brand-knowledge');
                    if (!$kb.length) {
                        $('#brand').closest('tr').after('<tr id="chroma-brand-knowledge-row"><td colspan="2"><div id="chroma-brand-knowledge"></div></td></tr>');
                    }
                    $('#chroma-brand-knowledge').html(html).show();
                });
            }, 1000);
        });

        $(document).on('click', '#btn-apply-brand-homos', function () {
            var homos = JSON.parse(decodeURIComponent($(this).data('homos')));
            var $tb = $('#homo-body').empty();
            homos.forEach(function (h) {
                var parts = h.trim().split(/\s+/);
                var tipo  = parts[0] || '';
                var codigo= parts.slice(1).join(' ') || '';
                var $r = $($('#tmpl-homo-row').html());
                $r.find('input[name="homo_tipo[]"]').val(tipo);
                $r.find('input[name="homo_codigo[]"]').val(codigo);
                $tb.append($r);
            });
            $('#chroma-brand-knowledge-row').hide();
            toast(' ' + homos.length + ' homologaciones de ' + $('#brand').val() + ' aplicadas desde el catalogo.');
            $('.ckg-tab[data-target="tab-homol"]').trigger('click');
        });
    }

    //  4. CANIBALIZACION DE KEYWORDS al escribir el focus keyword 

    var _kwTimer;
    if (_chromaAvailable) {
        $(document).on('input', '#seo_keywords', function () {
            var kw = $(this).val().trim();
            clearTimeout(_kwTimer);
            if (kw.length < 4) { $('#chroma-kw-warning').hide(); return; }

            _kwTimer = setTimeout(function () {
                $.post(ajax, { action: 'ckg_chroma_cannibalization', nonce: nonce, keyword: kw, product_id: 0 }, function (r) {
                    var $warn = $('#chroma-kw-warning');
                    if (!r.success || !r.data.conflicts.length) { $warn.hide(); return; }

                    var conflicts = r.data.conflicts;
                    var html = '<div class="ckg-chroma-kw-warn">';
                    html += ' <strong>Posible canibalizacion SEO:</strong> ' + conflicts.length + ' producto(s) ya usan keywords muy similares:<br>';
                    conflicts.forEach(function (c) {
                        html += '<span style="font-size:12px">- <strong>' + c.product_name + '</strong> -> "<em>' + c.keyword + '</em>" (' + c.similarity + '% similar) ' +
                            '<a href="' + c.edit_url + '" target="_blank" style="font-size:11px">Ver</a></span><br>';
                    });
                    html += '<span style="font-size:11px;color:#888">Considera usar una keyword mas especifica para este producto.</span>';
                    html += '</div>';

                    if (!$warn.length) {
                        $('#seo_keywords').closest('tr').after('<tr id="chroma-kw-warning-row"><td colspan="2"><div id="chroma-kw-warning"></div></td></tr>');
                    }
                    $('#chroma-kw-warning').html(html).show();
                });
            }, 1200);
        });
    }

    //  Boton de sugerir FAQ solo aparece si ChromaDB disponible 
    if (_chromaAvailable) {
        setTimeout(function () {
            if (!$('#btn-chroma-suggest-faq').length) {
                $('#btn-generate-faq-answers').after(
                    ' <button type="button" class="button" id="btn-chroma-suggest-faq" style="border-color:#7c3aed;color:#7c3aed" title="Busca FAQ similares en tu catalogo (ChromaDB)">' +
                    ' Sugerir FAQ del catalogo</button>'
                );
            }
        }, 600);
    }

    /* Progress bar CSS */
    // Inyectar CSS del progress bar
    var _pbCss = [
        '#ckg-progress-bar{position:fixed;bottom:0;left:0;right:0;',
        'background:#2c3e50;color:#fff;padding:14px 24px;',
        'display:flex;align-items:center;gap:16px;z-index:99998;font-size:13px}',
        '.ckg-pb-inner{flex:1;height:6px;background:#c0392b;border-radius:3px;',
        'animation:ckg-pulse 1.2s ease-in-out infinite}',
        '@keyframes ckg-pulse{0%,100%{opacity:.5}50%{opacity:1}}'
    ].join('');
    $('<style>' + _pbCss + '</style>').appendTo('head');

})(jQuery, typeof CKG_ADMIN !== 'undefined' ? CKG_ADMIN : {});
