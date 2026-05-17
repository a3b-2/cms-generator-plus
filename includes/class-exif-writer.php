<?php
/**
 * CKG_ExifWriter
 * Inyecta coordenadas GPS y metadatos de localización en imágenes.
 *
 * Formatos soportados:
 *   WebP → bloque XMP (RIFF chunk 'XMP ')
 *   JPEG → segmento APP1 EXIF con GPS IFD
 *   PNG  → chunk tEXt/iTXt con XMP
 *
 * Sin dependencias externas — pure PHP, sin Imagick, sin ExifTool.
 * Funciona con GD (el único requisito del plugin).
 *
 * Uso típico:
 *   // En el importador de imágenes del producto:
 *   $binary = CKG_ExifWriter::embed_location(
 *       $webp_data,
 *       'image/webp',
 *       [ 'lat' => 37.3891, 'lng' => -5.9845 ],   // Sevilla
 *       [ 'name' => 'Casco Bell RS7K GP', 'brand' => 'Bell', 'sku' => 'BELL-RS7K' ]
 *   );
 *
 *   // En el plugin de landing (municipios):
 *   $binary = CKG_ExifWriter::embed_location(
 *       $jpeg_data,
 *       'image/jpeg',
 *       [ 'lat' => 37.7749, 'lng' => -3.7906 ],    // Jaén
 *       [ 'location' => 'Jaén, Andalucía, España' ]
 *   );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CKG_ExifWriter {

    /**
     * Geocodifica un nombre de municipio/localidad a coordenadas GPS.
     * Usa OpenStreetMap Nominatim (gratuito, sin API key).
     *
     * @param string $place_name  "Córdoba, España" / "Ayuntamiento de Sevilla"
     * @return array|null  ['lat' => float, 'lng' => float] o null si no encontró
     */
    public static function geocode( string $place_name ): ?array {
        $query = urlencode( $place_name . ', España' );
        $url   = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1&countrycodes=es";

        $resp = wp_remote_get( $url, [
            'timeout'    => 8,
            'user-agent' => 'CMSKart-ExifWriter/1.0 (WordPress plugin; contact@cmskart.es)',
            'headers'    => [ 'Accept-Language' => 'es' ],
        ] );

        if ( is_wp_error( $resp ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) return null;

        return [
            'lat'         => (float) $body[0]['lat'],
            'lng'         => (float) $body[0]['lon'],
            'display_name'=> $body[0]['display_name'] ?? $place_name,
        ];
    }

    /**
     * Punto de entrada principal.
     * Inyecta GPS + metadatos en el binario de la imagen.
     *
     * @param string $binary    Datos binarios de la imagen
     * @param string $mime      'image/webp' | 'image/jpeg' | 'image/png'
     * @param array  $coords    ['lat' => float, 'lng' => float] (decimal, ej: 37.3891)
     * @param array  $meta      Metadatos adicionales:
     *                          'name'        → nombre del producto/página
     *                          'brand'       → marca
     *                          'sku'         → referencia
     *                          'location'    → texto de localización libre
     *                          'city'        → ciudad
     *                          'province'    → provincia
     *                          'country'     → país (defecto: España)
     *                          'keywords'    → array de keywords
     *                          'copyright'   → texto de copyright
     *                          'creator'     → creador (defecto: CMSKart.es)
     * @return string   Binario modificado (o el original si no se pudo modificar)
     */
    public static function embed_location(
        string $binary,
        string $mime,
        array  $coords,
        array  $meta = []
    ): string {
        if ( empty( $coords['lat'] ) || empty( $coords['lng'] ) ) return $binary;

        $lat = (float) $coords['lat'];
        $lng = (float) $coords['lng'];

        try {
            switch ( $mime ) {
                case 'image/webp':
                    return self::inject_webp_xmp( $binary, $lat, $lng, $meta );

                case 'image/jpeg':
                case 'image/jpg':
                    return self::inject_jpeg_exif( $binary, $lat, $lng, $meta );

                case 'image/png':
                    return self::inject_png_xmp( $binary, $lat, $lng, $meta );

                default:
                    return $binary;
            }
        } catch ( \Throwable $e ) {
            // Nunca romper la imagen — devolver original si algo falla
            error_log( 'CKG_ExifWriter error: ' . $e->getMessage() );
            return $binary;
        }
    }

    /* ══════════════════════════════════════════════════════════════════
       WEBP — Inyección de bloque XMP en RIFF
    ══════════════════════════════════════════════════════════════════ */

    private static function inject_webp_xmp( string $data, float $lat, float $lng, array $meta ): string {
        // Verificar cabecera RIFF WebP
        if ( strlen( $data ) < 12 ) return $data;
        if ( substr( $data, 0, 4 ) !== 'RIFF' ) return $data;
        if ( substr( $data, 8, 4 ) !== 'WEBP' ) return $data;

        $xmp = self::build_xmp( $lat, $lng, $meta );

        // El chunk XMP en WebP: 'XMP ' + tamaño 4 bytes LE + datos XMP
        // Si el tamaño es impar, añadir byte de padding
        $xmp_padded = $xmp;
        if ( strlen( $xmp_padded ) % 2 !== 0 ) $xmp_padded .= "\x00";

        $xmp_chunk  = 'XMP ';
        $xmp_chunk .= pack( 'V', strlen( $xmp ) );   // tamaño real sin padding
        $xmp_chunk .= $xmp_padded;

        // Eliminar bloque XMP existente si lo hay
        $data = self::remove_webp_chunk( $data, 'XMP ' );

        // Insertar el nuevo bloque XMP justo antes del final
        // Estructura WebP: RIFF(4) + filesize(4) + WEBP(4) + chunks...
        $new_data = substr( $data, 0, 12 ) . $xmp_chunk . substr( $data, 12 );

        // Actualizar el tamaño del archivo RIFF (bytes 4-7)
        $new_riff_size = strlen( $new_data ) - 8;
        $new_data = substr( $new_data, 0, 4 ) . pack( 'V', $new_riff_size ) . substr( $new_data, 8 );

        // Activar el flag XMP en el chunk VP8X si existe
        $new_data = self::set_webp_xmp_flag( $new_data );

        return $new_data;
    }

    private static function remove_webp_chunk( string $data, string $chunk_id ): string {
        $pos = 12; // Después de RIFF + size + WEBP
        while ( $pos + 8 <= strlen( $data ) ) {
            $id   = substr( $data, $pos, 4 );
            $size = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
            $padded = $size + ( $size % 2 ); // alinear a 2 bytes
            if ( $id === $chunk_id ) {
                $data = substr( $data, 0, $pos ) . substr( $data, $pos + 8 + $padded );
                break;
            }
            $pos += 8 + $padded;
        }
        return $data;
    }

    private static function set_webp_xmp_flag( string $data ): string {
        // Buscar chunk VP8X y activar bit XMP (bit 2 del byte de flags)
        $pos = 12;
        while ( $pos + 8 <= strlen( $data ) ) {
            $id   = substr( $data, $pos, 4 );
            $size = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
            if ( $id === 'VP8X' && $size >= 4 ) {
                $flags_byte = ord( $data[ $pos + 8 ] );
                $flags_byte |= 0x04; // bit 2 = XMP metadata presente
                $data[ $pos + 8 ] = chr( $flags_byte );
                return $data;
            }
            $pos += 8 + $size + ( $size % 2 );
        }
        return $data;
    }

    /* ══════════════════════════════════════════════════════════════════
       JPEG — Inyección de APP1 EXIF con GPS IFD
    ══════════════════════════════════════════════════════════════════ */

    private static function inject_jpeg_exif( string $data, float $lat, float $lng, array $meta ): string {
        if ( strlen( $data ) < 2 ) return $data;
        if ( substr( $data, 0, 2 ) !== "\xFF\xD8" ) return $data;

        // Construir el bloque EXIF con GPS IFD
        $exif = self::build_jpeg_exif_gps( $lat, $lng, $meta );

        // APP1 marker: 0xFF 0xE1 + tamaño (2 bytes BE, incluye los 2 bytes del propio campo size)
        $app1_size = strlen( $exif ) + 2;
        $app1_marker = "\xFF\xE1" . pack( 'n', $app1_size ) . $exif;

        // Encontrar dónde insertar: después del SOI (0xFFD8) y antes del APP0 si existe,
        // o simplemente después del SOI si no hay APP0
        $insert_pos = 2;

        // Si hay un APP0 (JFIF), insertar DESPUÉS de él
        if ( substr( $data, 2, 2 ) === "\xFF\xE0" ) {
            $app0_size = unpack( 'n', substr( $data, 4, 2 ) )[1];
            $insert_pos = 2 + 2 + $app0_size;
        }

        // Eliminar APP1 EXIF existente si lo hay (buscamos el marcador con 'Exif\0\0')
        $data = self::remove_jpeg_app1_exif( $data, $insert_pos );

        // Insertar
        return substr( $data, 0, $insert_pos ) . $app1_marker . substr( $data, $insert_pos );
    }

    private static function remove_jpeg_app1_exif( string $data, int $start ): string {
        $pos = $start;
        while ( $pos + 4 < strlen( $data ) ) {
            if ( $data[$pos] !== "\xFF" ) break;
            $marker = $data[$pos+1];
            if ( $marker === "\xD9" || $marker === "\xDA" ) break; // EOI / SOS

            if ( $pos + 4 > strlen( $data ) ) break;
            $seg_size = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];

            if ( $marker === "\xE1" && substr( $data, $pos + 4, 6 ) === "Exif\0\0" ) {
                // Eliminar este segmento
                $data = substr( $data, 0, $pos ) . substr( $data, $pos + 2 + $seg_size );
                break;
            }
            $pos += 2 + $seg_size;
        }
        return $data;
    }

    /**
     * Construye un bloque EXIF TIFF mínimo con GPS IFD.
     * Formato: TIFF header + IFD0 (con puntero a GPS IFD) + GPS IFD
     */
    private static function build_jpeg_exif_gps( float $lat, float $lng, array $meta ): string {
        // EXIF header
        $exif_header = "Exif\0\0";

        // TIFF — little endian
        $tiff  = "II";       // byte order: Intel (little-endian)
        $tiff .= "\x2A\x00"; // TIFF magic number
        $tiff .= "\x08\x00\x00\x00"; // Offset to IFD0 = 8

        // GPS IFD offset: justo después de IFD0
        // IFD0: 2 bytes (count) + n × 12 bytes (entries) + 4 bytes (next IFD = 0)
        $ifd0_entry_count = 3;
        $gps_ifd_offset = 8 + 2 + ($ifd0_entry_count * 12) + 4;

        // IFD0 entries
        // Tag 0x8825 = GPSInfoIFD — puntero al GPS IFD
        // Tag 0x013B = Artist
        // Tag 0x013E = WhitePoint (lo usamos para poner el nombre del lugar)
        $artist = isset( $meta['creator'] ) ? $meta['creator'] : 'CMSKart.es';
        $desc   = self::build_location_string( $meta );

        // Calcular offsets de los datos de longitud variable
        // Empiezan después de IFD0 completo + GPS IFD
        $gps_ifd_size = 2 + (6 * 12) + 4; // 6 entries GPS
        $data_start   = $gps_ifd_offset + $gps_ifd_size;

        $artist_offset = $data_start;
        $artist_data   = $artist . "\x00";
        $artist_data   = self::pad2( $artist_data );

        $desc_offset = $artist_offset + strlen( $artist_data );
        $desc_data   = $desc . "\x00";
        $desc_data   = self::pad2( $desc_data );

        // GPS racional values
        $gps_data_start = $desc_offset + strlen( $desc_data );

        $lat_abs     = abs( $lat );
        $lat_deg     = (int) $lat_abs;
        $lat_min     = (int) ( ( $lat_abs - $lat_deg ) * 60 );
        $lat_sec_f   = ( ( $lat_abs - $lat_deg ) * 60 - $lat_min ) * 60;
        $lat_sec_num = (int) round( $lat_sec_f * 1000 );

        $lng_abs     = abs( $lng );
        $lng_deg     = (int) $lng_abs;
        $lng_min     = (int) ( ( $lng_abs - $lng_deg ) * 60 );
        $lng_sec_f   = ( ( $lng_abs - $lng_deg ) * 60 - $lng_min ) * 60;
        $lng_sec_num = (int) round( $lng_sec_f * 1000 );

        // GPSLatitude: 3 × RATIONAL = 3 × 8 bytes = 24 bytes
        $lat_data   = pack( 'VV', $lat_deg, 1 ) . pack( 'VV', $lat_min, 1 ) . pack( 'VV', $lat_sec_num, 1000 );
        $lng_data   = pack( 'VV', $lng_deg, 1 ) . pack( 'VV', $lng_min, 1 ) . pack( 'VV', $lng_sec_num, 1000 );

        $lat_offset = $gps_data_start;
        $lng_offset = $lat_offset + 24;

        // IFD0
        $ifd0  = pack( 'v', $ifd0_entry_count );
        // Tag 0x010E = ImageDescription
        $ifd0 .= pack( 'vvVV', 0x010E, 2, strlen($desc)+1, $desc_offset );
        // Tag 0x013B = Artist
        $ifd0 .= pack( 'vvVV', 0x013B, 2, strlen($artist)+1, $artist_offset );
        // Tag 0x8825 = GPSInfoIFD
        $ifd0 .= pack( 'vvVV', 0x8825, 4, 1, $gps_ifd_offset );
        // Next IFD = 0
        $ifd0 .= pack( 'V', 0 );

        // GPS IFD
        $gps_ifd  = pack( 'v', 6 );
        // Tag 0x0001 = GPSLatitudeRef (N/S) — ASCII 2 bytes, cabe inline
        $lat_ref  = $lat >= 0 ? "N\x00" : "S\x00";
        $gps_ifd .= pack( 'vvV', 0x0001, 2, 2 ) . $lat_ref . pack( 'V', 0 ); // valor inline de 4 bytes
        // Tag 0x0002 = GPSLatitude — 3 RATIONAL, offset
        $gps_ifd .= pack( 'vvVV', 0x0002, 5, 3, $lat_offset );
        // Tag 0x0003 = GPSLongitudeRef
        $lng_ref  = $lng >= 0 ? "E\x00" : "W\x00";
        $gps_ifd .= pack( 'vvV', 0x0003, 2, 2 ) . $lng_ref . pack( 'V', 0 );
        // Tag 0x0004 = GPSLongitude — 3 RATIONAL, offset
        $gps_ifd .= pack( 'vvVV', 0x0004, 5, 3, $lng_offset );
        // Tag 0x0012 = GPSMapDatum — WGS-84
        $wgs84    = "WGS-84\x00\x00"; // 8 bytes, cabe inline
        $gps_ifd .= pack( 'vvV', 0x0012, 2, 7 ) . substr( $wgs84, 0, 4 ) . substr( $wgs84, 4, 4 );
        // Usamos 2 últimas tags concatenadas: 0x001D = GPSDateStamp  
        $date     = gmdate('Y:m:d') . "\x00";
        $date     = str_pad( $date, 11, "\x00" );
        $gps_ifd .= pack( 'vvV', 0x001D, 2, 11 ) . pack( 'V', $lng_offset + 24 );
        // Next GPS IFD = 0
        $gps_ifd .= pack( 'V', 0 );

        // Datos variables: artist, desc, lat, lng, date
        $variable_data  = $artist_data . $desc_data . $lat_data . $lng_data . $date;

        $tiff_body = $ifd0 . $gps_ifd . $variable_data;

        return $exif_header . $tiff . $tiff_body;
    }

    /* ══════════════════════════════════════════════════════════════════
       PNG — Inyección de chunk iTXt con XMP
    ══════════════════════════════════════════════════════════════════ */

    private static function inject_png_xmp( string $data, float $lat, float $lng, array $meta ): string {
        if ( substr( $data, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) return $data;

        $xmp = self::build_xmp( $lat, $lng, $meta );

        // iTXt chunk: keyword + \0 + compression=0 + compressionMethod=0 + \0 + \0 + text
        $keyword = 'XML:com.adobe.xmp';
        $chunk_data = $keyword . "\x00\x00\x00\x00\x00" . $xmp;

        $crc        = crc32( 'iTXt' . $chunk_data );
        $new_chunk  = pack( 'N', strlen( $chunk_data ) ) . 'iTXt' . $chunk_data . pack( 'N', $crc );

        // Insertar después de la cabecera PNG (8 bytes) + chunk IHDR (4+4+13+4 = 25 bytes)
        return substr( $data, 0, 33 ) . $new_chunk . substr( $data, 33 );
    }

    /* ══════════════════════════════════════════════════════════════════
       XMP — Construcción del bloque XML/RDF
    ══════════════════════════════════════════════════════════════════ */

    private static function build_xmp( float $lat, float $lng, array $meta ): string {
        $lat_dms = self::decimal_to_xmp_gps( $lat );
        $lng_dms = self::decimal_to_xmp_gps( $lng );
        $lat_ref = $lat >= 0 ? 'N' : 'S';
        $lng_ref = $lng >= 0 ? 'E' : 'W';

        $location = self::build_location_string( $meta );
        $name     = esc_attr( $meta['name']     ?? '' );
        $brand    = esc_attr( $meta['brand']    ?? '' );
        $sku      = esc_attr( $meta['sku']      ?? '' );
        $city     = esc_attr( $meta['city']     ?? '' );
        $province = esc_attr( $meta['province'] ?? '' );
        $country  = esc_attr( $meta['country']  ?? 'España' );
        $creator  = esc_attr( $meta['creator']  ?? 'CMSKart.es' );
        $copyright= esc_attr( $meta['copyright'] ?? '© ' . gmdate('Y') . ' CMSKart.es' );
        $date     = gmdate( 'Y-m-d\TH:i:s' ) . '+01:00';

        // Keywords
        $keywords_arr = $meta['keywords'] ?? [];
        if ( $name )    $keywords_arr[] = $name;
        if ( $brand )   $keywords_arr[] = $brand;
        if ( $city )    $keywords_arr[] = $city;
        if ( $province )$keywords_arr[] = $province;
        $keywords_arr[] = 'karting';
        $keywords_arr[] = 'CMSKart';
        $keywords_arr   = array_unique( array_filter( $keywords_arr ) );
        $keywords_xml   = implode( '', array_map(
            fn( $k ) => "\n        <rdf:li>" . esc_attr( $k ) . "</rdf:li>",
            array_slice( $keywords_arr, 0, 10 )
        ) );

        return <<<XMP
<?xpacket begin="\xef\xbb\xbf" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about=""
      xmlns:exif="http://ns.adobe.com/exif/1.0/"
      xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
      xmlns:xmp="http://ns.adobe.com/xap/1.0/"
      xmlns:dc="http://purl.org/dc/elements/1.1/"
      xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"
      xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/"
      xmlns:plus="http://ns.useplus.org/ldf/xmp/1.0/">

      <!-- GPS Location -->
      <exif:GPSLatitude>{$lat_dms},{$lat_ref}</exif:GPSLatitude>
      <exif:GPSLongitude>{$lng_dms},{$lng_ref}</exif:GPSLongitude>
      <exif:GPSAltitudeRef>0</exif:GPSAltitudeRef>
      <exif:GPSMapDatum>WGS-84</exif:GPSMapDatum>

      <!-- Basic metadata -->
      <tiff:ImageDescription>{$location}</tiff:ImageDescription>
      <tiff:Artist>{$creator}</tiff:Artist>
      <tiff:Copyright>{$copyright}</tiff:Copyright>

      <!-- XMP core -->
      <xmp:CreateDate>{$date}</xmp:CreateDate>
      <xmp:ModifyDate>{$date}</xmp:ModifyDate>
      <xmp:CreatorTool>CMSKart Product Generator</xmp:CreatorTool>

      <!-- Dublin Core -->
      <dc:title>
        <rdf:Alt><rdf:li xml:lang="x-default">{$name}</rdf:li></rdf:Alt>
      </dc:title>
      <dc:description>
        <rdf:Alt><rdf:li xml:lang="x-default">{$location}</rdf:li></rdf:Alt>
      </dc:description>
      <dc:creator><rdf:Seq><rdf:li>{$creator}</rdf:li></rdf:Seq></dc:creator>
      <dc:rights>
        <rdf:Alt><rdf:li xml:lang="x-default">{$copyright}</rdf:li></rdf:Alt>
      </dc:rights>
      <dc:subject>
        <rdf:Bag>{$keywords_xml}
        </rdf:Bag>
      </dc:subject>

      <!-- Photoshop/IPTC -->
      <photoshop:City>{$city}</photoshop:City>
      <photoshop:State>{$province}</photoshop:State>
      <photoshop:Country>{$country}</photoshop:Country>
      <photoshop:Credit>{$creator}</photoshop:Credit>
      <photoshop:Source>{$creator}</photoshop:Source>

      <!-- IPTC Core -->
      <Iptc4xmpCore:Location>{$location}</Iptc4xmpCore:Location>
      <Iptc4xmpCore:City>{$city}</Iptc4xmpCore:City>
      <Iptc4xmpCore:ProvinceState>{$province}</Iptc4xmpCore:ProvinceState>
      <Iptc4xmpCore:CountryName>{$country}</Iptc4xmpCore:CountryName>
      <Iptc4xmpCore:CreatorContactInfo>
        <rdf:Description>
          <Iptc4xmpCore:CiUrlWork>https://cmskart.es</Iptc4xmpCore:CiUrlWork>
        </rdf:Description>
      </Iptc4xmpCore:CreatorContactInfo>

    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XMP;
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * Convierte decimal GPS a formato XMP (grados,minutos.decimales)
     * Ejemplo: 37.3891 → "37,23.346"
     */
    private static function decimal_to_xmp_gps( float $decimal ): string {
        $decimal = abs( $decimal );
        $degrees = (int) $decimal;
        $minutes = ( $decimal - $degrees ) * 60;
        return $degrees . ',' . number_format( $minutes, 6, '.', '' );
    }

    private static function build_location_string( array $meta ): string {
        if ( ! empty( $meta['location'] ) ) return sanitize_text_field( $meta['location'] );
        $parts = array_filter( [
            $meta['name']     ?? '',
            $meta['city']     ?? '',
            $meta['province'] ?? '',
            $meta['country']  ?? 'España',
        ] );
        return implode( ', ', $parts ) ?: 'CMSKart.es — Karting España';
    }

    private static function pad2( string $str ): string {
        return strlen( $str ) % 2 !== 0 ? $str . "\x00" : $str;
    }
}
