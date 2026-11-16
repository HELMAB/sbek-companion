# CLAUDE.md

This is the **Sbek Companion** plugin, the plugin half of the Sbek theme (`../../themes/sbek`). Read the theme's `CLAUDE.md` first: it documents both halves, the theme hooks this plugin relies on, and which features belong on which side.

Plugin-specific rules:
- Text domain `sbek-companion`; prefixes `sbek_` / `_sbek_` / `Sbek_` (shared with the theme — check the theme doesn't define a function before adding one).
- Never declare a function the theme also declares, and never wrap a declaration in `function_exists()` (WordPress.org reviewers reject that outside shared libraries). The theme's language helpers in `inc/language.php` call this plugin's `sbek_companion_*()` versions when they exist; use the `sbek_companion_*()` names inside the plugin.
- Pluggable functions (`wp_safe_redirect()`, `wp_get_current_user()`…) don't exist yet while plugin files are being included — use them in hooks (`plugins_loaded` or later).
- Theme functions (`sbek_site_setting()`, `sbek_icon()`, `sbek_section_open()`…) may only be called inside hooks, never at include time.
- Keep `readme.txt` (WordPress.org plugin format) in sync: the External services and Privacy sections must list every outside request and every stored personal datum.
