=== Sbek Companion ===
Contributors: helmab
Tags: khmer, bilingual, news, newsletter, share buttons
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Khmer / English URLs, linked translations, news fields, share buttons, newsletter, SEO tags and demo content for the Sbek news theme.

== Description ==

Sbek Companion adds the features of the [Sbek](https://github.com/HELMAB/sbek) news theme that belong in a plugin. It works only while Sbek (or a child theme of it) is active; with any other theme it does nothing but show a notice.

**Two languages without a translation plugin**

* `/` is Khmer and `/en/` is English. Theme, plugin and WordPress strings follow the URL.
* Link each post to its translation in the *Translation* box, or create the English draft in one click (title, content, image, categories, tags and news details are copied).
* English site title, tagline, footer texts and category names. A language switcher in the menu bar and hreflang links.

**News details on every post**

* Video URL (embedded above the article), source name and link, *Sponsored* and *Breaking news* flags, and a trending score for the "Popular" lists.

**For readers**

* Share buttons: Facebook, Telegram, X, email, copy link and the device's share sheet.
* Double opt-in newsletter signup in the footer, with confirmation and unsubscribe links in the subscriber's language, and a CSV export.

**For the site owner**

* Meta descriptions, Open Graph tags and Organization / NewsArticle / BreadcrumbList structured data (switched off automatically when Yoast SEO, Rank Math, SEOPress or All in One SEO is active).
* Google Analytics 4 or Tag Manager, and custom header / footer code.
* A branded login screen and a login lockout after 5 failed attempts. Optional switches on the *Security* tab add security headers, turn off XML-RPC and the file editors, and hide the user list from visitors (all off until you turn them on).
* Caching of the theme's homepage queries, refreshed automatically when content changes.
* Demo content (about 140 Khmer and English posts with images, menus and widgets) that can be removed again exactly.

All settings are on the theme's *Appearance > Sbek Theme* page: the *English*, *Integrations*, *Security* and *Tools* tabs, and cards on *General* and *Contact & Footer*.

== Installation ==

1. Install and activate the Sbek theme.
2. Upload the plugin through Plugins > Add New Plugin > Upload Plugin, and activate it.
3. Open *Appearance > Sbek Theme*. Assign the English menus under *Appearance > Menus*.

== Frequently Asked Questions ==

= Does it work with other themes? =

No. It adds features to the Sbek theme through the theme's own hooks and settings page.

= Why doesn't the newsletter send emails? =

Subscribers must confirm by email, so your site needs to be able to send mail. Use an SMTP plugin if your host doesn't send it.

= What happens to my data if I deactivate the plugin? =

Nothing is deleted. Languages, translations, news details, subscribers and settings stay in the database and come back when the plugin is activated again.

= And if I delete it? =

Deleting the plugin under *Plugins* removes everything it stored: settings, newsletter subscribers, news details, translation links, the post languages, English category names and its caches. Export the subscribers (CSV) first if you want to keep them. Imported demo posts and images stay, as ordinary content; remove them under *Tools* before deleting the plugin if you don't want them. The theme's own settings are not touched.

== External services ==

The plugin connects to other services only in these cases:

* **Google Analytics 4 / Google Tag Manager** — only after you enter an ID under *Integrations*. Every public page then loads Google's script, which sends page-view data to Google. [Terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy). Mention this in your site's privacy policy.
* **Share buttons** — plain links to Facebook, Telegram and X. Nothing is sent until a visitor clicks one; the visitor's browser then opens that service with the page's address and title. [Facebook privacy](https://www.facebook.com/privacy/policy/), [Telegram privacy](https://telegram.org/privacy), [X privacy](https://x.com/en/privacy).
* **Video URLs** — embedded with WordPress's own oEmbed, which asks the video site (YouTube, Vimeo…) for the player when the post is displayed.
* **Demo content with site settings** — when you import it, WordPress downloads the Khmer language pack from WordPress.org.

== Privacy ==

The newsletter stores each subscriber's email address, language, status and signup date on your site (in the *Subscribers* list), together with a one-way hash of the IP address the signup came from; the same hash is also kept for an hour to limit repeated signups. Nothing else is collected unless you enable analytics or add custom code. The login lockout (on by default, under *Security*) stores a hash of the IP address for 15 minutes after a failed login.

== Changelog ==

= 1.0.0 - 2026-11-03 =
* Initial release: the language, news, sharing, newsletter, SEO, analytics, login and demo features that used to be part of the Sbek theme.

== Copyright ==

Sbek Companion, (C) 2026 Hel Mab
Distributed under the terms of the GNU GPL v2 or later.

* assets/images/demo-logo.png, Copyright 2026 Hel Mab, GPLv2 or later. Demo post images are drawn by the importer with the PHP GD extension.
