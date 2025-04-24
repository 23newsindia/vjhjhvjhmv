<?php
class ABC_Cache {
    const CACHE_GROUP = 'abc_banners';
    const CACHE_EXPIRY = 12 * HOUR_IN_SECONDS;
    const TRANSIENT_EXPIRY = 24 * HOUR_IN_SECONDS;
    
    public function __construct() {
        // Clear cache when banner is updated/deleted
        add_action('abc_banner_updated', [$this, 'clear_banner_cache']);
        add_action('abc_banner_deleted', [$this, 'clear_banner_cache']);

        // Clear cache when posts/pages using the shortcode are updated
        add_action('save_post', [$this, 'clear_post_banners_cache']);
        add_action('delete_post', [$this, 'clear_post_banners_cache']);

        // Clear cache on plugin/theme updates
        add_action('upgrader_process_complete', [$this, 'clear_all_cache'], 10, 2);

        // Clear cache if WordPress core options change
        add_action('update_option', [$this, 'maybe_clear_cache_on_option_change'], 10, 3);
    }
    
    public function get_cache_key($type, $identifier, $context = '') {
        $key_parts = ['abc', $type, $identifier];
        
        if (!empty($context)) {
            $key_parts[] = md5(serialize($context));
        }
        
        return implode('_', $key_parts) . '_' . ABC_VERSION;
    }

    public function get_banner($slug, $force_fresh = false) {
        $cache_key = $this->get_cache_key('banner', $slug);
        
        // 1. Check in-memory cache first
        $banner = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if ($banner !== false && !$force_fresh) {
            return $banner;
        }
        
        // 2. Fallback to transient (longer cache)
        $transient_key = 'abc_banner_' . md5($slug);
        $banner = get_transient($transient_key);
        
        if ($banner !== false && !$force_fresh) {
            // Store in object cache for faster subsequent access
            wp_cache_set($cache_key, $banner, self::CACHE_GROUP, self::CACHE_EXPIRY);
            return $banner;
        }
        
        // 3. Fetch fresh from DB if no cache exists
        $banner = ABC_DB::get_banner($slug);
        
        if ($banner) {
            $this->set_banner_cache($slug, $banner);
        }
        
        return $banner;
    }

    public function set_banner_cache($slug, $banner) {
        $cache_key = $this->get_cache_key('banner', $slug);
        $transient_key = 'abc_banner_' . md5($slug);
        
        wp_cache_set($cache_key, $banner, self::CACHE_GROUP, self::CACHE_EXPIRY);
        set_transient($transient_key, $banner, self::TRANSIENT_EXPIRY);
    }

    public function clear_banner_cache($banner_id_or_slug) {
        if (is_numeric($banner_id_or_slug)) {
            $banner = ABC_DB::get_banner_by_id($banner_id_or_slug);
            $slug = $banner ? $banner->slug : null;
        } else {
            $slug = $banner_id_or_slug;
        }
        
        if (!$slug) return;
        
        $cache_key = $this->get_cache_key('banner', $slug);
        $transient_key = 'abc_banner_' . md5($slug);
        
        wp_cache_delete($cache_key, self::CACHE_GROUP);
        delete_transient($transient_key);
        
        // Also clear shortcode cache if used
        ABC_Frontend::clear_shortcode_cache($slug);
    }

    public function maybe_clear_cache_on_option_change($option, $old_value, $new_value) {
        $critical_options = [
            'home', 'siteurl', 'permalink_structure', 'rewrite_rules'
        ];
        
        if (in_array($option, $critical_options)) {
            $this->clear_all_cache();
        }
    }

    public function clear_post_banners_cache($post_id) {
        $post = get_post($post_id);
        if (!$post || !has_shortcode($post->post_content, 'abc_banner')) {
            return;
        }

        preg_match_all('/\[abc_banner\s+slug=["\']([^"\']+)["\']/', $post->post_content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $slug) {
                $this->clear_banner_cache($slug);
            }
        }
    }

    public function preload_banners() {
        $banners = ABC_DB::get_all_banners();
        
        foreach ($banners as $banner) {
            $this->set_banner_cache($banner->slug, $banner);
        }
    }
  
  public static function preload_banners() {
        $banners = ABC_DB::get_all_banners();
        
        foreach ($banners as $banner) {
            self::set_banner_cache($banner->slug, $banner);
        }
    }
    
     public static function set_banner_cache($slug, $banner) {
        $cache_key = self::get_cache_key('banner', $slug);
        $transient_key = 'abc_banner_' . md5($slug);
        
        wp_cache_set($cache_key, $banner, self::CACHE_GROUP, self::CACHE_EXPIRY);
        set_transient($transient_key, $banner, self::TRANSIENT_EXPIRY);
    }

    /**
     * Generate cache key (static version)
     */
    public static function get_cache_key($type, $identifier, $context = '') {
        $key_parts = ['abc', $type, $identifier];
        
        if (!empty($context)) {
            $key_parts[] = md5(serialize($context));
        }
        
        return implode('_', $key_parts) . '_' . ABC_VERSION;
    }

  
   public static function register_hooks() {
        $instance = new self(); // Create instance to trigger __construct()
        return $instance;
    }

    public function clear_all_cache() {
        global $wpdb;
        
        // Clear object cache
        wp_cache_flush_group(self::CACHE_GROUP);
        
        // Delete all transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_abc_banner_%' 
             OR option_name LIKE '_transient_timeout_abc_banner_%'"
        );
        
        // Clear shortcode cache
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE '_abc_shortcode_cache_%'"
        );
    }
}