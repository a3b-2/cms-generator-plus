# PLUGIN_STATE.md — CMSKart Product Generator
# Pegar al inicio de cada sesión con Claude para restaurar contexto completo
# Última actualización: v2.8.0 — 2026-05-17

---

## IDENTIDAD DEL PROYECTO
- **Plugin:** CMSKart Product Generator
- **WordPress:** cmskart.es | Ginernet (PHP 8.3) | Prefijo tablas: `wpwq_`
- **Plugin path en servidor:** `/wp-content/plugins/PLUGIN/`
- **Archivo principal:** `cmskart-product-generator.php`
- **Versión actual:** 2.8.0

---

## ARQUITECTURA LOCAL (PC Carlos — Huelva)
```
C:\CMSKart\
├── qdrant\              → Qdrant v1.16.3 nativo Windows, puerto 6333
├── local-scraper-iame\  → Scripts Python + API FastAPI
│   ├── alimentar_qdrant.py   → Ingesta PDFs fabricantes con nomic-embed-text
│   ├── scraper_api.py        → FastAPI puerto 8001 (Playwright + Qdrant)
│   ├── traducir_qdrant.py    → Traduce fragmentos Qdrant al español
│   ├── ver_qdrant.py         → Inspecciona colecciones Qdrant
│   └── venv\                 → Python venv con dependencias
└── chroma_db\           → ChromaDB puerto 8000 (catálogo CMSKart)
```

**Ollama:** localhost:11434 | Endpoint: `/v1/chat/completions`
**Modelos disponibles:** nemotron-3-nano:4b (mejor/más rápido), llama3.2, nomic-embed-text (embeddings 768d)
**Qdrant colección:** `cmskart_conocimiento` — 509 vectores (PDFs IAME en inglés/francés, pendiente traducción)
**ChromaDB colección:** `cmskart_conocimiento` — 690 productos del catálogo

