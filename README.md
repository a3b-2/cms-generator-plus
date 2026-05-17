# CMSKart Product Generator

Plugin WordPress para WooCommerce que automatiza la creación y enriquecimiento de fichas de producto para tiendas de karting. Integra Ollama (IA local), ChromaDB (base de datos vectorial) y scraping de competidores.

**Versión actual:** 2.6.1  
**Requiere:** WordPress 6.0+, WooCommerce 7.0+, PHP 8.1+  
**Desarrollado para:** cmskart.es

---

## Funciones implementadas

### ✅ Creación de productos
- Formulario multi-tab: Básico, Imágenes, Variaciones, Homologación, Compatibilidad, Contenido SEO, Complementos, SEO, IA/Plantillas, Competencia
- Modo Express: auto-relleno desde URL de fabricante o competidor
- Clonado de productos existentes
- Integración con WooCommerce: atributos, variaciones, precios, stock
- Campos personalizados: marca, homologaciones, tallas, compatibilidad

### ✅ Importación CSV
- Importación masiva de productos desde CSV
- Detección de formato: columnas flexibles
- Preservación de traducciones EN/IT del CSV en meta fields `_ckg_desc_en` / `_ckg_desc_it`
- Detección de contenido de baja calidad (patrones de importación automática)

### ✅ Enriquecimiento batch con IA
- Página dedicada: CMSKart → 🚀 Enriquecer
- Análisis de productos pendientes filtrado por:
  - Categoría
  - Score SEO máximo
  - Solo productos sin enriquecer
- **Modo Normal**: productos pendientes de enriquecer
- **Modo Revisión**: productos ya enriquecidos hace más de X días con score bajo
- Generación con Ollama de:
  - Descripción larga (intro + cuerpo)
  - Descripción corta
  - Conclusión (llamada a la acción)
  - FAQ (preguntas frecuentes)
  - Traducciones EN e IT
  - SEO title, meta description, keywords
  - Schema JSON-LD (Product + FAQPage)
- Preserva contenido elaborado existente (>80 palabras o con `<h2>/<h3>`)
- Sobreescribe solo contenido de baja calidad (patrones CSV de importación)
- Backup automático antes de cada cambio en `_ckg_backup_desc`
- Búsqueda automática en competidores para productos con nombre genérico
- Detección de canibalización de keywords via ChromaDB
- Guardado de precios de competidores con alerta si el competidor es más barato
- Integración con RankMath SEO
- Integración con Social Meta (Facebook, Google Shopping, Pinterest)
- Schema JSON-LD inyectado en `wp_head` automáticamente
- Re-enriquecimiento inteligente: detecta productos con >30 días y score bajo

### ✅ Historial de revisiones
- Tabla propia `wp_ckg_revisions` (sobrevive a reinstalaciones)
- Guarda antes/después de cada cambio con fecha y acción
- Máximo 10 revisiones por producto (auto-limpieza)
- Página admin: CMSKart → 🕐 Revisiones
- Restaurar cualquier versión anterior con un click

### ✅ Historial de productos creados
- Tabla con todos los productos generados con el plugin
- Columnas: imagen, nombre, marca, precio, SEO score, completitud, estado, fecha
- Acciones: editar, ver, clonar
- ⚠️ Bug conocido: la columna precio mostraba HTML crudo (corregido en v2.5.3)

### ✅ Scraper de competidores
- Scraping de páginas de producto de competidores
- Compatible con WooCommerce y PrestaShop
- Extrae: título, precio, descripción, especificaciones, imágenes, tallas/colores
- Soporta lazy loading de imágenes (data-src)
- Extrae specs de `dl/dt/dd` (PrestaShop), tablas WooCommerce, y meta tags OpenGraph
- Extrae precio desde `meta property="product:price:amount"`
- Extrae tallas desde `ul.product-variants-item li` (PrestaShop)
- Extrae variaciones WooCommerce desde `data-product_variations`
- User-agent Chrome para evitar bloqueos
- ⚠️ Pendiente: tiendas con JS rendering (tallas cargadas via JavaScript)

