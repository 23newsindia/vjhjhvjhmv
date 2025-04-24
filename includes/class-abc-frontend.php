<?php


class ABC_Frontend {
    private static $parsed_shortcodes = array();

    public function __construct() {
        add_shortcode('abc_banner', array($this, 'render_banner_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        global $post;
        if ($post && has_shortcode($post->post_content, 'abc_banner')) {
            wp_enqueue_style('abc-carousel-css', ABC_PLUGIN_URL . 'assets/css/carousel.css', array(), ABC_VERSION);
            wp_enqueue_script('abc-carousel-js', ABC_PLUGIN_URL . 'assets/js/carousel.js', array(), ABC_VERSION, true);
            $this->preload_first_slide();
        }
    }

    private function preload_first_slide() {
        global $post;
        if (!is_object($post) || !isset($post->post_content)) return;
        
        preg_match_all('/\[abc_banner\s+slug=["\']([^"\']+)["\']/', $post->post_content, $matches);
        if (empty($matches[1])) return;
        
        $first_slider_slug = $matches[1][0];
        $banner = ABC_Cache::get_banner($first_slider_slug);
        
        if ($banner && !empty($banner->slides)) {
            $slides = maybe_unserialize($banner->slides);
            if (!empty($slides[0]['image'])) {
                add_action('wp_head', function() use ($slides) {
                    echo '<link rel="preload" as="image" href="'.esc_url($slides[0]['image']).'" fetchpriority="high" importance="high">';
                }, 1);
                
                if (count($slides) > 1 && !empty($slides[1]['image'])) {
                    add_action('wp_head', function() use ($slides) {
                        echo '<link rel="prefetch" as="image" href="'.esc_url($slides[1]['image']).'">';
                    }, 2);
                }
            }
        }
    }

    public function render_banner_shortcode($atts) {
        $atts = shortcode_atts(array(
            'slug' => '',
            'class' => '' // Added from second version
        ), $atts);
        
        if (empty($atts['slug'])) {
            return '<p class="abc-error">Please specify a banner slug</p>';
        }
        
        // Check memory cache first
        if (isset(self::$parsed_shortcodes[$atts['slug']])) {
            return self::$parsed_shortcodes[$atts['slug']];
        }
        
        // Then check persistent cache
        $cache_key = $this->get_shortcode_cache_key($atts);
        $html = wp_cache_get($cache_key, 'abc_shortcodes');
        
        if (false === $html) {
            $banner = ABC_Cache::get_banner($atts['slug']);
            
            if (!$banner) {
                return '<p class="abc-error">Banner not found</p>';
            }
            
            $slides = maybe_unserialize($banner->slides);
            $settings = maybe_unserialize($banner->settings);
            
            if (empty($slides) || !is_array($slides)) {
                return '<p class="abc-error">No slides found for this banner</p>';
            }

            // Filter out invalid slides
            $slides = array_filter($slides, function($slide) {
                return !empty($slide['image']) && $slide['image'] !== 'null';
            });
            
            if (empty($slides)) {
                return '<p class="abc-error">No valid slides found for this banner</p>';
            }
            
            $default_settings = json_decode(get_option('abc_default_settings'), true);
            $settings = wp_parse_args($settings, $default_settings);
            
            ob_start();
            $this->render_banner_html($banner, $atts, $slides, $settings);
            $html = ob_get_clean();
            
            // Cache in memory
            self::$parsed_shortcodes[$atts['slug']] = $html;
            
            // Cache persistently
            wp_cache_set($cache_key, $html, 'abc_shortcodes', HOUR_IN_SECONDS);
        }
        
        return $html;
    }

    /**
     * Generate unique cache key for shortcode instance
     */
    private function get_shortcode_cache_key($atts) {
        return 'abc_shortcode_' . md5(serialize($atts));
    }

    /**
     * Clear all caches when banner is updated
     */
    public static function clear_shortcode_cache($slug = '') {
        // Clear memory cache
        if ($slug) {
            unset(self::$parsed_shortcodes[$slug]);
        } else {
            self::$parsed_shortcodes = array();
        }
        
        // Clear persistent cache
        if ($slug) {
            global $wpdb;
            $cache_pattern = 'abc_shortcode_%' . md5($slug) . '%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} 
                     WHERE option_name LIKE %s",
                    $cache_pattern
                )
            );
        } else {
            wp_cache_flush();
        }
    }

    /**
     * Render banner HTML (uncached)
     */
    private function render_banner_html($banner, $atts, $slides, $settings) {
        $additional_class = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';
        ?>
        <div class="abc-banner-carousel<?php echo $additional_class; ?>" data-settings="<?php echo esc_attr(json_encode($settings)); ?>">
            <div class="abc-carousel-wrapper">
                <div class="abc-carousel-inner">
                    <?php foreach ($slides as $index => $slide) : 
                        $image_data = $this->get_optimized_image_data($slide['image'], $index + 1, $slide['alt_text']);
                    ?>
                        <div class="abc-slide" data-index="<?php echo $index; ?>">
                            <?php if (!empty($slide['link'])) : ?>
                                <a href="<?php echo esc_url($slide['link']); ?>" class="abc-slide-link">
                            <?php endif; ?>

                            <div class="abc-slide-image-container">
                                <img src="<?php echo esc_attr($image_data['placeholder']); ?>" 
                                     data-src="<?php echo esc_url($image_data['url']); ?>"
                                     alt="<?php echo esc_attr($image_data['alt']); ?>"
                                     loading="<?php echo esc_attr($image_data['loading']); ?>"
                                     class="abc-slide-image <?php echo $index === 0 ? 'abc-first-slide customFade-active' : ''; ?>"
                                     width="<?php echo esc_attr($image_data['width']); ?>"
                                     height="<?php echo esc_attr($image_data['height']); ?>"
                                />
                            </div>

                            <?php if (!empty($slide['title'])) : ?>
                                <div class="abc-slide-title"><?php echo esc_html($slide['title']); ?></div>
                            <?php endif; ?>

                            <?php if (!empty($slide['link'])) : ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($settings['show_arrows']) : ?>
                    <button class="abc-carousel-prev" aria-label="Previous slide">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/>
                        </svg>
                    </button>
                    <button class="abc-carousel-next" aria-label="Next slide">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_optimized_image_data($image_url, $position = 1, $alt_text = '') {
        if (empty($image_url) || $image_url === 'null') {
            return array(
                'url' => '',
                'placeholder' => '',
                'width' => '480',
                'height' => '460',
                'alt' => '',
                'loading' => 'lazy',
                'fetchpriority' => 'auto',
                'decoding' => 'async'
            );
        }

        return array(
            'url' => esc_url($image_url),
            'placeholder' => $this->get_placeholder_image($image_url),
            'width' => '480',
            'height' => '460',
            'alt' => sanitize_text_field($alt_text),
            'loading' => $position <= 2 ? 'eager' : 'lazy',
            'fetchpriority' => $position === 1 ? 'high' : 'auto',
            'decoding' => $position === 1 ? 'sync' : 'async'
        );
    }

    private function get_placeholder_image($original_url) {
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" viewBox="0 0 1 1"></svg>'
        );
    }
}