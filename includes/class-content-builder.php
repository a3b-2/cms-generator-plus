<?php
/**
 * CKG_Content_Builder
 * Genera HTML enriquecido para productos de karting en CMSKart.es
 *
 * Secciones disponibles:
 *  1. Introducción (con imagen)
 *  2. Homologación  ← específico karting
 *  3. Características técnicas (H3 libres)
 *  4. Compatibilidad (chasis / categorías)  ← específico karting
 *  5. Tabla de especificaciones técnicas
 *  6. ¿Para qué categoría es?  ← específico karting
 *  7. FAQ accordion + schema FAQ
 *  8. Conclusión + CTA
 *  9. Botón guía/catálogo
 * 10. Nota de revisión
 * 11. Schema FAQ JSON-LD
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Content_Builder {

    public static function build( array $data ): string {
        $html = '';

        if ( ! empty( $data['intro'] ) ) {
            $html .= self::section_intro( $data['intro'], $data['intro_image'] ?? '' );
        }

        if ( ! empty( $data['homologacion'] ) ) {
            $html .= self::section_homologacion( $data['homologacion'] );
        }

        if ( ! empty( $data['caracteristicas'] ) ) {
            $html .= self::section_caracteristicas( $data['caracteristicas'] );
        }

        if ( ! empty( $data['compatibilidad'] ) ) {
            $html .= self::section_compatibilidad(
                $data['compatibilidad'],
                $data['compat_image'] ?? ''
            );
        }

        if ( ! empty( $data['specs'] ) ) {
            $html .= self::section_specs( $data['specs'], $data['specs_image'] ?? '' );
        }

        if ( ! empty( $data['categorias_uso'] ) ) {
            $html .= self::section_categorias_uso( $data['categorias_uso'] );
        }

        if ( ! empty( $data['faq'] ) ) {
            $html .= self::section_faq( $data['faq'], $data['faq_image'] ?? '' );
        }

        // ── Video embebido (YouTube, Vimeo, URL directa) ──────────────────
        if ( ! empty( $data['video_url'] ) ) {
            $html .= self::section_video( $data['video_url'], $data['video_title'] ?? '', $data['video_description'] ?? '' );
        }

        if ( ! empty( $data['conclusion'] ) ) {
            $html .= self::section_conclusion( $data['conclusion'] );
        }

        if ( ! empty( $data['catalog_url'] ) ) {
            $html .= self::catalog_button( $data['catalog_url'], $data['catalog_label'] ?? '' );
        }

        if ( ! empty( $data['revision_date'] ) ) {
            $html .= '<p class="ckg-revision-note"><em>*Revisado por equipo técnico CMSKart. Actualizado: ' . esc_html( $data['revision_date'] ) . '*</em></p>';
        }

        if ( ! empty( $data['faq'] ) ) {
            $html .= self::faq_schema( $data['faq'] );
        }

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────

    private static function section_intro( string $text, string $image = '' ): string {
        $img = $image
            ? '<figure class="ckg-intro-img"><img src="' . esc_url( $image ) . '" alt="" loading="lazy"></figure>'
            : '';
        return '<div class="ckg-intro">' . wp_kses_post( $text ) . $img . '</div>';
    }

    // ── HOMOLOGACIÓN ──────────────────────────────────────────────────────
    // $data: [ ['tipo'=>'CIK-FIA', 'codigo'=>'FIA 8877-2022', 'descripcion'=>'...'], ... ]
    private static function section_homologacion( array $homos ): string {
        $html  = '<h2 class="ckg-h2">Homologación</h2>';
        $html .= '<div class="ckg-homo-grid">';

        foreach ( $homos as $h ) {
            $html .= '<div class="ckg-homo-badge">';
            $html .= '<span class="ckg-homo-tipo">' . esc_html( $h['tipo'] ) . '</span>';
            $html .= '<span class="ckg-homo-code">' . esc_html( $h['codigo'] ) . '</span>';
            if ( ! empty( $h['descripcion'] ) ) {
                $html .= '<span class="ckg-homo-desc">' . esc_html( $h['descripcion'] ) . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    // ── CARACTERÍSTICAS ────────────────────────────────────────────────────
    private static function section_caracteristicas( array $items ): string {
        $html = '<h2 class="ckg-h2">Características</h2>';
        foreach ( $items as $item ) {
            $html .= '<h3 class="ckg-h3">' . esc_html( $item['titulo'] ) . '</h3>';
            $html .= '<p>' . wp_kses_post( $item['texto'] ) . '</p>';
        }
        return $html;
    }

    // ── COMPATIBILIDAD ─────────────────────────────────────────────────────
    // $data: { 'chasis': ['OTK Tony Kart', 'Intrepid'], 'categorias': ['KZ', 'OK'], 'nota': '...' }
    private static function section_compatibilidad( array $data, string $image = '' ): string {
        $html  = '<h2 class="ckg-h2">Compatibilidad</h2>';
        $html .= '<div class="ckg-compat-wrap">';

        if ( ! empty( $data['chasis'] ) ) {
            $html .= '<div class="ckg-compat-block">';
            $html .= '<h3 class="ckg-h3">Chasis compatibles</h3>';
            $html .= '<ul class="ckg-compat-list">';
            foreach ( $data['chasis'] as $c ) {
                $html .= '<li>' . esc_html( $c ) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ( ! empty( $data['categorias'] ) ) {
            $html .= '<div class="ckg-compat-block">';
            $html .= '<h3 class="ckg-h3">Categorías de competición</h3>';
            $html .= '<div class="ckg-cat-tags">';
            foreach ( $data['categorias'] as $cat ) {
                $html .= '<span class="ckg-cat-tag">' . esc_html( $cat ) . '</span>';
            }
            $html .= '</div></div>';
        }

        if ( ! empty( $data['nota'] ) ) {
            $html .= '<p class="ckg-compat-note">' . wp_kses_post( $data['nota'] ) . '</p>';
        }

        $html .= '</div>';

        if ( $image ) {
            $html .= '<figure><img src="' . esc_url( $image ) . '" alt="" loading="lazy"></figure>';
        }

        return $html;
    }

    // ── SPECS ──────────────────────────────────────────────────────────────
    private static function section_specs( array $specs, string $image = '' ): string {
        $html  = '<h2 class="ckg-h2">Especificaciones técnicas</h2>';
        $html .= '<div class="ckg-specs-wrap">';
        $html .= '<table class="ckg-specs-table"><thead><tr><th>Característica</th><th>Detalle</th></tr></thead><tbody>';

        foreach ( $specs as $row ) {
            $html .= '<tr><td>' . esc_html( $row['key'] ) . '</td><td>' . esc_html( $row['value'] ) . '</td></tr>';
        }

        $html .= '</tbody></table>';

        if ( $image ) {
            $html .= '<figure><img src="' . esc_url( $image ) . '" alt="" loading="lazy"></figure>';
        }

        $html .= '</div>';
        return $html;
    }

    // ── CATEGORÍAS DE USO ──────────────────────────────────────────────────
    // [ ['titulo'=>'KZ / KZ2', 'texto'=>'Ideal para pilotos de cambio...'], ... ]
    private static function section_categorias_uso( array $cats ): string {
        $html = '<h2 class="ckg-h2">¿Para qué categoría es?</h2>';
        foreach ( $cats as $cat ) {
            $html .= '<h3 class="ckg-h3">' . esc_html( $cat['titulo'] ) . '</h3>';
            $html .= '<p>' . wp_kses_post( $cat['texto'] ) . '</p>';
        }
        return $html;
    }

    // ── FAQ ────────────────────────────────────────────────────────────────
    private static function section_faq( array $faq, string $image = '' ): string {
        $html  = '<h2 class="ckg-h2">Preguntas frecuentes</h2>';
        $html .= '<div class="ckg-faq">';

        foreach ( $faq as $item ) {
            $html .= '<div class="ckg-faq-item">';
            // Usamos data-expanded en lugar de aria-expanded para evitar
            // que WooCommerce/temas interfieran con el atributo
            $html .= '<button class="ckg-faq-q" data-expanded="false" type="button">';
            $html .= '<span class="ckg-faq-icon">?</span> ';
            $html .= '<span class="ckg-faq-text">' . esc_html( $item['pregunta'] ) . '</span>';
            $html .= '<span class="ckg-faq-toggle" aria-hidden="true"></span>';
            $html .= '</button>';
            // style="display:none" en lugar de hidden — wp_kses_post elimina 'hidden'
            $html .= '<div class="ckg-faq-a" style="display:none"><div class="ckg-faq-a-inner">' . wp_kses_post( $item['respuesta'] ) . '</div></div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        if ( $image ) {
            $html .= '<figure><img src="' . esc_url( $image ) . '" alt="" loading="lazy"></figure>';
        }

        return $html;
    }

    // ── VIDEO EMBEBIDO ────────────────────────────────────────────────────
    private static function section_video( string $url, string $title = '', string $description = '' ): string {
        $embed = self::get_video_embed( $url );
        if ( ! $embed ) return '';

        $html  = '<div class="ckg-video-wrap">';
        if ( $title ) {
            $html .= '<h2 class="ckg-h2">' . esc_html( $title ) . '</h2>';
        }
        if ( $description ) {
            $html .= '<p class="ckg-video-desc">' . esc_html( $description ) . '</p>';
        }
        $html .= '<div class="ckg-video-responsive">' . $embed . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function get_video_embed( string $url ): string {
        $url = trim( $url );
        if ( empty( $url ) ) return '';

        // YouTube: youtube.com/watch?v=ID o youtu.be/ID o youtube.com/embed/ID
        if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_\-]{11})#', $url, $m ) ) {
            $id = $m[1];
            return '<iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '?rel=0" title="Video del producto" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
        }

        // Vimeo: vimeo.com/ID
        if ( preg_match( '#vimeo\.com/(\d+)#', $url, $m ) ) {
            return '<iframe src="https://player.vimeo.com/video/' . esc_attr( $m[1] ) . '?dnt=1" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
        }

        // URL de video directo (.mp4, .webm, .ogg)
        if ( preg_match( '#\.(mp4|webm|ogg)(\?.*)?$#i', $url ) ) {
            return '<video controls preload="metadata" style="width:100%;max-width:100%"><source src="' . esc_url( $url ) . '"><p>Tu navegador no soporta video HTML5.</p></video>';
        }

        // Fallback: intentar con wp_oembed_get
        $oembed = wp_oembed_get( $url );
        if ( $oembed ) return $oembed;

        // Si nada funciona, enlace
        return '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">▶ Ver video</a></p>';
    }

    // ── CONCLUSIÓN ─────────────────────────────────────────────────────────
    private static function section_conclusion( string $text ): string {
        return '<h2 class="ckg-h2">Conclusión</h2><div class="ckg-conclusion">' . wp_kses_post( $text ) . '</div>';
    }

    // ── BOTÓN CATÁLOGO / GUÍA ──────────────────────────────────────────────
    private static function catalog_button( string $url, string $label = '' ): string {
        $label = $label ?: '📖 Ver ficha técnica completa';
        return '<div class="ckg-catalog-btn-wrap"><a href="' . esc_url( $url ) . '" class="ckg-catalog-btn" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></div>';
    }

    // ── SCHEMA FAQ JSON-LD ─────────────────────────────────────────────────
    private static function faq_schema( array $faq ): string {
        $items = [];
        foreach ( $faq as $item ) {
            $items[] = [
                '@type'          => 'Question',
                'name'           => $item['pregunta'],
                'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $item['respuesta'] ) ],
            ];
        }
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $items,
        ];
        return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . '</script>';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  SHORT DESCRIPTION — badges CMSKart
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Convierte markdown basico a HTML y limpia el texto para WordPress.
     * Ollama a veces devuelve markdown en vez de HTML.
     */
    private static function sanitize_llm_output( string $text ): string {
        if ( empty( $text ) ) return '';

        // [texto](url) → <a href="url">texto</a>
        $text = preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/',
            '<a href="$2">$1</a>',
            $text
        );

        // **texto** → <strong>texto</strong>
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

        // *texto* → <em>texto</em>  (solo si no es bullet list)
        $text = preg_replace( '/(?<!\*)\*(?!\*)([^*
]+)\*(?!\*)/', '<em>$1</em>', $text );

        // Saltos de linea dobles → parrafos
        $text = preg_replace( '/
{2,}/', '</p><p>', $text );

        return trim( $text );
    }

    public static function build_short_description( array $data ): string {
        $html = '';

        if ( ! empty( $data['short_desc'] ) ) {
            $html .= '<p>' . wp_kses_post( $data['short_desc'] ) . '</p>';
        }

        // Badges informativos de CMSKart
        $settings = get_option( 'ckg_settings', [] );
        $badges   = [];

        if ( ! empty( $data['badge_envio'] ) )    $badges[] = [ 'icon' => '🚚', 'text' => $settings['envio_texto']    ?? 'Envío 24-72h' ];
        if ( ! empty( $data['badge_garantia'] ) )  $badges[] = [ 'icon' => '🔧', 'text' => $settings['garantia_texto'] ?? 'Garantía fabricante' ];
        if ( ! empty( $data['badge_recogida'] ) )  $badges[] = [ 'icon' => '📍', 'text' => $settings['recogida_texto'] ?? 'Recogida en karting' ];
        if ( ! empty( $data['badge_inter'] ) )     $badges[] = [ 'icon' => '🌍', 'text' => 'Envío Internacional' ];
        if ( ! empty( $data['badge_homol'] ) && ! empty( $data['homo_badge_text'] ) ) {
            $badges[] = [ 'icon' => '✅', 'text' => $data['homo_badge_text'] ];
        }

        if ( $badges ) {
            $html .= '<ul class="ckg-badges">';
            foreach ( $badges as $b ) {
                $html .= '<li class="ckg-badge"><span class="ckg-badge-icon">' . $b['icon'] . '</span> ' . esc_html( $b['text'] ) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }
}
