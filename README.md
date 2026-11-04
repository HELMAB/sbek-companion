# Sbek Companion

The non-design half of the [Sbek](https://github.com/HELMAB/sbek) news theme, as a plugin — so the theme can live in the WordPress.org theme directory, which doesn't allow these features in themes:

- `/` Khmer + `/en/` English URLs, the `language` taxonomy, linked translations, English texts and category names (`includes/i18n.php`, `includes/admin/translation.php`, `includes/term-names.php`)
- News details post meta: video, source, sponsored, breaking, trending (`includes/admin/post-meta.php`, `includes/frontend/article.php`)
- Share buttons (`includes/frontend/article.php`, `assets/js/share.js`)
- Double opt-in newsletter (`includes/frontend/newsletter.php`)
- SEO tags and JSON-LD, analytics and custom scripts (`includes/frontend/seo.php`, `includes/frontend/integrations.php`)
- Security hardening, login lockout and the branded login screen (`includes/security.php`, `includes/admin/login.php`)
- Query caching for the theme's blocks (`includes/cache.php`)
- Dashboard setup checklist and demo content with `wp sbek demo` (`includes/admin/`, `includes/demo/`, `includes/cli/`)

It only loads while Sbek (or a child theme) is active and plugs into the theme's hooks and its Appearance → Sbek Theme page. The full architecture, including the list of theme hooks, is in the theme's `CLAUDE.md`.

## Development

```bash
# translations (domain sbek-companion)
wp i18n make-pot . languages/sbek-companion.pot --domain=sbek-companion --exclude=dist
msgmerge -N -U languages/sbek-companion-km.po languages/sbek-companion.pot
wp i18n make-mo languages/sbek-companion-km.po languages/ && wp i18n make-php languages/sbek-companion-km.po languages/

bin/release.sh   # checks + dist/sbek-companion-<version>.zip
```

License: GPLv2 or later.