### ✅ Importador de imágenes
- Importa imágenes desde URL de página web o URL directa de imagen
- Detección automática: URL de imagen directa vs URL de página HTML
- Descarga con user-agent Chrome
- Conversión a WebP con redimensionado a 600×600
- Fondo blanco para imágenes con transparencia
- Validación de MIME type
- ⚠️ Pendiente: algunas URLs de CDN externas bloqueadas por Ginernet

### ✅ ChromaDB — Base de datos vectorial
- Conexión a ChromaDB local via túnel Cloudflare
- Soporte API v1 y v2 (detección automática)
- Modelo de embeddings: nomic-embed-text via Ollama
- Indexación masiva del catálogo (bulk index por lotes de 10)
- Colecciones: productos, keywords, marcas, FAQs
- **Detección de duplicados**: al crear producto nuevo, busca similares en ChromaDB
- **Canibalización de keywords**: detecta si otra ficha usa la misma keyword principal
- **Búsqueda semántica**: para enriquecimiento de productos genéricos

### ✅ Proveedores de IA
- Ollama (local) — recomendado, gratuito
- OpenAI (ChatGPT) — requiere API Key
- Anthropic (Claude) — requiere API Key
- Perplexity — requiere API Key, acceso a info en tiempo real
- DeepSeek — muy económico, requiere API Key

### ✅ Configuración
- Perfil de GPU automático (detecta RTX, VRAM disponible, contexto óptimo)
- Detección automática del endpoint Ollama desde otros plugins
- Coordenadas GPS de la tienda para EXIF de imágenes
- Badges configurables: envío, garantía, recogida
- Proveedor de IA seleccionable con API Keys

### ✅ SEO
- Integración completa con RankMath
- Score SEO calculado y guardado en `_ckg_seo_score`
- Meta title y description generados por Ollama
- Schema JSON-LD Product + Offer + FAQPage
- ⚠️ Bug conocido: schema puede generar warnings en RankMath class-jsonld.php

### ✅ Social Meta
- Campos Facebook: product_condition, age_group, gender, visibility, brand, mpn
- Google Shopping / Pinterest
- Re-aplicación automática al guardar producto con imagen

---

## Funciones pendientes / con bugs conocidos

| Función | Estado | Descripción |
|---------|--------|-------------|
| Imágenes importación | ⚠️ Intermitente | Algunas URLs externas bloqueadas por Ginernet |
| Atributos scraper | ❌ Pendiente | Tallas/colores con JS rendering no se extraen |
| ChromaDB duplicados UI | ⚠️ En pruebas | El aviso en campo nombre requiere Ollama activo |
| Bulk index conteo | ✅ Corregido v2.5.9 | Loop JS y conteo total corregidos |
| Precio historial HTML | ✅ Corregido v2.5.3 | get_price_html() mostraba HTML crudo |
| ERROR-ID enriquecimiento | ✅ Corregido v2.4.6 | CKG_Ollama::generate() → improve_raw() |

---

## Requisitos del servidor

### WordPress (Ginernet)
- PHP 8.1+ con GD, mbstring, json
- WooCommerce 7.0+
- RankMath SEO (opcional pero recomendado)
- Prefijo tablas: `cmskarte_XXXX_` (no estándar)

### PC local (Carlos)
- Ollama con modelo principal (qwen2.5, llama3, etc.)
- Ollama con modelo `nomic-embed-text` para embeddings
- ChromaDB 1.x corriendo en puerto 8000
- Túnel Cloudflare activo para exponer Ollama y ChromaDB

### Scripts de arranque (C:\CMSKart\)
```bat
# start_chroma.bat
@echo off
cd /d C:\CMSKart
call env\Scripts\activate
python -c "import sys; sys.argv = ['chroma', 'run', '--path', './chroma_db', '--host', 'localhost', '--port', '8000']; from chromadb.cli.cli import app; app()"
pause
```

---

## Estructura de archivos

