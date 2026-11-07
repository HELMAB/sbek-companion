<?php
/**
 * Meta description, Open Graph / Twitter tags and schema.org JSON-LD.
 *
 * Steps aside when a dedicated SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO)
 * is active, so pages never carry two competing sets of tags.
 *
 * @package sbek-companion
 */

defined( 'ABSPATH' ) || exit;

function sbek_seo_plugin_active(): bool {
    return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
}

/**
 * Title, description, URL, image and type of the current view for the meta
 * description and Open Graph tags; null for views that shouldn't be shared
 * (search, 404, paged archives keep their first page's tags out).
 *
 * @return array{title:string,description:string,url:string,image:string,type:string}|null
 */
function sbek_seo_context(): ?array {
    $default_image = sbek_companion_image_url( 'og_image', 'large' );
    $site_text     = trim( sbek_site_text( 'description' ) );
    $site_text     = '' !== $site_text ? $site_text : (string) get_bloginfo( 'description' );

    if ( is_singular() ) {
        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return null;
        }

        return [
            'title'       => wp_strip_all_tags( get_the_title( $post ) ),
            'description' => sbek_seo_description( $post ),
            'url'         => (string) get_permalink( $post ),
            'image'       => has_post_thumbnail( $post ) ? (string) wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'large' ) : $default_image,
            'type'        => 'post' === $post->post_type ? 'article' : 'website',
        ];
    }

    if ( is_front_page() || ( is_home() && ! is_paged() ) ) {
        return [
            'title'       => (string) get_bloginfo( 'name' ),
            'description' => $site_text,
            'url'         => sbek_home_url(),
            'image'       => $default_image,
            'type'        => 'website',
        ];
    }

    $object = get_queried_object();

    if ( $object instanceof WP_Term && in_array( $object->taxonomy, array( 'category', 'post_tag' ), true ) ) {
        $link = get_term_link( $object );
        $text = sbek_plain_text( sbek_term_description( $object ) );

        return [
            'title'       => $object->name,
            'description' => '' !== $text ? $text : $site_text,
            'url'         => is_wp_error( $link ) ? '' : (string) $link,
            'image'       => $default_image,
            'type'        => 'website',
        ];
    }

    if ( $object instanceof WP_User ) {
        return [
            'title'       => $object->display_name,
            'description' => sbek_plain_text( (string) get_the_author_meta( 'description', $object->ID ) ),
            'url'         => get_author_posts_url( $object->ID ),
            'image'       => $default_image,
            'type'        => 'profile',
        ];
    }

    return null;
}

/**
 * Tag-free, single-line text capped at 200 characters (Khmer has no spaces
 * between words, so word trimming can't shorten it).
 */
