# CLAUDE.md

This is the **Sbek Companion** plugin, the plugin half of the Sbek theme (`../../themes/sbek`). Read the theme's `CLAUDE.md` first: it documents both halves, the theme hooks this plugin relies on, and which features belong on which side.

Plugin-specific rules:
- Text domain `sbek-companion`; prefixes `sbek_` / `_sbek_` / `Sbek_` (shared with the theme — check the theme doesn't define a function before adding one).
- The plugin loads before the theme. Functions the theme also defines as fallbacks (`inc/language.php` there) must stay wrapped in `function_exists()`, because on the activation request the plugin is included *after* the theme.
- Pluggable functions (`wp_safe_redirect()`, `wp_get_current_user()`…) don't exist yet while plugin files are being included — use them in hooks (`plugins_loaded` or later).
- Theme functions (`sbek_site_setting()`, `sbek_icon()`, `sbek_section_open()`…) may only be called inside hooks, never at include time.
- Keep `readme.txt` (WordPress.org plugin format) in sync: the External services and Privacy sections must list every outside request and every stored personal datum.
