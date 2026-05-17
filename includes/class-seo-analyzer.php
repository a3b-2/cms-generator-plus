<?php
/**
 * CKG_SEO_Analyzer
 * Análisis SEO en tiempo real de los datos del formulario.
 * Devuelve puntuación 0-100 y checklist de factores con estado.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_SEO_Analyzer {

    /**
     * Analiza todos los datos del formulario y devuelve score + checks.
     */
    public static function analyze( array $data ): array {
        $checks = [];
        $score  = 0;

        $name    = $data['product_name']    ?? '';
        $slug    = $data['product_slug']    ?? sanitize_title( $name );
        $short   = wp_strip_all_tags( $data['short_desc']    ?? '' );
        $intro   = wp_strip_all_tags( $data['intro']         ?? '' );
        $keyword = $data['seo_keywords']    ?? '';
        $title   = $data['seo_title']       ?? '';
        $desc    = $data['seo_description'] ?? '';
        $sec_kws = $data['seo_secondary_keywords'] ?? '';

        // Contenido total (intro + características + specs + FAQ + conclusión)
        $full_content = $intro;
        foreach ( (array) ( $data['caracteristicas'] ?? [] ) as $c ) $full_content .= ' ' . ($c['texto'] ?? '');
        foreach ( (array) ( $data['faq']             ?? [] ) as $f ) $full_content .= ' ' . ($f['respuesta'] ?? '');
        $full_content .= wp_strip_all_tags( $data['conclusion'] ?? '' );
        $full_content  = wp_strip_all_tags( $full_content );

        $word_count = str_word_count( $full_content );
        $img_count  = count( array_filter( $data['images'] ?? [] ) );
        $spec_count = count( $data['specs'] ?? [] );
        $faq_count  = count( $data['faq']   ?? [] );
        $homo_count = count( $data['homologacion'] ?? [] );

        /* ── CHECKS ─────────────────────────────────────────────────── */

        // 1. Nombre del producto (10pts)
        if ( strlen( $name ) >= 20 ) {
            self::ok( $checks, 'product_name', 'Nombre descriptivo (' . strlen($name) . ' chars)', 10 );
            $score += 10;
        } elseif ( strlen( $name ) >= 5 ) {
            self::warn( $checks, 'product_name', 'Nombre corto — considera añadir marca y homologación', 5 );
            $score += 5;
        } else {
            self::fail( $checks, 'product_name', 'Nombre del producto vacío o muy corto' );
        }

        // 2. Keyword principal (8pts)
        if ( $keyword ) {
            $score += 8;
            self::ok( $checks, 'keyword', 'Focus keyword definido: "' . $keyword . '"', 8 );
        } else {
            self::fail( $checks, 'keyword', 'Sin focus keyword — imprescindible para RankMath' );
        }

        // 3. Keyword en el nombre del producto (5pts)
        if ( $keyword && stripos( $name, $keyword ) !== false ) {
            $score += 5;
            self::ok( $checks, 'kw_in_name', 'Keyword en el nombre del producto', 5 );
        } elseif ( $keyword ) {
            self::warn( $checks, 'kw_in_name', 'Keyword no está en el nombre — considera incluirla', 0 );
        }

        // 4. SEO Title (8pts)
        $tlen = strlen( $title );
        if ( $tlen >= 50 && $tlen <= 60 ) {
            $score += 8;
            self::ok( $checks, 'seo_title', "SEO Title óptimo ($tlen chars)", 8 );
        } elseif ( $tlen > 0 && $tlen < 50 ) {
            $score += 4;
            self::warn( $checks, 'seo_title', "SEO Title corto ($tlen/50-60 chars recomendados)", 4 );
        } elseif ( $tlen > 60 ) {
            $score += 4;
            self::warn( $checks, 'seo_title', "SEO Title demasiado largo ($tlen/60 chars máx)", 4 );
        } else {
            self::fail( $checks, 'seo_title', 'SEO Title vacío — Google usará el nombre del producto' );
        }

        // 5. Meta description (8pts)
        $dlen = strlen( $desc );
        if ( $dlen >= 140 && $dlen <= 155 ) {
            $score += 8;
            self::ok( $checks, 'meta_desc', "Meta description óptima ($dlen chars)", 8 );
        } elseif ( $dlen > 0 ) {
            $score += 4;
            self::warn( $checks, 'meta_desc', "Meta description fuera de rango óptimo ($dlen/140-155 chars)", 4 );
        } else {
            self::fail( $checks, 'meta_desc', 'Meta description vacía — crítico para CTR en Google' );
        }

        // 6. Contenido (longitud) (10pts)
        if ( $word_count >= 600 ) {
            $score += 10;
            self::ok( $checks, 'content_length', "Contenido abundante: $word_count palabras", 10 );
        } elseif ( $word_count >= 300 ) {
            $score += 6;
            self::warn( $checks, 'content_length', "Contenido moderado: $word_count palabras (recomendado: 600+)", 6 );
        } elseif ( $word_count > 0 ) {
            $score += 2;
            self::warn( $checks, 'content_length', "Contenido escaso: $word_count palabras (mínimo: 300)", 2 );
        } else {
            self::fail( $checks, 'content_length', 'Sin contenido en descripción — añade introducción, características y FAQ' );
        }

        // 7. Keyword en el contenido (5pts)
        if ( $keyword && $full_content ) {
            $density = self::keyword_density( $keyword, $full_content );
            if ( $density >= 0.5 && $density <= 2.5 ) {
                $score += 5;
                self::ok( $checks, 'kw_density', "Densidad de keyword: {$density}% (óptimo 0.5-2.5%)", 5 );
            } elseif ( $density > 2.5 ) {
                $score += 2;
                self::warn( $checks, 'kw_density', "Keyword sobre-optimizada: {$density}% (máx recomendado: 2.5%)", 2 );
            } else {
                self::warn( $checks, 'kw_density', "Keyword poco presente: {$density}% — úsala más en el contenido", 0 );
            }
        }

        // 8. Imágenes (6pts)
        if ( $img_count >= 4 ) {
            $score += 6;
            self::ok( $checks, 'images', "$img_count imágenes del producto", 6 );
        } elseif ( $img_count >= 2 ) {
            $score += 4;
            self::warn( $checks, 'images', "$img_count imágenes (recomendado: 4+)", 4 );
        } elseif ( $img_count === 1 ) {
            $score += 2;
            self::warn( $checks, 'images', 'Solo 1 imagen — añade más para mejor conversión', 2 );
        } else {
            self::fail( $checks, 'images', 'Sin imágenes — crítico para producto WooCommerce' );
        }

        // 9. FAQ schema (8pts)
        if ( $faq_count >= 5 ) {
            $score += 8;
            self::ok( $checks, 'faq', "$faq_count preguntas FAQ — schema FAQ JSON-LD generado", 8 );
        } elseif ( $faq_count >= 3 ) {
            $score += 5;
            self::warn( $checks, 'faq', "$faq_count preguntas FAQ (recomendado: 5+)", 5 );
        } elseif ( $faq_count > 0 ) {
            $score += 2;
            self::warn( $checks, 'faq', "$faq_count pregunta FAQ — añade más para featured snippets", 2 );
        } else {
            self::fail( $checks, 'faq', 'Sin FAQ — pierdes oportunidades de featured snippets en Google' );
        }

        // 10. Especificaciones técnicas (5pts)
        if ( $spec_count >= 6 ) {
            $score += 5;
            self::ok( $checks, 'specs', "$spec_count especificaciones técnicas", 5 );
        } elseif ( $spec_count >= 3 ) {
            $score += 3;
            self::warn( $checks, 'specs', "$spec_count especificaciones (recomendado: 6+)", 3 );
        } else {
            self::fail( $checks, 'specs', 'Sin especificaciones técnicas — clave para conversión en karting' );
        }

        // 11. Homologaciones (4pts — diferenciador clave en karting)
        if ( $homo_count >= 2 ) {
            $score += 4;
            self::ok( $checks, 'homos', "$homo_count homologaciones configuradas", 4 );
        } elseif ( $homo_count === 1 ) {
            $score += 2;
            self::warn( $checks, 'homos', '1 homologación — ¿hay más que apliquen?', 2 );
        } else {
            self::warn( $checks, 'homos', 'Sin homologaciones — si el producto las tiene, añádelas', 0 );
        }

        // 12. Secondary keywords (3pts)
        if ( $sec_kws ) {
            $kw_count = count( array_filter( explode( ',', $sec_kws ) ) );
            $score += 3;
            self::ok( $checks, 'secondary_kws', "$kw_count keywords secundarias para RankMath", 3 );
        } else {
            self::warn( $checks, 'secondary_kws', 'Sin keywords secundarias — genera LSI con Ollama', 0 );
        }

        // 13. Marca definida (4pts)
        $brand = $data['brand'] ?? '';
        if ( $brand ) {
            $score += 4;
            self::ok( $checks, 'brand', "Marca: $brand", 4 );
        } else {
            self::warn( $checks, 'brand', 'Sin marca — importante para schema Product', 0 );
        }

        // 14. SKU (3pts)
        if ( $data['sku'] ?? '' ) {
            $score += 3;
            self::ok( $checks, 'sku', 'SKU definido: ' . $data['sku'], 3 );
        } else {
            self::warn( $checks, 'sku', 'Sin SKU — añádelo para el schema Product', 0 );
        }

        // 15. Variaciones con precio (4pts)
        $vars = $data['variations'] ?? [];
        $vars_with_price = array_filter( $vars, fn($v) => (float)($v['price'] ?? 0) > 0 );
        if ( count( $vars_with_price ) >= 1 ) {
            $score += 4;
            self::ok( $checks, 'variations', count($vars_with_price) . ' variación(es) con precio', 4 );
        } else {
            self::fail( $checks, 'variations', 'Sin variaciones con precio — WooCommerce no mostrará precio' );
        }

        // ── Score final ───────────────────────────────────────────────
        $score = min( 100, $score );

        return [
            'score'        => $score,
            'grade'        => self::grade( $score ),
            'color'        => self::grade_color( $score ),
            'checks'       => $checks,
            'word_count'   => $word_count,
            'img_count'    => $img_count,
            'faq_count'    => $faq_count,
            'spec_count'   => $spec_count,
            'summary'      => self::summary( $score, $checks ),
        ];
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private static function ok(   array &$checks, string $id, string $msg, int $pts ): void { $checks[] = [ 'id' => $id, 'status' => 'ok',   'msg' => $msg, 'pts' => $pts ]; }
    private static function warn( array &$checks, string $id, string $msg, int $pts ): void { $checks[] = [ 'id' => $id, 'status' => 'warn', 'msg' => $msg, 'pts' => $pts ]; }
    private static function fail( array &$checks, string $id, string $msg ): void           { $checks[] = [ 'id' => $id, 'status' => 'fail', 'msg' => $msg, 'pts' => 0 ]; }

    private static function keyword_density( string $keyword, string $content ): float {
        $words   = str_word_count( strtolower( $content ) );
        if ( $words === 0 ) return 0;
        $kw_words = str_word_count( strtolower( $keyword ) );
        $count   = substr_count( strtolower( $content ), strtolower( $keyword ) );
        return round( ( $count * $kw_words / $words ) * 100, 1 );
    }

    private static function grade( int $score ): string {
        if ( $score >= 85 ) return 'Excelente';
        if ( $score >= 70 ) return 'Bueno';
        if ( $score >= 50 ) return 'Mejorable';
        if ( $score >= 30 ) return 'Deficiente';
        return 'Muy deficiente';
    }

    private static function grade_color( int $score ): string {
        if ( $score >= 85 ) return '#27ae60';
        if ( $score >= 70 ) return '#2ecc71';
        if ( $score >= 50 ) return '#f39c12';
        if ( $score >= 30 ) return '#e67e22';
        return '#c0392b';
    }

    private static function summary( int $score, array $checks ): string {
        $fails = array_filter( $checks, fn( $c ) => $c['status'] === 'fail' );
        $warns = array_filter( $checks, fn( $c ) => $c['status'] === 'warn' );
        $parts = [];
        if ( count( $fails ) ) $parts[] = count( $fails ) . ' problema(s) crítico(s)';
        if ( count( $warns ) ) $parts[] = count( $warns ) . ' mejora(s) sugerida(s)';
        return $parts ? 'Detectados: ' . implode( ', ', $parts ) . '.' : '¡Todo en orden!';
    }
}
