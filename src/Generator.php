<?php

namespace SearchIndex;

class Generator {

    public function build() : void {
        $items = $this->collectItems();
        $payload = [
            'version' => '1',
            'generatedAt' => gmdate( 'c' ),
            'items' => $items,
        ];

        $json = \wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { return; }

        $upload_dir = \wp_upload_dir();
        $base = \trailingslashit( $upload_dir['basedir'] ) . 'search';
        if ( ! \wp_mkdir_p( $base ) ) { return; }
        $file = \trailingslashit( $base ) . 'index.json';
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
}


