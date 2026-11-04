#!/usr/bin/env bash
# Build a WordPress.org-ready zip of the plugin: dist/sbek-companion-<version>.zip
# Then submit it at https://wordpress.org/plugins/developers/add/ (first
# release) — updates later go through the plugin's SVN repository.
set -euo pipefail

plugin_dir="$(cd "$(dirname "$0")/.." && pwd)"
slug="$(basename "$plugin_dir")"
dist="$plugin_dir/dist"
main="$plugin_dir/$slug.php"

excludes=( .git bin dist README.md CLAUDE.md .gitignore .DS_Store Thumbs.db .idea .vscode '*.log' '*.l10n.php' )

red=$'\e[31m'; green=$'\e[32m'; bold=$'\e[1m'; reset=$'\e[0m'
errors=0
fail() { echo "${red}✗ $*${reset}"; errors=$((errors + 1)); }
ok()   { echo "${green}✓${reset} $*"; }

cd "$plugin_dir"
for cmd in php zip rsync unzip; do
	command -v "$cmd" >/dev/null || { echo "${red}Missing command: $cmd${reset}"; exit 1; }
done

header() { sed -n "s/^[[:space:]*]*$1:[[:space:]]*//p" "$2" | head -1 | tr -d '\r'; }
version="$(header 'Version' "$main")"
echo "${bold}Releasing $slug $version${reset}"

php_bad=0
while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null 2>&1 || { fail "PHP syntax error in $f"; php_bad=1; }
	head -c 5 "$f" | grep -q '<?php' || { fail "$f does not start with <?php"; php_bad=1; }
done < <(find . -name '*.php' -not -path './dist/*' -not -path './.git/*' -print0)
(( php_bad )) || ok "PHP syntax"

[[ "$(header 'Stable tag' readme.txt)" == "$version" ]] || fail "readme.txt Stable tag is not $version"
grep -q "define( 'SBEK_COMPANION_VERSION', '$version' );" "$main" || fail "SBEK_COMPANION_VERSION is not $version"
grep -q "^= $version" readme.txt || fail "readme.txt Changelog has no entry for $version"
for key in 'Requires at least' 'Requires PHP'; do
	[[ "$(header "$key" "$main")" == "$(header "$key" readme.txt)" ]] || fail "'$key' differs between $slug.php and readme.txt"
done
(( errors )) || ok "Version $version everywhere"

if command -v wp >/dev/null; then
	tmp="$(mktemp -d)"
	for po in languages/*.po; do
		base="$(basename "${po%.po}")"
		wp i18n make-mo "$po" "$tmp/$base.mo" --quiet 2>/dev/null
		cmp -s "languages/$base.mo" "$tmp/$base.mo" || fail "languages/$base.mo is out of date (wp i18n make-mo $po languages/)"
	done
	rm -rf "$tmp"
	(( errors )) || ok "Translations compiled"
fi

(( errors == 0 )) || { echo "${red}${bold}$errors error(s); no zip made.${reset}"; exit 1; }

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
rsync_args=(-a)
for e in "${excludes[@]}"; do rsync_args+=(--exclude="$e"); done
rsync "${rsync_args[@]}" "$plugin_dir/" "$stage/$slug/"

mkdir -p "$dist"
zip_file="$dist/$slug-$version.zip"
rm -f "$zip_file"
(cd "$stage" && zip -rqX "$zip_file" "$slug")

listing="$(unzip -Z1 "$zip_file")"
for must in "$slug/$slug.php" "$slug/readme.txt"; do
	grep -qxF "$must" <<<"$listing" || { fail "Zip is missing $must"; exit 1; }
done

echo
ok "${bold}$zip_file${reset} ($(grep -vc '/$' <<<"$listing") files, $(du -h "$zip_file" | cut -f1))"
echo
echo "Next: check it with the Plugin Check plugin, then submit at https://wordpress.org/plugins/developers/add/"
