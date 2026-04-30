# TableQR Translation Plugin

Translation-only plugin for TableQR digital menu WordPress sites. Replaces WPGlobus for multisite environments.

## What it does

- Stores translations in **suffixed meta keys** (`description_ar`, `description_tr`) alongside original ACF fields
- Does NOT touch your existing CPTs, ACF fields, theme files, or any other plugin
- Provides a tabbed translation UI on post edit screens
- Imports/exports translations via CSV in your existing column format
- Serves translated content through a REST API for Next.js headless frontends

## What it does NOT do

- Register CPTs (your theme handles that)
- Manage ACF fields (your existing field groups stay untouched)
- Auto-translate anything
- Modify the WordPress frontend (you're headless)

## Important development rule

- Do not edit `tableqr-menu-multi/` from this plugin workflow.
- Treat `tableqr-menu-multi/` as reference-only when tracing UI/rendering behavior.
- All translation logic and fixes must be implemented inside `tableqr-translations` plugin files.
- If a theme-file change becomes mandatory to complete a fix, ask for explicit permission before editing `tableqr-menu-multi/`.

## Storage approach

| Content | Default language (en) | Arabic (ar) | Turkish (tr) |
|---|---|---|---|
| Post title | `post_title` column | `tqt_post_title_ar` meta | `tqt_post_title_tr` meta |
| Description | `description` meta | `description_ar` meta | `description_tr` meta |
| Price name (variant) | `prices_0_price_name` | `prices_0_price_name_ar` | `prices_0_price_name_tr` |
| Term name | `wp_terms.name` | `tqt_name_ar` term meta | `tqt_name_tr` term meta |
| Options | `options_name` | `options_name_ar` | `options_name_tr` |

Existing code that reads `get_field('description')` still works — it gets the default language value untouched.

## Translatable fields

### menu_item CPT
- Title, Description, Custom Label
- Variant price names (inside the prices repeater)
- Taxonomy terms: Menu Category, Menu Section

### branch CPT
- Title, Card Description

### menu_promo CPT
- Title

### Options pages
- Restaurant Name, Business Name, Business Description, Serves Cuisine
- Address, Open Days, Street Address, City, Region
- Tax Notice, Google Feedback ID
- Link in Bio Title, Link in Bio Description

## CSV Format

Matches your existing format exactly:

```csv
title,menu_category,menu_section,title_ar,menu_category_ar,menu_section_ar,title_tr,...,description,description_ar,description_tr,price,calorie,prices_1_price_name,prices_1_price_name_ar,...
```

## REST API

```
GET /wp-json/tqt/v1/languages
GET /wp-json/tqt/v1/menu?lang=ar&category=breakfast
GET /wp-json/tqt/v1/menu/{id}?lang=ar
GET /wp-json/tqt/v1/terms?lang=ar&taxonomy=menu_category
GET /wp-json/tqt/v1/options?lang=ar
GET /wp-json/tqt/v1/branches?lang=ar
```

## Next.js usage

```typescript
const API = process.env.NEXT_PUBLIC_WP_URL;

export async function getMenu(lang: string) {
  const res = await fetch(`${API}/wp-json/tqt/v1/menu?lang=${lang}`, {
    next: { revalidate: 60 },
  });
  return res.json();
}

export async function getLanguages() {
  const res = await fetch(`${API}/wp-json/tqt/v1/languages`);
  return res.json();
}
```

## WP-CLI

```bash
wp tqt import menu.csv --post-type=menu_item
wp tqt import menu.csv --dry-run
wp tqt languages
wp tqt stats --post-type=menu_item
```

## Adding new translatable fields

Edit `includes/settings.php` → `tqt_translatable_fields()`. Or use the filter:

```php
add_filter( 'tqt_translatable_fields', function( $fields ) {
    $fields['menu_item']['meta_fields'][] = [
        'name' => 'my_new_field',
        'type' => 'text',
        'label' => 'My New Field',
    ];
    return $fields;
});
```

## Multisite

Network activate. Each site has its own language settings.

## Plugin structure

```
tableqr-translations/
├── tableqr-translations.php     # Bootstrap
├── includes/
│   ├── settings.php             # Language config + field registry
│   ├── storage.php              # Read/write translation values
│   ├── admin-settings.php       # Settings page + term translations page
│   ├── admin-tabs.php           # Tabbed metabox on post edit screens
│   ├── admin-columns.php        # Language badges on post list
│   ├── csv-import.php           # CSV importer
│   ├── csv-export.php           # CSV exporter
│   ├── rest-api.php             # Headless REST endpoints
│   └── cli.php                  # WP-CLI commands
└── README.md
```
