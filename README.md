# TableQR Translations — Digital Menu Translation Plugin

A lightweight, zero-dependency multilingual translation system built for headless WordPress + Next.js digital menu services.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- ACF Pro (for repeater fields)
- WordPress Multisite (supported, not required)

## Plugin Structure

```
tableqr-translations/
├── tableqr-translations.php          # Main plugin file (settings, REST API, CSV import/export, admin columns)
├── includes/
│   ├── cpt-registration.php     # digital_menu custom post type
│   └── acf-fields.php           # ACF field groups (translations repeater + base fields)
├── assets/
│   ├── css/
│   │   └── tqt-tabs.css         # Tabbed editor UI styles
│   └── js/
│       └── tqt-tabs.js          # Tabbed editor UI logic
└── README.md
```

## Installation

1. Upload the `tableqr-translations` folder to `/wp-content/plugins/`
2. For multisite: Network Activate the plugin
3. For single site: Activate normally
4. Go to **TableQR Translations → Settings** to configure languages

## Settings

### Default Language
The primary language for the site. Shown first in editor tabs. Used as fallback when a translation is missing.

### Enabled Languages
Check any languages your site needs. Only checked languages appear as editor tabs.

### Custom Languages
Add languages not in the built-in list (e.g., regional dialects, less common languages). Provide a short code and label.

### Fallback Behaviour
When a requested language translation doesn't exist:
- **Show default language** — falls back to the default language content
- **Hide item** — item is excluded from API response entirely
- **Show empty** — returns the item with blank translated fields

### CSV Delimiter
Choose comma, semicolon, or tab for your CSV files.

## CSV Import Format

```csv
item_id,category,price,image,is_available,sort_order,lang,name,description
001,mains,25.00,chicken.jpg,1,10,en,Grilled Chicken,Juicy grilled chicken breast
001,mains,25.00,chicken.jpg,1,10,ar,دجاج مشوي,صدر دجاج مشوي طري
001,mains,25.00,chicken.jpg,1,10,zh,烤鸡,多汁的烤鸡胸肉
002,desserts,12.00,cake.jpg,1,20,en,Chocolate Cake,Rich dark chocolate cake
002,desserts,12.00,cake.jpg,1,20,ar,كيكة الشوكولاتة,كيكة شوكولاتة داكنة غنية
```

**Rules:**
- Columns before `lang` are non-translatable (same values per item across all rows)
- `item_id` is your unique identifier — used to match existing posts for updates
- One row per language per item
- Add any extra translated columns after `description` — they'll be stored automatically
- Use the Dry Run checkbox to validate before importing

## REST API Endpoints

### GET `/wp-json/tableqr/v1/languages`
Returns available languages for the site.

```json
[
  { "code": "en", "label": "English", "is_default": true, "is_rtl": false },
  { "code": "ar", "label": "Arabic", "is_default": false, "is_rtl": true }
]
```

### GET `/wp-json/tableqr/v1/menu?lang=ar&category=mains`
Returns menu items in the requested language.

**Parameters:**
- `lang` — Language code (defaults to site default language)
- `category` — Filter by category
- `per_page` — Items per page (default 100, max 500)
- `page` — Page number

**Response headers:**
- `X-WP-Total` — Total items
- `X-WP-TotalPages` — Total pages
- `X-TQT-Language` — Language served

### GET `/wp-json/tableqr/v1/menu/{id}?lang=ar`
Returns a single menu item.

## Next.js Integration Example

```typescript
// lib/api.ts
const WP_API = process.env.NEXT_PUBLIC_WP_API_URL; // e.g. https://gcc.example.com

export async function getMenuItems(lang: string, category?: string) {
  const params = new URLSearchParams({ lang });
  if (category) params.set('category', category);

  const res = await fetch(`${WP_API}/wp-json/tableqr/v1/menu?${params}`, {
    next: { revalidate: 60 }, // ISR: revalidate every 60 seconds
  });

  if (!res.ok) throw new Error('Failed to fetch menu');
  return res.json();
}

export async function getLanguages() {
  const res = await fetch(`${WP_API}/wp-json/tableqr/v1/languages`, {
    next: { revalidate: 3600 },
  });
  return res.json();
}
```

## WP-CLI Commands

```bash
# Import a CSV file
wp tqt import /path/to/menu.csv

# List active languages
wp tqt list-languages
```

## Adding Custom Translated Fields

Edit `includes/acf-fields.php` and add entries to the `$translated_sub_fields` array. The CSV importer will automatically pick up any new columns that match the field names (without the `tqt_` prefix).

## Multisite Notes

- Network activate to make available across all sites
- Each site has its own language settings (different restaurants may need different languages)
- The REST API is per-site, so your Next.js frontend hits the specific subsite URL
