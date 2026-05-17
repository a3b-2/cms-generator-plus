/**
 * CMSKart Product Generator — Frontend JS
 * Accordion FAQ + Calculadora accesorios + Video lazy
 */
(function ($) {
    'use strict';

    var basePrice = 0;

    /* ══════════════════════════════════════════════════════════════
       FAQ ACCORDION
       Robusto: usa data-expanded en lugar de aria-expanded
       para evitar interferencias de temas/plugins WC
    ══════════════════════════════════════════════════════════════ */
    function initFaqAccordion() {
        // Buscar por clase y también por estructura de WooCommerce
        // que puede envolver el contenido en divs adicionales
        $(document).on('click', '.ckg-faq-q', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn    = $(this);
            var $item   = $btn.closest('.ckg-faq-item');
            var $answer = $item.find('.ckg-faq-a').first();
            var isOpen  = $btn.hasClass('ckg-faq-open');

            // Cerrar todos del mismo grupo
            var $group  = $btn.closest('.ckg-faq');
            $group.find('.ckg-faq-q').removeClass('ckg-faq-open').attr('aria-expanded', 'false');
            $group.find('.ckg-faq-a').stop(true).slideUp(200, function(){ $(this).hide(); });

            if (!isOpen) {
                $btn.addClass('ckg-faq-open').attr('aria-expanded', 'true');
                $answer.stop(true).slideDown(250);
            }
        });
    }

    // Inicializar cuando el DOM esté listo Y también cuando WC carga el contenido
    // (algunos temas con tabs cargan el contenido con AJAX)
    $(document).ready(initFaqAccordion);

    // Re-init si WooCommerce carga tabs con AJAX (variaciones, etc.)
    $(document).on('wc_variation_form_init', initFaqAccordion);

    /* ══════════════════════════════════════════════════════════════
       CALCULADORA DE ACCESORIOS
    ══════════════════════════════════════════════════════════════ */
    function getBasePrice() {
        if (basePrice > 0) return basePrice;
        var raw = $('.summary .price .woocommerce-Price-amount bdi')
            .first().text()
            .replace(/[^\d,.]/g, '')
            .replace(',', '.');
        return parseFloat(raw) || 0;
    }

    function updateTotal() {
        var accTotal = 0;
        $('.ckg-acc-check:checked').each(function () {
            accTotal += parseFloat($(this).data('price')) || 0;
        });
        var base  = getBasePrice();
        var total = base + accTotal;
        var symbol = (typeof CKG !== 'undefined' && CKG.currency) ? CKG.currency : '€';

        if (total > 0) {
            $('.ckg-total-amount').text(total.toFixed(2).replace('.', ',') + symbol);
        } else if (accTotal > 0) {
            $('.ckg-total-amount').text('+ ' + accTotal.toFixed(2).replace('.', ',') + symbol + ' en complementos');
        } else {
            $('.ckg-total-amount').text('—');
        }
    }

    $(document).on('change', '.ckg-acc-check', updateTotal);
    $(document).on('show_variation', function (e, variation) {
        if (variation && variation.display_price) basePrice = parseFloat(variation.display_price) || 0;
        updateTotal();
    });
    $(document).on('hide_variation reset_data', function () { basePrice = 0; updateTotal(); });

    /* ══════════════════════════════════════════════════════════════
       VIDEO LAZY LOAD
       Los iframes de YouTube/Vimeo se cargan al entrar en viewport
    ══════════════════════════════════════════════════════════════ */
    function initVideoLazy() {
        if (!('IntersectionObserver' in window)) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var $iframe = $(entry.target);
                    var src = $iframe.data('src');
                    if (src) {
                        $iframe.attr('src', src).removeAttr('data-src');
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '200px' });

        // Convertir iframes a lazy (si tienen data-src)
        $('.ckg-video-responsive iframe[data-src]').each(function () {
            observer.observe(this);
        });
    }

    /* ══════════════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════════════ */
    $(window).on('load', function () {
        updateTotal();
        initVideoLazy();
    });

})(jQuery);
