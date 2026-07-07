# Banner Design System

Banner management lives in `Контент -> Банери`. The system keeps content, placement, media, visual style, overlay, colors, and animation as explicit fields instead of free-form CSS.

## Fields

Content:

- `eyebrow` - short label or badge above the title.
- `title`, `subtitle` - main banner copy.
- `button_text`, `button_url` - primary CTA. The button renders only when both fields are filled.
- `secondary_button_text`, `secondary_button_url` - optional secondary CTA. It also requires both fields.

Media and placement:

- `image` - desktop image.
- `mobile_image` - optional mobile image. If empty, desktop image is used on mobile.
- `placement` - `home_hero`, `home_promo`, or `catalog_top`.
- `is_active`, `sort_order`, `starts_at`, `ends_at` - visibility and ordering controls.

Design:

- `style_preset` - quick starting point for the design fields.
- `layout_variant` - `background_image`, `image_right`, `image_left`, `split`, `compact`, `centered`.
- `visual_style` - `clean`, `light`, `dark`, `accent`, `glass`, `gradient`, `outline`.
- `color_scheme` - `auto`, `brand`, `neutral`, `warm`, `cool`, `dark`.
- `text_align`, `content_position`, `vertical_align` - content alignment.
- `height_variant`, `border_radius`, `shadow`, `button_style` - shape and CTA style.
- `image_fit`, `image_position` - media framing.

Overlay, colors, animation:

- `overlay_enabled`, `overlay_style`, `overlay_opacity` keep text readable over images.
- `background_color`, `text_color`, `accent_color` accept safe hex values only.
- `animation_enabled` is off by default. Allowed types are `none`, `fade`, `slide_up`, `slide_left`, `zoom_in`, `soft_parallax`.
- CSS animations respect `prefers-reduced-motion`.

## Presets

- `clean_light` - light, clear banner for neutral informational areas.
- `dark_overlay` - image background with dark overlay, best for hero banners.
- `brand_gradient` - branded gradient and strong CTA.
- `glass_card` - background image with translucent content card.
- `compact_promo` - smaller promo banner for grids.
- `split_product` - text and CTA beside a product/service image.

Presets fill normal design fields. The frontend renders from the fields through whitelisted class mappings, so adding a future preset should not require arbitrary CSS in the database.

## Image Guidelines

Desktop:

- Hero/background: 16:7 to 16:9, minimum 1800px wide.
- Promo cards: 16:10 or 4:3, minimum 1000px wide.
- Split layout: product/object centered with transparent or quiet background when possible.

Mobile:

- Use 4:5 or 1:1 for mobile hero if the desktop crop loses the subject.
- Keep important objects and faces away from the top/bottom 15%.
- Leave a quiet text-safe area when using `background_image`.

Use overlay when text sits on a photo or busy illustration. Prefer `split_product` when the image contains important product detail that should not be darkened.

## Release Note

Banner Design System adds managed visual controls, responsive mobile image fallback, CTA guards, overlay controls, lightweight CSS animation, demo presets, and feature tests while keeping legacy banners renderable with safe defaults.