function sbek_plain_text( string $text ): string {
    $text = trim( preg_replace( '/[\s\x{200B}]+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );

    return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 199 ) . '…' : $text;
}

/**
 * Meta description + Open Graph / Twitter tags for posts, pages, the home
 * page, category/tag archives and author pages.
 */
function sbek_seo_meta_tags(): void {
    if ( sbek_seo_plugin_active() ) {
        return;
    }

    $context = sbek_seo_context();
    if ( ! $context ) {
        return;
    }

    if ( '' !== $context['description'] ) {
        echo '<meta name="description" content="' . esc_attr( $context['description'] ) . '" />' . "\n";
    }

    echo '<!-- Open Graph Meta Tags -->' . "\n";
    echo '<meta property="og:title" content="' . esc_attr( $context['title'] ) . '" />' . "\n";
    echo '<meta property="og:type" content="' . esc_attr( $context['type'] ) . '" />' . "\n";
    if ( '' !== $context['url'] ) {
        echo '<meta property="og:url" content="' . esc_url( $context['url'] ) . '" />' . "\n";
    }
    echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', get_bloginfo( 'language' ) ) ) . '" />' . "\n";

    if ( '' !== $context['image'] ) {
        echo '<meta property="og:image" content="' . esc_url( $context['image'] ) . '" />' . "\n";
    }
    if ( '' !== $context['description'] ) {
        echo '<meta property="og:description" content="' . esc_attr( $context['description'] ) . '" />' . "\n";
    }

    if ( 'article' === $context['type'] ) {
        echo '<meta property="article:published_time" content="' . esc_attr( (string) get_the_date( DATE_W3C, get_queried_object() ) ) . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( (string) get_the_modified_date( DATE_W3C, get_queried_object() ) ) . '" />' . "\n";
    }

    echo '<meta name="twitter:card" content="' . ( '' !== $context['image'] ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
}
add_action( 'wp_head', 'sbek_seo_meta_tags' );

/**
 * Shared excerpt/description text for OG tags and NewsArticle JSON-LD.
 */
function sbek_seo_description( WP_Post $post ): string {
    return sbek_plain_text( has_excerpt( $post->ID ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 30 ) );
}

/**
 * Print a schema.org JSON-LD block through core's inline script API.
 * JSON_HEX_TAG encodes < and > so no value can close the tag early.
 *
 * @param array<string,mixed> $data Structured data.
 */
function sbek_print_jsonld( array $data ): void {
    wp_print_inline_script_tag(
        (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        [ 'type' => 'application/ld+json' ]
    );
}

/**
 * Output NewsMediaOrganization JSON-LD structured data (site-wide).
 *
 * Built from the values collected in Sbek Theme: description, logo,
 * socials (sameAs), phones (contactPoint), and lat/lng (location, this
 * plugin's setting).
 */
function sbek_organization_jsonld() {
    if ( ! is_front_page() || sbek_seo_plugin_active() ) {
        return; // Once, on the home page, is what search engines need.
    }

    // Theme settings (contact, socials) plus this plugin's coordinates.
    $settings = array_merge( sbek_site_setting(), array_intersect_key( sbek_companion_setting(), array_flip( array( 'lat', 'lng' ) ) ) );

    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'NewsMediaOrganization',
        'name'     => get_bloginfo( 'name' ),
        'url'      => sbek_home_url(),
    ];

    $description = sbek_site_text( 'description' );
    if ( '' !== $description ) {
        $data['description'] = $description;
    }

    $logo = sbek_companion_logo_url( 'full' );
    if ( $logo ) {
        $data['logo'] = $logo;
    }

    // Social profiles → sameAs.
    if ( ! empty( $settings['socials'] ) ) {
        $same_as = array_values( array_filter( wp_list_pluck( $settings['socials'], 'url' ) ) );
        if ( $same_as ) {
            $data['sameAs'] = $same_as;
        }
    }

    // Phone numbers → contactPoint.
    if ( ! empty( $settings['phones'] ) ) {
        $contacts = [];
        foreach ( $settings['phones'] as $phone ) {
            if ( empty( $phone['number'] ) ) {
                continue;
            }
            $contact = [
                '@type'     => 'ContactPoint',
                'telephone' => $phone['number'],
            ];
            if ( ! empty( $phone['label'] ) ) {
                $contact['contactType'] = $phone['label'];
            }
            $contacts[] = $contact;
        }
        if ( $contacts ) {
            $data['contactPoint'] = $contacts;
        }
    }

    // Coordinates → location/geo.
    if ( '' !== $settings['lat'] && '' !== $settings['lng'] ) {
        $data['location'] = [
            '@type' => 'Place',
            'geo'   => [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $settings['lat'],
                'longitude' => $settings['lng'],
            ],
        ];
    }

    sbek_print_jsonld( $data );
}
add_action( 'wp_head', 'sbek_organization_jsonld' );

/**
 * Output NewsArticle JSON-LD structured data on single posts.
 *
 * This is what Google News / Discover crawlers key off — headline, images,
 * publish/modified dates, author, and a publisher block matching the
 * NewsMediaOrganization data above.
 */
function sbek_article_jsonld() {
    if ( ! is_singular( 'post' ) || sbek_seo_plugin_active() ) {
        return;
    }

    global $post;

    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
        'inLanguage'       => sbek_languages()[ sbek_lang() ]['hreflang'],
        'description'      => sbek_seo_description( $post ),
        'datePublished'    => get_the_date( 'c', $post ),
        'dateModified'     => get_the_modified_date( 'c', $post ),
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => get_permalink( $post ),
        ],
        'author'           => [
            '@type' => 'Person',
            'name'  => get_the_author_meta( 'display_name', $post->post_author ),
        ],
        'publisher'        => [
            '@type' => 'NewsMediaOrganization',
            'name'  => get_bloginfo( 'name' ),
        ],
    ];

    $logo = sbek_companion_logo_url( 'full' );
    if ( $logo ) {
        $data['publisher']['logo'] = [
            '@type' => 'ImageObject',
            'url'   => $logo,
        ];
    }

    $image_src = has_post_thumbnail( $post->ID )
        ? wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), 'full' )
        : sbek_companion_image_url( 'og_image', 'full' );

    if ( $image_src ) {
        $data['image'] = [ $image_src ];
    }

    $category = sbek_primary_category( $post );
    if ( $category ) {
        $data['articleSection'] = $category->name;
    }

    sbek_print_jsonld( $data );
}
add_action( 'wp_head', 'sbek_article_jsonld' );

/**
 * Output BreadcrumbList JSON-LD on single posts (Home → Section → Article).
 * Built from sbek_breadcrumb_items(), the same trail sbek_breadcrumb() shows
 * above the headline in single.php.
 */
function sbek_breadcrumb_jsonld() {
    if ( ! is_singular( 'post' ) || sbek_seo_plugin_active() ) {
        return;
    }

    $items = [];
    foreach ( sbek_breadcrumb_items( get_queried_object_id() ) as $i => $crumb ) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $crumb['name'],
            'item'     => $crumb['url'],
        ];
    }

    sbek_print_jsonld( [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ] );
}
add_action( 'wp_head', 'sbek_breadcrumb_jsonld' );

/**
 * Point crawlers at every language's sitemap. WordPress core already lists
 * the default-language (Khmer) sitemap on the virtual robots.txt via
 * wp_sitemaps_robots(); this adds the prefixed variants (e.g. /en/), which
 * core has no awareness of. The front-end language pre_get_posts filter in
 * inc/i18n.php already makes wp-sitemap.xml itself language-aware per the
 * active prefix.
 */
function sbek_add_localized_sitemaps_to_robots( $output, $public ) {
    if ( '1' !== (string) $public ) {
        return $output;
    }

    foreach ( array_keys( sbek_languages() ) as $lang ) {
        if ( SBEK_DEFAULT_LANGUAGE === $lang ) {
            continue; // Already added by WordPress core.
        }
        $output .= 'Sitemap: ' . esc_url( home_url( "/{$lang}/wp-sitemap.xml" ) ) . "\n";
    }

    return $output;
}
add_filter( 'robots_txt', 'sbek_add_localized_sitemaps_to_robots', 10, 2 );

