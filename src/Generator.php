<?php

namespace SearchIndex;

class Generator {

    public function build() : void {
        $this->buildSearchIndex();

        if ( $this->isResourceTagExportEnabled() ) {
            $this->buildResourceTagIndex();
        } else {
            $this->deleteResourceTagIndex();
        }
    }

    private function buildSearchIndex() : void {
        $items = $this->collectItems();
        $payload = [
            'version' => '1',
            'generatedAt' => gmdate( 'c' ),
            'items' => $items,
        ];

        $json = \wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { return; }

        $file = $this->path()->search_index;
        if ( ! $this->ensureDirectory( dirname( $file ) ) ) { return; }

        file_put_contents( $file, $json );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectItems() : array {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $ids = \get_posts( $args );
        if ( ! is_array( $ids ) ) { return []; }

        $items = [];
        foreach ( $ids as $post_id ) {
            $items[] = $this->mapPost( (int) $post_id );
        }
        $items = array_values( array_filter( $items, function( $it ) {
            return is_array( $it ) && isset( $it['id'] );
        } ) );
        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPost( int $post_id ) : array {
        $post = \get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
            return [];
        }

        $title = \wp_strip_all_tags( \get_the_title( $post_id ) );
        $content_mode = \get_option( 'search_index_content_mode', 'excerpt' );
        $truncate = (int) \get_option( 'search_index_truncate_words', 40 );
        $content = $content_mode === 'full' ? $this->makeFull( $post, $truncate ) : $this->makeExcerpt( $post, $truncate );
        $permalink = \get_permalink( $post_id );
        $root_relative = is_string( $permalink ) ? parse_url( $permalink, PHP_URL_PATH ) : '';
        $url_field = ( is_string( $root_relative ) && $root_relative !== '' ) ? $root_relative : '/';
        $slug = $post->post_name;

        $cats = $this->termSlugs( $post_id, 'category' );
        $tags = $this->termSlugs( $post_id, 'post_tag' );

        return [
            'id' => $post_id,
            'slug' => is_string( $slug ) ? $slug : '',
            'title' => is_string( $title ) ? $title : '',
            'content' => $content,
            'url' => $url_field,
            'categories' => $cats,
            'tags' => $tags,
        ];
    }

    private function makeExcerpt( $post, int $truncate_words ) : string {
        if ( ! is_object( $post ) ) { return ''; }
        $raw = isset( $post->post_excerpt ) && $post->post_excerpt !== '' ? $post->post_excerpt : ( isset( $post->post_content ) ? $post->post_content : '' );
        $raw = \strip_shortcodes( $raw );
        $raw = $this->applyUserStripRegex( $raw );
        $text = \wp_strip_all_tags( $raw );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( $truncate_words > 0 ) {
            $words = explode( ' ', $text );
            if ( count( $words ) > $truncate_words ) {
                $words = array_slice( $words, 0, $truncate_words );
                $text = implode( ' ', $words ) . '…';
            }
        }
        return $text;
    }

    private function makeFull( $post, int $truncate_words ) : string {
        if ( ! is_object( $post ) ) { return ''; }
        $raw = isset( $post->post_content ) ? $post->post_content : '';
        $raw = \strip_shortcodes( $raw );
        $raw = $this->applyUserStripRegex( $raw );
        $text = \wp_strip_all_tags( $raw );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( $truncate_words > 0 ) {
            $words = explode( ' ', $text );
            if ( count( $words ) > $truncate_words ) {
                $words = array_slice( $words, 0, $truncate_words );
                $text = implode( ' ', $words ) . '…';
            }
        }
        return $text;
    }

    private function applyUserStripRegex( string $text ) : string {
        $pattern = (string) \get_option( 'search_index_strip_regex', '' );
        if ( $pattern !== '' ) {
            $text = (string) @preg_replace( $pattern, '', $text );
        }
        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function termSlugs( int $post_id, string $taxonomy ) : array {
        $terms = \get_the_terms( $post_id, $taxonomy );
        if ( ! is_array( $terms ) ) { return []; }
        return array_values( array_map( function( $t ) { return is_object( $t ) && isset( $t->slug ) ? $t->slug : ''; }, $terms ) );
    }

    private function buildResourceTagIndex() : void {
        $posts = $this->collectResourceTagPosts();
        $authors = $this->collectResourceTagAuthors();

        $payload = [
            'version' => '1',
            'generatedAt' => gmdate( 'c' ),
            'posts' => $posts,
            'authors' => $authors,
        ];

        $json = \wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { return; }

        $file = $this->path()->resource_tags;
        if ( ! $this->ensureDirectory( dirname( $file ) ) ) { return; }

        file_put_contents( $file, $json );
    }

    private function deleteResourceTagIndex() : void {
        $file = $this->path()->resource_tags;
        if ( \file_exists( $file ) ) {
            @\unlink( $file );
        }
    }

    private function ensureDirectory( string $dir ) : bool {
        if ( is_dir( $dir ) ) {
            return true;
        }
        return \wp_mkdir_p( $dir );
    }

    private function isResourceTagExportEnabled() : bool {
        return (bool) \get_option( 'search_index_enable_resource_tags', false );
    }

    private function collectResourceTagPosts() : array {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $ids = \get_posts( $args );
        if ( ! is_array( $ids ) ) { return []; }

        $resource_settings = $this->resourceTagSettings();
        $category_settings = $this->categorySettings();
        $profile_settings = $this->authorProfileSettings();

        $posts = [];
        foreach ( $ids as $post_id ) {
            $mapped = $this->mapResourceTagPost( (int) $post_id, $resource_settings, $category_settings, $profile_settings );
            if ( ! empty( $mapped ) ) {
                $posts[] = $mapped;
            }
        }

        return $posts;
    }

    /**
     * @param array<string, array<string, mixed>> $resource_settings
     * @param array<string, array<string, mixed>> $category_settings
     * @param array<int, array<string, mixed>>    $profile_settings
     * @return array<string, mixed>
     */
    private function mapResourceTagPost( int $post_id, array $resource_settings, array $category_settings, array $profile_settings ) : array {
        $post = \get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
            return [];
        }

        $category_details = $this->resolvePrimaryCategory( $post_id, $category_settings );
        $resource_tag_details = $this->resolveResourceTag( $post_id, $resource_settings );
        $tag_details = $this->resolveStandardTag( $post_id, $resource_settings );
        $author_details = $this->resolveAuthorDetails( $post->post_author, $profile_settings );

        $summary = $this->buildPostSummary( $post );
        $permalink = (string) \get_permalink( $post_id );
        $post_date = $this->formatPostDate( $post );
        $reading_time = $this->calculateReadingTime( $post );
        $image_markup = $this->buildImageMarkup( $post_id );

        return [
            'post_ID' => $post_id,
            'post_title' => (string) $post->post_title,
            'post_description' => $summary,
            'post_paramlink' => $this->normaliseUrl( $permalink ),
            'post_date' => $post_date,
            'post_min_read' => $reading_time,
            'post_image' => $image_markup,
            'post_category' => $category_details['name'],
            'post_category_background_color' => $category_details['color'],
            'post_category_url' => $category_details['url'],
            'post_author_ID' => $author_details['id'],
            'post_author_name' => $author_details['name'],
            'post_author_url' => $author_details['url'],
            'post_resource_tag' => $resource_tag_details['name'],
            'post_resource_tag_slug' => $resource_tag_details['slug'],
            'post_resource_tag_bg_color' => $resource_tag_details['color'],
            'post_resource_tag_icon' => $resource_tag_details['icon'],
            'post_resource_tag_url' => $resource_tag_details['url'],
            'post_tag' => $tag_details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePrimaryCategory( int $post_id, array $category_settings ) : array {
        $defaults = [
            'name' => '',
            'color' => '',
            'url' => '',
        ];

        $categories = \get_the_category( $post_id );
        if ( ! is_array( $categories ) || count( $categories ) === 0 ) {
            return $defaults;
        }

        $category = $categories[0];
        $slug = isset( $category->slug ) ? $category->slug : '';

        $color = '';
        if ( $slug !== '' && isset( $category_settings[ $slug ] ) && is_array( $category_settings[ $slug ] ) ) {
            $color = isset( $category_settings[ $slug ]['color'] ) ? (string) $category_settings[ $slug ]['color'] : '';
        }

        return [
            'name' => isset( $category->name ) ? (string) $category->name : '',
            'color' => $color,
            'url' => $slug !== '' ? '/blog/category/' . $slug : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveResourceTag( int $post_id, array $resource_settings ) : array {
        $defaults = [
            'name' => '',
            'slug' => '',
            'color' => '',
            'icon' => '',
            'url' => '',
        ];

        $tags = \get_the_tags( $post_id );
        if ( ! is_array( $tags ) || count( $tags ) === 0 ) {
            return $defaults;
        }

        foreach ( $tags as $tag ) {
            if ( ! isset( $tag->slug ) ) {
                continue;
            }
            $slug = $tag->slug;
            if ( isset( $resource_settings[ $slug ] ) && is_array( $resource_settings[ $slug ] ) ) {
                $settings = $resource_settings[ $slug ];
                $color = isset( $settings['color'] ) ? (string) $settings['color'] : '';
                $icon = isset( $settings['icon'] ) ? (string) $settings['icon'] : '';
                $name = isset( $settings['name'] ) ? (string) $settings['name'] : ( isset( $tag->name ) ? (string) $tag->name : '' );

                return [
                    'name' => $name,
                    'slug' => $slug,
                    'color' => $color,
                    'icon' => $this->normaliseUrlIfAsset( $icon ),
                    'url' => '/blog/tag/' . $slug,
                ];
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveStandardTag( int $post_id, array $resource_settings ) : array {
        $defaults = [
            'tag_name' => '',
            'tag_slug' => '',
            'tag_id' => '',
            'tag_url' => '',
        ];

        $tags = \get_the_tags( $post_id );
        if ( ! is_array( $tags ) || count( $tags ) === 0 ) {
            return $defaults;
        }

        foreach ( $tags as $tag ) {
            if ( ! isset( $tag->slug ) ) {
                continue;
            }
            $slug = $tag->slug;
            if ( isset( $resource_settings[ $slug ] ) ) {
                continue;
            }

            return [
                'tag_name' => isset( $tag->name ) ? (string) $tag->name : '',
                'tag_slug' => $slug,
                'tag_id' => isset( $tag->term_id ) ? (int) $tag->term_id : '',
                'tag_url' => '/blog/tag/' . $slug,
            ];
        }

        return $defaults;
    }

    /**
     * @param array<int, array<string, mixed>> $profile_settings
     * @return array<string, mixed>
     */
    private function resolveAuthorDetails( int $author_id, array $profile_settings ) : array {
        $user = \get_user_by( 'id', $author_id );

        $name = $user ? (string) $user->display_name : '';
        $nicename = $user ? (string) $user->user_nicename : '';

        if ( isset( $profile_settings[ $author_id ] ) && is_array( $profile_settings[ $author_id ] ) ) {
            $profile = $profile_settings[ $author_id ];
            if ( isset( $profile['display_name'] ) && $profile['display_name'] !== '' ) {
                $name = (string) $profile['display_name'];
            }
        }

        return [
            'id' => $author_id,
            'name' => $name,
            'url' => $nicename !== '' ? '/blog/author/' . $nicename : '',
        ];
    }

    private function buildPostSummary( \WP_Post $post ) : string {
        $content = isset( $post->post_content ) ? (string) $post->post_content : '';

        if ( function_exists( 'strip_shortcode_from_content' ) && isset( $GLOBALS['filtered_shortcodes'] ) ) {
            $content = strip_shortcode_from_content( $content, $GLOBALS['filtered_shortcodes'] );
        } else {
            $content = \strip_shortcodes( $content );
        }

        $content = \wp_strip_all_tags( $content );
        $trimmed = \wp_trim_words( $content, 100, '' );
        return trim( preg_replace( '/\s+/', ' ', $trimmed ) );
    }

    private function formatPostDate( \WP_Post $post ) : string {
        $date = isset( $post->post_date ) ? $post->post_date : '';
        if ( $date === '' ) {
            return '';
        }

        return \date_i18n( 'M d, Y', \strtotime( $date ) );
    }

    private function calculateReadingTime( \WP_Post $post ) : string {
        $content = isset( $post->post_content ) ? (string) $post->post_content : '';
        $word_count = str_word_count( \wp_strip_all_tags( $content ) );
        $minutes = (int) \ceil( $word_count / 200 );
        if ( $minutes < 1 ) {
            $minutes = 1;
        }
        return $minutes . ' min read';
    }

    private function buildImageMarkup( int $post_id ) : string {
        $thumb_id = \get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return '';
        }

        $alt = (string) \get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
        $html = \get_the_post_thumbnail( $post_id, 'post-thumbnail', [
            'alt' => trim( $alt ),
            'loading' => 'lazy',
        ] );

        if ( ! is_string( $html ) || $html === '' ) {
            return '';
        }

        return is_string( $html ) ? $html : '';
    }

    private function collectResourceTagAuthors() : array {
        $authors = [];

        $default_pic = $this->defaultAuthorPicture();
        if ( $default_pic !== '' ) {
            $authors[] = [ 'default_author_pic' => $default_pic ];
        }

        $profile_settings = $this->authorProfileSettings();

        $blog_authors = \get_users( [
            'who' => 'authors',
            'has_published_posts' => true,
        ] );

        foreach ( $blog_authors as $user ) {
            $pic = $this->resolveAuthorPicture( $user, $profile_settings, $default_pic );
            $authors[] = [
                'author_ID' => (int) $user->ID,
                'author_pic' => $pic,
            ];
        }

        return $authors;
    }

    /**
     * @param array<int, array<string, mixed>> $profile_settings
     */
    private function resolveAuthorPicture( \WP_User $user, array $profile_settings, string $default_pic ) : string {
        $author_id = (int) $user->ID;
        if ( isset( $profile_settings[ $author_id ] ) && is_array( $profile_settings[ $author_id ] ) ) {
            $profile = $profile_settings[ $author_id ];
            if ( isset( $profile['profile_pic'] ) && $profile['profile_pic'] !== '' ) {
                $pic = (string) $profile['profile_pic'];
                return $this->normaliseUrlIfAsset( $pic );
            }
        }

        return $default_pic;
    }

    private function defaultAuthorPicture() : string {
        $uri = \get_theme_file_uri( 'assets/images/default-profile-picture.webp' );
        if ( ! is_string( $uri ) || $uri === '' ) {
            return '';
        }
        return $this->normaliseUrl( $uri );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resourceTagSettings() : array {
        $settings = \get_option( 'yoti-blog-resource-tag-settings', [] );
        return is_array( $settings ) ? $settings : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function categorySettings() : array {
        $settings = \get_option( 'yoti-blog-categories-settings', [] );
        return is_array( $settings ) ? $settings : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function authorProfileSettings() : array {
        $settings = \get_option( 'user-profile-details', [] );
        return is_array( $settings ) ? $settings : [];
    }

    private function normaliseUrl( string $url ) : string {
        if ( $url === '' ) {
            return '';
        }
        if ( strpos( $url, 'data:' ) === 0 ) {
            return $url;
        }

        $relative = \wp_make_link_relative( $url );
        return is_string( $relative ) && $relative !== '' ? $relative : $url;
    }

    private function normaliseUrlIfAsset( string $url ) : string {
        if ( $url === '' || strpos( $url, 'data:' ) === 0 ) {
            return $url;
        }
        return $this->normaliseUrl( $url );
    }

    private function path() : object {
        $upload_dir = \wp_upload_dir();
        $base = \trailingslashit( $upload_dir['basedir'] ) . 'search';

        return (object) [
            'base' => $base,
            'search_index' => \trailingslashit( $base ) . 'index.json',
            'resource_tags' => \trailingslashit( $base ) . 'resource-tags.json',
        ];
    }
}