**Túneles Cloudflare:** cambian al reiniciar. Configurar en Ajustes del plugin:
- Ollama tunnel → campo `Ollama Endpoint`
- Qdrant tunnel → campo `Qdrant Tunnel`
- Scraper API → campo `URL API Local` (default http://localhost:8001)

---

## AUDITORÍA / CONTROL DE CAMBIOS
- Fecha: 2026-05-17
- Acción: Inicializado repositorio Git y ramas en el remoto `https://github.com/a3b-2/cms-generator-plus.git`.
    - Ramas creadas: `main` (estado actual), `fix-audit-errors` (cambios en curso).
- Cambios iniciales realizados en `fix-audit-errors`:
    - `includes/class-batch-enricher.php`: corregido uso de variable indefinida `$max_score` (ahora se lee desde filtros).
    - `includes/class-qdrant.php`: `ollama_endpoint()` ahora usa `CKG_Ollama::get_resolved_endpoint()` o fallback a la configuración.

- Nuevo (2026-05-17): endurecimiento de parsing JSON
    - `includes/class-llm-provider.php`: reemplazados `@json_decode` por decodificación segura y extracción de JSON embebido; manejo de errores devuelven `WP_Error` o `_raw` según el caso.
    - `includes/class-ollama.php`: validación robusta de `wp_remote_*` responses; evita indexar resultados de `json_decode()` cuando falla y devuelve mensajes de error más claros.
    - Commit: "Hardening: robust JSON parsing for Ollama and LLM provider; remove @json_decode usage". Rama: `fix-audit-errors`.

Se irá actualizando este documento a medida que se apliquen más correcciones.

## ESTRUCTURA DEL PLUGIN
```
cmskart-product-generator/
├── cmskart-product-generator.php  → Archivo principal: AJAX handlers, páginas admin
├── admin/
│   └── admin-page.php             → Todas las páginas del menú admin
├── includes/
│   ├── class-batch-enricher.php   → Enriquecimiento batch (process_next + enrich_product PUBLIC)
│   ├── class-chromadb.php         → Cliente ChromaDB
│   ├── class-context-scraper.php  → Scraping fabricantes: Qdrant→ChromaDB→web→fallback
│   ├── class-image-importer.php   → Importación imágenes (sin EXIF GPS — corrompía WebP)
│   ├── class-llm-provider.php     → Abstracción LLM: improve_raw() es el método correcto
│   ├── class-ollama.php           → Cliente Ollama
│   ├── class-prompt-manager.php   → CRUD prompts, render() con variables, tabla ckg_prompts
│   ├── class-qdrant.php           → Cliente Qdrant: search_filtered(), find_manufacturer_knowledge()
│   ├── class-rankmath.php         → Integración RankMath SEO
│   ├── class-revisions.php        → Historial revisiones (max 10/producto)
│   └── class-scheduler.php        → Cola Web Stories via WP-Cron
└── assets/
    ├── js/admin.js                → JS principal: IIFE con CKG_ADMIN, nonce, ajax
    └── css/admin.css
```

---

## TABLAS DE BASE DE DATOS (prefijo wpwq_)
```sql
wpwq_ckg_prompts       → Gestor prompts (id, nombre, system_prompt, user_prompt,
                          modelo_recomendado, usa_scraper, usa_chroma, usa_qdrant,
                          es_predefinido, activo)
wpwq_ckg_scraper_log   → Log de scraping por producto
wpwq_ckg_revisions     → Historial de contenido generado (max 10/producto)
wpwq_ckg_scheduler_log → Log de cola Web Stories
```

**Meta keys WooCommerce del plugin:**
- `_ckg_generated`     → flag: producto enriquecido
- `_ckg_seo_score`     → score SEO 0-100
- `_ckg_backup_desc`   → backup descripción original
- `_ckg_keyword_conflict` → canibalización detectada
- `_ckg_price_alert`   → alerta precio competidor
- `_ckg_comp_price_min` → precio mínimo competidor detectado

---

## MENÚ ADMIN
```
CMSKart Generator
├── Nuevo Producto       → ckg_admin_page() — formulario completo con Modo Express
├── 🚀 Enriquecer        → ckg_enrich_page() — batch + buscador individual
├── ✍️ Prompts           → ckg_prompts_page() — CRUD prompts inline en PHP
├── 📋 Log               → ckg_log_page() — changelog + queries SQL útiles
├── 🕐 Revisiones        → Historial de contenido generado
├── Ajustes              → ckg_settings_page() — toda la configuración
└── Historial            → ckg_history_page()
```

---

## AJAX HANDLERS (todos verifican ckg_admin_nonce)
```
ckg_prompt_save          → Guardar/editar prompt
ckg_prompt_delete        → Borrar prompt (no predefinidos)
ckg_prompt_render        → Preview prompt con variables sustituidas
ckg_enrich_with_prompt   → Enriquecer con prompt específico
ckg_enricher_process     → Batch: process_next() | Individual: enrich_product(product_id)
ckg_enricher_analyze     → Analizar productos para enriquecer
ckg_enricher_status      → Estado de la cola
ckg_enrich_search_product → Buscar producto por nombre/SKU
ckg_express_autofill     → Pipeline completo: URL→Playwright→Qdrant→LLM→JSON→form
ckg_qdrant_ping          → Verificar conexión Qdrant
ckg_qdrant_search        → Búsqueda semántica de prueba en Qdrant
ckg_autofill             → Express básico (legacy, mantener)
ckg_store_autofill       → Guardar datos Express en transient WP
```

---

## PROMPTS PREDEFINIDOS
```
ID 1 — "Ollama — Texto Plano"
  modelo: ollama | scraper: NO | chroma: SI | qdrant: NO
  Genera JSON: short_desc, intro, conclusion, desc_en, desc_it, faq, seo_*

ID 2 — "Claude — SEO Completo"  
  modelo: anthropic | scraper: SI | chroma: SI | qdrant: SI
  Genera HTML completo estilo Reedster IV (600-900 palabras, tabla specs, FAQ, schema JSON-LD)
```

**Variables disponibles en prompts:**
`{nombre}` `{marca}` `{sku}` `{categoria}` `{precio}` `{specs}` `{descripcion_actual}`
`{json_scraper}` `{keywords}` `{homologaciones}` `{faq_existente}` `{qdrant_context}`

---

## PIPELINE EXPRESS AUTO-RELLENAR (v2.8.0)
```
URL → scraper_api.py /scrape-url (Playwright, puerto 8001)
    → ckg_extract_product_data_from_html() — nombre, marca, precio, imágenes
    → CKG_Qdrant::find_manufacturer_knowledge() — PDFs fabricante
    → ckg_build_express_prompt() — prompt con todo el contexto en XML tags
    → CKG_LLM_Provider::improve_raw() — llama al modelo
    → ckg_parse_express_response() — parsea JSON (limpia markdown)
    → loadFormData() JS — rellena TODOS los campos del formulario
```

**Respuesta JSON que genera el LLM:**
`product_name, brand, sku, short_desc, long_desc (HTML), specs[], faq[],
homologaciones[], seo_title, seo_description, seo_keywords, categories[], tags[], price`

---

## CONTEXT SCRAPER — ORDEN DE PRIORIDAD
```
1. Qdrant local         → find_manufacturer_knowledge() + find_homologations()
2. ChromaDB local       → caché scraping + conocimiento marca
3. Web fabricante       → scrape_manufacturer() con Playwright via API local
4. Web distribuidor     → KPSRacing, gt2i, MG Karting
5. CIK-FIA PDFs         → homologaciones oficiales
6. Fallback             → Amazon/eBay último recurso
```

**Ollama:** localhost:11434 | Endpoint: `/v1/chat/completions`

---

## BUGS CONOCIDOS / PENDIENTES
```
[ ] Traducción Qdrant pendiente: 509 fragmentos en inglés/francés
    → python traducir_qdrant.py (usa nemotron-3-nano:4b + /v1/chat/completions)
    → Tarda ~2-3h. Ejecutar de noche.

[ ] Túnel Cloudflare Qdrant: configurar y probar end-to-end
    → Ajustes → Qdrant → Túnel Cloudflare

[ ] Pipeline Express: probar con motor IAME real
    → Pegar URL iameengines.com/product/x30 → verificar JSON generado

[ ] Tabla wpwq_ckg_prompts: si existe de versión anterior, hacer DROP y reactivar
    → DROP TABLE IF EXISTS wpwq_ckg_prompts;

[ ] HNSW index Qdrant: se activa automáticamente al superar 10.000 vectores
```

---

## REGLAS CRÍTICAS (no romper nunca)
```
PHP:
- Nonce siempre: ckg_admin_nonce (NO ckg_ajax — ese fue el bug del 403)
- LLM: CKG_LLM_Provider::improve_raw() — NO generate() (no existe)
- wp_unslash() en prompts — NO wp_kses_post (borra tags XML del prompt)
- enrich_product() es PUBLIC en class-batch-enricher.php

JS:
- admin.js es un IIFE: (function($, ADMIN){...})(jQuery, CKG_ADMIN)
- nonce viene de ADMIN.nonce (CKG_ADMIN localizado en wp_enqueue)
- calcCompleteness() tiene guard: if(!$('#product_name').length) return;
- Páginas sin formulario de producto NO tienen #product_name ni #images_urls

Qdrant:
- Vector size: 768 (nomic-embed-text)
- Colección conocimiento: cmskart_conocimiento
- check_compatibility=False en QdrantClient (v1.18 client vs v1.16.3 server)
- Embeddings SIEMPRE via Ollama /api/embeddings (NO ChromaDB default)

Imágenes:
- Sin EXIF GPS — corrompía WebP (imágenes grises)
- class-image-importer.php v1.4.0 es la versión estable
```

---

## PARA CLAUDE — INICIO DE SESIÓN
Cuando Carlos pegue este archivo, responder:
"Contexto v2.8.0 cargado. [Estado de Qdrant/ChromaDB si se menciona]. ¿Continuamos con [pendiente más urgente]?"

Prioridades pendientes en orden:
1. Probar Express Auto-rellenar end-to-end con producto real
2. Ejecutar traducir_qdrant.py (noche)
3. Configurar túnel Cloudflare para Qdrant
4. Alimentar Qdrant con más fabricantes (Rotax, OTK, Vortex)
5. Prompt perfecto para producto tipo Reedster IV (sesión dedicada)
