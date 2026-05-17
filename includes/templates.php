<?php
/**
 * CKG_Templates
 * Plantillas predefinidas para los tipos de producto más comunes en CMSKart.es
 *
 * Cada plantilla pre-rellena:
 *  - attribute_name (atributo de variación)
 *  - homologaciones (badges)
 *  - caracteristicas (H3 + texto base)
 *  - specs (tabla con parámetros típicos del producto)
 *  - categorias_uso (para qué categoría)
 *  - faq (preguntas frecuentes base)
 *  - badges recomendados
 *
 * Presets de homologaciones por marca (se aplican a la plantilla activa)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_Templates {

    /* ── Lista de plantillas disponibles ─────────────────────────────── */
    public static function list(): array {
        return [
            [ 'id' => 'casco',        'name' => 'Casco de karting',         'icon' => '🪖' ],
            [ 'id' => 'proteccion',   'name' => 'Protección / Rib',         'icon' => '🦺' ],
            [ 'id' => 'guantes',      'name' => 'Guantes de karting',       'icon' => '🧤' ],
            [ 'id' => 'botas',        'name' => 'Botas de karting',         'icon' => '👢' ],
            [ 'id' => 'mono',         'name' => 'Mono / Overol',            'icon' => '🏎️' ],
            [ 'id' => 'chasis',       'name' => 'Chasis de competición',    'icon' => '⚙️' ],
            [ 'id' => 'motor',        'name' => 'Motor de karting',         'icon' => '🔧' ],
            [ 'id' => 'neumatico',    'name' => 'Neumático',                'icon' => '⭕' ],
            [ 'id' => 'recambio',     'name' => 'Recambio / Pieza',         'icon' => '🔩' ],
            [ 'id' => 'telemetria',   'name' => 'Telemetría / AIM MyChron', 'icon' => '📊' ],
            [ 'id' => 'accesorio',    'name' => 'Accesorio general',        'icon' => '📦' ],
        ];
    }

    /* ── Presets de homologaciones por marca ─────────────────────────── */
    public static function homologaciones_by_brand(): array {
        return [
            // Cascos
            'Bell' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación 2023', 'descripcion' => 'Para categorías OK, KZ, Junior, Senior' ],
                [ 'tipo' => 'Snell',    'codigo' => 'SA2025',            'descripcion' => 'Karting y automovilismo' ],
            ],
            'Arai' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación 2023', 'descripcion' => '' ],
                [ 'tipo' => 'Snell',    'codigo' => 'SA2025',            'descripcion' => '' ],
                [ 'tipo' => 'FIA',      'codigo' => '8860-2018',         'descripcion' => 'Automovilismo de alto nivel' ],
            ],
            'Zamp' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación 2023', 'descripcion' => '' ],
                [ 'tipo' => 'Snell',    'codigo' => 'SA2025',            'descripcion' => '' ],
                [ 'tipo' => 'CMR',      'codigo' => 'CMR 2016',          'descripcion' => 'Para pilotos junior hasta 15 años' ],
            ],
            'OMP' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación 2023', 'descripcion' => '' ],
                [ 'tipo' => 'Snell',    'codigo' => 'SA2020',            'descripcion' => '' ],
            ],
            // Equipamiento piloto
            'Alpinestars' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Nivel 2',           'descripcion' => 'Monos y equipamiento' ],
                [ 'tipo' => 'FIA',      'codigo' => '8877-2022',         'descripcion' => 'Guantes y botas karting' ],
            ],
            'Sparco' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Nivel 2',           'descripcion' => '' ],
                [ 'tipo' => 'FIA',      'codigo' => '8877-2022',         'descripcion' => 'Guantes y botas' ],
            ],
            'Sabelt' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Nivel 2',           'descripcion' => '' ],
                [ 'tipo' => 'FIA',      'codigo' => '8877-2022',         'descripcion' => '' ],
            ],
            // Chasis
            'OTK' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación vigente', 'descripcion' => 'Tony Kart, Exprit, FA Kart, Kosmic' ],
            ],
            'Intrepid' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación vigente', 'descripcion' => 'KZ, KZ2, KZN, KZ Master' ],
            ],
            'Lenzokart' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación vigente', 'descripcion' => '' ],
            ],
            'Kart Republic' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologación vigente', 'descripcion' => 'OK, Junior' ],
            ],
            // Motores
            'TM Racing' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Motor homologado',  'descripcion' => 'KZ, KZ2, 60cc Mini' ],
            ],
            'IAME' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Motor homologado',  'descripcion' => 'X30, Shifter, Micro Swift, Mini Swift' ],
            ],
            'Rotax' => [
                [ 'tipo' => 'Rotax',    'codigo' => 'Rotax Max',         'descripcion' => 'Sistema Rotax Max Challenge' ],
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologado',        'descripcion' => '' ],
            ],
            // Protecciones
            'Bengio' => [
                [ 'tipo' => 'CIK-FIA',  'codigo' => 'Homologado',        'descripcion' => 'Protector de costillas' ],
                [ 'tipo' => 'FIA',      'codigo' => '8870-2018',         'descripcion' => 'Estándar premium FIA' ],
            ],
        ];
    }

    /* ── Datos de cada plantilla ─────────────────────────────────────── */
    public static function get( string $id ): array {
        $templates = [

            /* ── CASCO ──────────────────────────────────────────────── */
            'casco' => [
                'attribute_name'  => 'Talla',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_inter'     => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA 2023 · Snell SA2025',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Homologación 2023', 'descripcion' => 'OK, KZ, Junior, Senior' ],
                    [ 'tipo' => 'Snell',   'codigo' => 'SA2025',            'descripcion' => 'Karting y automovilismo' ],
                ],
                'caracteristicas' => [
                    [ 'titulo' => 'Carcasa y construcción', 'texto' => 'Describe aquí el material de la carcasa (carbono, fibra, policarbonato) y cómo influye en el peso y la protección.' ],
                    [ 'titulo' => 'Sistema de ventilación', 'texto' => 'Explica las entradas y salidas de aire del casco, fundamental para el confort en pista.' ],
                    [ 'titulo' => 'Interior y comodidad',   'texto' => 'Detalla los materiales del interior, si es extraíble y lavable, y el sistema de ajuste.' ],
                    [ 'titulo' => 'Visera y campo visual',  'texto' => 'Describe el ángulo de visión, sistema de enganche de la visera y accesorios disponibles (tearoffs, Pinlock…).' ],
                ],
                'specs' => [
                    [ 'key' => 'Peso',              'value' => '' ],
                    [ 'key' => 'Material carcasa',  'value' => '' ],
                    [ 'key' => 'Homologación',      'value' => 'CIK-FIA 2023 · Snell SA2025' ],
                    [ 'key' => 'Tallas disponibles','value' => 'XS / S / M / L / XL / XXL' ],
                    [ 'key' => 'Colores',           'value' => '' ],
                    [ 'key' => 'Sistema de cierre', 'value' => '' ],
                    [ 'key' => 'Interior',          'value' => 'Extraíble y lavable' ],
                ],
                'categorias_uso' => [
                    [ 'titulo' => 'Categorías OK y OK Junior',     'texto' => '' ],
                    [ 'titulo' => 'Categorías KZ y KZ2',           'texto' => '' ],
                    [ 'titulo' => 'Karting recreativo y escuelas', 'texto' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Cómo sé qué talla de casco necesito?',
                      'respuesta' => 'Para elegir la talla correcta, mide el perímetro de tu cabeza con una cinta métrica a la altura de las cejas. Consulta la guía de tallas del fabricante y, en caso de duda entre dos tallas, elige la mayor. El casco debe quedar firme pero sin presionar dolorosamente.' ],
                    [ 'pregunta' => '¿La visera viene incluida?',
                      'respuesta' => 'Sí, el casco incluye visera clara de serie. Puedes adquirir de forma opcional viseras ahumadas, espejadas o de colores. Consulta los accesorios disponibles en la ficha del producto.' ],
                    [ 'pregunta' => '¿Cuándo caduca la homologación CIK-FIA?',
                      'respuesta' => 'La homologación CIK-FIA tiene una vigencia determinada (normalmente 5 años). Pasado ese período, el casco puede seguir usándose para entrenamientos pero no será válido para competiciones oficiales. Comprueba siempre la fecha de homologación vigente con tu federación.' ],
                    [ 'pregunta' => '¿Puedo usar este casco en automovilismo además de karting?',
                      'respuesta' => 'Depende de las homologaciones que lleve el casco. Un casco con homologación Snell SA puede usarse también en circuito. Para competiciones de automovilismo, consulta el reglamento de tu federación ya que puede exigir homologaciones específicas (FIA 8860, 8858-2010, etc.).' ],
                    [ 'pregunta' => '¿Cómo se limpia el interior del casco?',
                      'respuesta' => 'La mayoría de los cascos modernos incluyen interior extraíble y lavable a máquina (30°C, centrifugado suave). La carcasa exterior se limpia con un paño húmedo y jabón neutro. Evita disolventes o productos abrasivos que puedan dañar las capas protectoras.' ],
                ],
            ],

            /* ── PROTECCIÓN / RIB ───────────────────────────────────── */
            'proteccion' => [
                'attribute_name'  => 'Talla',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA homologado',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Homologado',    'descripcion' => 'Protector de costillas/torso' ],
                    [ 'tipo' => 'FIA',     'codigo' => '8870-2018',     'descripcion' => 'Estándar premium' ],
                ],
                'caracteristicas' => [
                    [ 'titulo' => 'Materiales y absorción de impactos', 'texto' => '' ],
                    [ 'titulo' => 'Ajuste y ergonomía',                 'texto' => '' ],
                    [ 'titulo' => 'Compatibilidad con el asiento',      'texto' => '' ],
                ],
                'specs' => [
                    [ 'key' => 'Material exterior',    'value' => '' ],
                    [ 'key' => 'Espuma absorbente',    'value' => '' ],
                    [ 'key' => 'Sistema de ajuste',    'value' => '' ],
                    [ 'key' => 'Homologación',         'value' => 'CIK-FIA · FIA 8870-2018' ],
                    [ 'key' => 'Tallas',               'value' => 'S / M / L / XL' ],
                    [ 'key' => 'Peso',                 'value' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Es obligatorio el protector de costillas en karting?', 'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo elijo la talla correcta?',                        'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo se coloca dentro del asiento del kart?',          'respuesta' => '' ],
                ],
            ],

            /* ── GUANTES ─────────────────────────────────────────────── */
            'guantes' => [
                'attribute_name'  => 'Talla',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'FIA 8877-2022',
                'homologacion'    => [
                    [ 'tipo' => 'FIA', 'codigo' => '8877-2022', 'descripcion' => 'Guantes de karting' ],
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Nivel 2', 'descripcion' => '' ],
                ],
                'caracteristicas' => [
                    [ 'titulo' => 'Material y construcción', 'texto' => '' ],
                    [ 'titulo' => 'Agarre y tacto del volante', 'texto' => '' ],
                    [ 'titulo' => 'Ventilación y comodidad', 'texto' => '' ],
                ],
                'specs' => [
                    [ 'key' => 'Material exterior',  'value' => '' ],
                    [ 'key' => 'Palma',              'value' => '' ],
                    [ 'key' => 'Homologación',       'value' => 'FIA 8877-2022' ],
                    [ 'key' => 'Tallas',             'value' => 'XXS / XS / S / M / L / XL / XXL' ],
                    [ 'key' => 'Cierre',             'value' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Son obligatorios guantes homologados en karting?', 'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo elijo la talla de guantes?',                  'respuesta' => '' ],
                ],
            ],

            /* ── BOTAS ───────────────────────────────────────────────── */
            'botas' => [
                'attribute_name'  => 'Talla (EU)',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'FIA 8877-2022',
                'homologacion'    => [
                    [ 'tipo' => 'FIA', 'codigo' => '8877-2022', 'descripcion' => 'Botas karting' ],
                ],
                'specs' => [
                    [ 'key' => 'Material exterior', 'value' => '' ],
                    [ 'key' => 'Suela',             'value' => '' ],
                    [ 'key' => 'Cierre',            'value' => '' ],
                    [ 'key' => 'Homologación',      'value' => 'FIA 8877-2022' ],
                    [ 'key' => 'Tallas (EU)',       'value' => '36 / 37 / 38 / 39 / 40 / 41 / 42 / 43 / 44 / 45 / 46' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Cómo elijo la talla de botas?',           'respuesta' => '' ],
                    [ 'pregunta' => '¿Son válidas para lluvia estas botas?',     'respuesta' => '' ],
                ],
            ],

            /* ── MONO / OVEROL ───────────────────────────────────────── */
            'mono' => [
                'attribute_name'  => 'Talla',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA Nivel 2',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Nivel 2', 'descripcion' => 'Mono de karting competición' ],
                ],
                'specs' => [
                    [ 'key' => 'Material exterior',   'value' => '' ],
                    [ 'key' => 'Homologación',        'value' => 'CIK-FIA Nivel 2' ],
                    [ 'key' => 'Tallas',              'value' => 'XS / S / M / L / XL / XXL / XXXL' ],
                    [ 'key' => 'Colores disponibles', 'value' => '' ],
                    [ 'key' => 'Bolsillos',           'value' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Es obligatorio el mono homologado en karting?', 'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo se lava el mono de karting?',              'respuesta' => '' ],
                ],
            ],

            /* ── CHASIS ──────────────────────────────────────────────── */
            'chasis' => [
                'attribute_name'  => 'Versión',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA homologado',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Homologación vigente', 'descripcion' => '' ],
                ],
                'caracteristicas' => [
                    [ 'titulo' => 'Geometría y tubería',       'texto' => '' ],
                    [ 'titulo' => 'Rigidez y comportamiento',  'texto' => '' ],
                    [ 'titulo' => 'Sistema de frenos',         'texto' => '' ],
                    [ 'titulo' => 'Dirección y ajustes',       'texto' => '' ],
                ],
                'specs' => [
                    [ 'key' => 'Longitud',              'value' => '' ],
                    [ 'key' => 'Anchura',               'value' => '' ],
                    [ 'key' => 'Diámetro tubo',         'value' => '' ],
                    [ 'key' => 'Material',              'value' => 'Acero CrMo' ],
                    [ 'key' => 'Homologación',          'value' => 'CIK-FIA' ],
                    [ 'key' => 'Peso (sin motor)',      'value' => '' ],
                    [ 'key' => 'Sistema frenos',        'value' => 'CIK-FIA' ],
                    [ 'key' => 'Categorías',            'value' => '' ],
                ],
                'categorias_uso' => [
                    [ 'titulo' => 'KZ / KZ2 — Cambio de marchas',          'texto' => '' ],
                    [ 'titulo' => 'OK / OK Junior — Motor directo',         'texto' => '' ],
                    [ 'titulo' => 'Mini 60 — Categorías de iniciación',     'texto' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿El chasis viene completo con motor y accesorios?',
                      'respuesta' => 'El chasis se suministra en configuración rodante (sin motor). Incluye la estructura, eje trasero, sistema de frenos, dirección y ruedas. Para una configuración completa consulta con nuestro equipo técnico.' ],
                    [ 'pregunta' => '¿Con qué motores es compatible este chasis?',
                      'respuesta' => 'Este chasis es compatible con los principales motores de karting del mercado. Para una compatibilidad exacta con tu motor, contacta con nuestro equipo técnico indicando la referencia del motor.' ],
                    [ 'pregunta' => '¿Está homologado para competición oficial?',
                      'respuesta' => 'Sí, cuenta con homologación CIK-FIA vigente, lo que permite su uso en competiciones nacionales e internacionales. Consulta las bases del campeonato en el que participes para verificar las categorías permitidas.' ],
                    [ 'pregunta' => '¿Hay recambios disponibles en CMSKart?',
                      'respuesta' => 'Sí, disponemos de recambios originales para este chasis. Puedes encontrarlos en nuestra sección de recambios filtrando por marca y modelo, o contactar con nuestro equipo técnico para localizar cualquier pieza.' ],
                    [ 'pregunta' => '¿Cuánto tiempo tarda el envío?',
                      'respuesta' => 'Los chasis se envían en un plazo de 24-72h hábiles en península. Para Canarias, Baleares o envío internacional, consulta plazos y costes con nuestro equipo.' ],
                ],
            ],

            /* ── MOTOR ───────────────────────────────────────────────── */
            'motor' => [
                'attribute_name'  => 'Versión',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA homologado',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Motor homologado', 'descripcion' => '' ],
                ],
                'specs' => [
                    [ 'key' => 'Cilindrada',          'value' => '' ],
                    [ 'key' => 'Potencia máxima',     'value' => '' ],
                    [ 'key' => 'Tipo',                'value' => '' ],
                    [ 'key' => 'Carburador',          'value' => '' ],
                    [ 'key' => 'Escape',              'value' => '' ],
                    [ 'key' => 'Arranque',            'value' => '' ],
                    [ 'key' => 'Peso',                'value' => '' ],
                    [ 'key' => 'Homologación',        'value' => 'CIK-FIA' ],
                    [ 'key' => 'Categorías',          'value' => '' ],
                ],
                'categorias_uso' => [
                    [ 'titulo' => 'KZ2 — Cambio de marchas 125cc', 'texto' => '' ],
                    [ 'titulo' => 'OK — Motor directo 125cc',       'texto' => '' ],
                    [ 'titulo' => 'Mini / Micro — 60cc',            'texto' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿El motor viene preparado para pista?',
                      'respuesta' => 'El motor se suministra en configuración estándar de serie, listo para montar. Se recomienda realizar el período de rodaje antes de exigirlo al máximo.' ],
                    [ 'pregunta' => '¿Qué aceite de mezcla se recomienda?',
                      'respuesta' => 'Se recomienda aceite de mezcla de alta calidad específico para motores de karting, con una proporción de mezcla según las indicaciones del fabricante (normalmente entre 1:20 y 1:40). Consulta el manual del motor para la proporción exacta.' ],
                    [ 'pregunta' => '¿Con qué chasis es compatible?',
                      'respuesta' => 'Compatible con la mayoría de chasis del mercado que utilicen sujeción estándar CIK. Para confirmar la compatibilidad exacta con tu chasis contacta con nuestro equipo técnico.' ],
                    [ 'pregunta' => '¿Cuál es el período de rodaje del motor?',
                      'respuesta' => 'Se recomienda un mínimo de 2-3 sesiones de rodaje progresivo, sin superar el 70% de las revoluciones máximas durante las primeras horas. Esto es fundamental para prolongar la vida útil del motor.' ],
                    [ 'pregunta' => '¿Incluye garantía?',
                      'respuesta' => 'Sí, incluye la garantía oficial del fabricante. El período de garantía puede variar según el fabricante. Para reclamaciones de garantía contacta con CMSKart aportando el justificante de compra.' ],
                ],
            ],

            /* ── TELEMETRÍA AIM ──────────────────────────────────────── */
            'telemetria' => [
                'attribute_name'  => 'Versión',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_inter'     => true,
                'specs' => [
                    [ 'key' => 'Pantalla',           'value' => '' ],
                    [ 'key' => 'GPS',                'value' => '' ],
                    [ 'key' => 'Canales internos',   'value' => '' ],
                    [ 'key' => 'Temperatura motor',  'value' => '' ],
                    [ 'key' => 'Velocidad máx.',     'value' => '' ],
                    [ 'key' => 'Autonomía batería',  'value' => '' ],
                    [ 'key' => 'Memoria interna',    'value' => '' ],
                    [ 'key' => 'Software análisis',  'value' => 'AiM Race Studio 3' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Es compatible con todos los chasis?',                 'respuesta' => '' ],
                    [ 'pregunta' => '¿Qué sensores adicionales admite?',                    'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo se descarga la telemetría al PC?',              'respuesta' => '' ],
                    [ 'pregunta' => '¿El software de análisis tiene coste adicional?',     'respuesta' => '' ],
                ],
            ],

            /* ── RECAMBIO / PIEZA ────────────────────────────────────── */
            'recambio' => [
                'attribute_name'  => 'Referencia',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_inter'     => true,
                'specs' => [
                    [ 'key' => 'Material',       'value' => '' ],
                    [ 'key' => 'Medidas',        'value' => '' ],
                    [ 'key' => 'Peso',           'value' => '' ],
                    [ 'key' => 'Compatibilidad', 'value' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Con qué chasis/motores es compatible esta pieza?', 'respuesta' => '' ],
                    [ 'pregunta' => '¿Cómo se instala?',                                 'respuesta' => '' ],
                ],
            ],

            /* ── ACCESORIO GENERAL ───────────────────────────────────── */
            'accesorio' => [
                'attribute_name'  => 'Modelo',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'specs' => [
                    [ 'key' => 'Material',    'value' => '' ],
                    [ 'key' => 'Medidas',     'value' => '' ],
                    [ 'key' => 'Peso',        'value' => '' ],
                    [ 'key' => 'Color',       'value' => '' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿Con qué karts / categorías es compatible?', 'respuesta' => '' ],
                ],
            ],

            /* ── NEUMÁTICO ───────────────────────────────────────────── */
            'neumatico' => [
                'attribute_name'  => 'Tipo (seco/lluvia)',
                'badge_envio'     => true,
                'badge_garantia'  => true,
                'badge_homol'     => true,
                'homo_badge_text' => 'CIK-FIA homologado',
                'homologacion'    => [
                    [ 'tipo' => 'CIK-FIA', 'codigo' => 'Homologado', 'descripcion' => '' ],
                ],
                'specs' => [
                    [ 'key' => 'Medida delantera',  'value' => '' ],
                    [ 'key' => 'Medida trasera',    'value' => '' ],
                    [ 'key' => 'Compuesto',         'value' => '' ],
                    [ 'key' => 'Tipo de llanta',    'value' => '' ],
                    [ 'key' => 'Categorías',        'value' => '' ],
                    [ 'key' => 'Homologación',      'value' => 'CIK-FIA' ],
                ],
                'faq' => [
                    [ 'pregunta' => '¿En qué categorías están homologados estos neumáticos?', 'respuesta' => '' ],
                    [ 'pregunta' => '¿Cuántas sesiones de pista aguantan?',                   'respuesta' => '' ],
                    [ 'pregunta' => '¿Con qué presión de inflado se utilizan?',               'respuesta' => '' ],
                ],
            ],
        ];

        return $templates[ $id ] ?? $templates['accesorio'];
    }
}