```
cmskart-product-generator/
├── cmskart-product-generator.php     # Plugin principal, handlers AJAX
├── admin/
│   └── admin-page.php                # Registro de submenús
├── includes/
│   ├── class-attribute-extractor.php
│   ├── class-attribute-migrator.php
│   ├── class-autofill.php
│   ├── class-batch-enricher.php      # Enriquecimiento masivo con IA
│   ├── class-batch-importer.php
│   ├── class-checkout-fields.php
│   ├── class-chromadb.php            # Cliente ChromaDB v1/v2
│   ├── class-competitive-seo.php
│   ├── class-competitor-scraper.php  # Scraper WooCommerce + PrestaShop
│   ├── class-content-builder.php     # Constructor de descripciones largas
│   ├── class-enrichment-queue.php
│   ├── class-exif-writer.php
│   ├── class-history.php             # Historial de productos creados
│   ├── class-image-importer.php      # Importador de imágenes con WebP
│   ├── class-llm-provider.php        # Abstracción multi-proveedor IA
│   ├── class-maintenance.php
│   ├── class-ollama.php              # Cliente Ollama
│   ├── class-rankmath.php            # Integración RankMath
│   ├── class-revisions.php           # Historial de versiones de contenido
│   ├── class-seo-analyzer.php
│   ├── class-social-meta.php         # Meta tags sociales
│   ├── class-web-story.php
│   ├── class-woo-creator.php
│   ├── shortcodes.php
│   └── templates.php
└── assets/
    ├── css/admin.css / styles.css
    └── js/admin.js / frontend.js
```

---

## Meta fields utilizados

| Meta key | Descripción |
|----------|-------------|
| `_ckg_generated` | Flag: producto enriquecido (1) |
| `_ckg_enriched_at` | Fecha del último enriquecimiento |
| `_ckg_seo_score` | Score SEO calculado (0-100) |
| `_ckg_faq` | FAQ en JSON |
| `_ckg_schema` | Schema JSON-LD del producto |
| `_ckg_desc_en` | Descripción en inglés |
| `_ckg_desc_it` | Descripción en italiano |
| `_ckg_backup_desc` | Backup descripción larga antes de enriquecer |
| `_ckg_backup_short` | Backup descripción corta |
| `_ckg_backup_date` | Fecha del backup |
| `_ckg_comp_price_min` | Precio mínimo encontrado en competidores |
| `_ckg_comp_price_urls` | URLs de competidores consultadas |
| `_ckg_comp_price_date` | Fecha consulta precios |
| `_ckg_price_alert` | Alerta si competidor es más barato |
| `_ckg_keyword_conflict` | Alerta de canibalización de keywords |
| `_ckg_homologaciones` | Homologaciones en JSON |
| `_ckg_brand` | Marca del producto |

---

## Tablas de base de datos propias

| Tabla | Descripción |
|-------|-------------|
| `{prefix}ckg_revisions` | Historial de versiones de contenido |

---

## Changelog resumido

- **v2.6.1** — Añadidos `find_similar_products()` y `check_keyword_cannibalization()` en ChromaDB
- **v2.6.0** — Fix loop bulk_index (has_more → done)
- **v2.5.9** — Fix contador total bulk_index con SQL directa
- **v2.5.8** — Añadido método `bulk_index()` en CKG_ChromaDB
- **v2.5.7** — Enriquecimiento completo: precios competidores, filtro score, modo revisión, canibalización keywords
- **v2.5.6** — FAQ + Conclusión + Traducciones EN/IT + Schema JSON-LD en una pasada
- **v2.5.5** — FAQ independiente de la descripción
- **v2.5.4** — Fix submenú Revisiones en admin
- **v2.5.3** — Fix precio HTML en historial
- **v2.5.2** — Sistema completo de revisiones con tabla propia
- **v2.5.1** — Preservar traducciones CSV, proteger contenido elaborado
- **v2.5.0** — Detección de contenido de baja calidad (patrones CSV)
- **v2.4.9** — Backup automático + búsqueda competidores para nombres genéricos
- **v2.4.8** — Enricher lee y usa contenido existente del producto
- **v2.4.7** — Umbrales de sobreescritura por longitud
- **v2.4.6** — Fix crítico: CKG_Ollama::generate() → improve_raw()
- **v2.4.5** — Fix fetch_html con Chrome user-agent (imágenes Sparco)
- **v2.4.4** — Fix cola enricher: limpiar al iniciar, product_id en errores
- **v2.4.3** — Restaurar canvas GD simple, download_image simplificado
- **v2.4.2** — Fix MIME type con image_type_to_mime_type
- **v2.4.0** — Scraper PrestaShop: lazy loading, dl/dt/dd specs, variaciones
