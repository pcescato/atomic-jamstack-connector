# Front Matter Template Examples

The Atomic Jamstack Connector now supports custom Front Matter templates, allowing you to use any Hugo theme format (YAML or TOML).

## How It Works

The plugin provides a textarea in **Settings > Hugo Configuration** where you can define your Front Matter template using placeholders.

### Available Placeholders

- `{{title}}` - Post title
- `{{date}}` - Post date (ISO 8601 format)
- `{{author}}` - Post author display name
- `{{slug}}` - Post slug/name
- `{{image_avif}}` - Path to AVIF version of featured image
- `{{image_webp}}` - Path to WebP version of featured image
- `{{image_original}}` - Original featured image URL

## Example Templates

### Default (YAML with Cover Image)

```yaml
---
title: "{{title}}"
date: {{date}}
author: "{{author}}"
cover:
  image: "{{image_avif}}"
  alt: "{{title}}"
---
```

### PaperMod Theme (YAML)

```yaml
---
title: "{{title}}"
date: {{date}}
author: "{{author}}"
tags: []
categories: []
cover:
  image: "{{image_webp}}"
  alt: "{{title}}"
  relative: false
ShowToc: true
TocOpen: false
---
```

### TOML Format

```toml
+++
title = "{{title}}"
date = {{date}}
author = "{{author}}"
slug = "{{slug}}"
[cover]
image = "{{image_avif}}"
alt = "{{title}}"
+++
```

### Minimal YAML

```yaml
---
title: "{{title}}"
date: {{date}}
---
```

### Extended YAML with All Features

```yaml
---
title: "{{title}}"
date: {{date}}
author: "{{author}}"
slug: "{{slug}}"
images:
  - "{{image_avif}}"
  - "{{image_webp}}"
featuredImage: "{{image_original}}"
draft: false
---
```

## Important Notes

1. **Include delimiters**: You must include `---` for YAML or `+++` for TOML in your template
2. **Escaping**: The plugin handles proper escaping of values
3. **Empty values**: If a placeholder has no value (e.g., no featured image), it will be replaced with an empty string
4. **Security**: Templates are sanitized on save to prevent XSS attacks
5. **Whitespace**: Preserve proper indentation for YAML structures

## Testing Your Template

After saving your custom template:

1. Sync a post with a featured image
2. Check the generated Markdown in your GitHub repository
3. Verify the Front Matter is valid YAML/TOML
4. Test with your Hugo theme to ensure compatibility

## Troubleshooting

**Q: My Front Matter is broken**
- Ensure you included delimiters (`---` or `+++`)
- Check YAML/TOML syntax (indentation matters in YAML)
- Verify quotes around string values

**Q: Images not showing**
- The plugin generates AVIF and WebP versions automatically
- Use `{{image_avif}}` for best performance
- Use `{{image_webp}}` for better browser compatibility
- Use `{{image_original}}` for the original WordPress URL

**Q: Can I add custom fields?**
- Yes! You can add any static values directly in the template
- For dynamic custom fields, contact plugin support for future enhancements
