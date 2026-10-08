<?php
/** Shared, expiration-based eBay US category matching. */
defined('ABSPATH') || exit;

function tcs_ebay_tree_name($name) {
    $name = strtolower(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
    $name = str_replace('&', ' and ', $name);
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_diff($words ?: [], ['and']));
    sort($words, SORT_STRING);
    return implode(' ', $words);
}

function tcs_ebay_tree_index() {
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $key = 'tcs_ebay_us_tree_index_v1';
    $cached = get_transient($key);
    if (is_array($cached) && !empty($cached['nodes']) && !empty($cached['names'])) {
        return $memo = $cached;
    }
    if (get_transient($key . '_failure')) {
        return $memo = new WP_Error('tcs_tree_retry', 'Category download is temporarily unavailable.');
    }
    $lock = $key . '_lock';
    $owner = TCS_Ebay_API_Client::acquire_lock($lock, 120);
    if (!$owner) {
        return $memo = new WP_Error('tcs_tree_busy', 'Category download is already running.');
    }
    try {
        $cached = get_transient($key);
        if (is_array($cached) && !empty($cached['nodes']) && !empty($cached['names'])) {
            return $memo = $cached;
        }
        $token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();
        if (!$token) {
            throw new RuntimeException('No eBay access token.');
        }
        $args = [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30,
        ];
        $response = TCS_Ebay_API_Client::request('taxonomy',
            'https://api.ebay.com/commerce/taxonomy/v1/get_default_category_tree_id?marketplace_id=EBAY_US', $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException('Cannot retrieve the eBay US category tree ID.');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $tree_id = is_array($body) ? (string) ($body['categoryTreeId'] ?? '') : '';
        if (!ctype_digit($tree_id)) {
            throw new RuntimeException('Invalid eBay category tree ID.');
        }
        // WordPress HTTP transport negotiates and decompresses gzip.
        $response = TCS_Ebay_API_Client::request('taxonomy',
            'https://api.ebay.com/commerce/taxonomy/v1/category_tree/' . $tree_id, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException('Cannot download the eBay category tree.');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !empty($body['errors']) || !is_array($body['rootCategoryNode'] ?? null)) {
            throw new RuntimeException('Invalid eBay category tree response.');
        }
        $index = ['nodes' => [], 'names' => []];
        $walk = static function ($node, $parent) use (&$walk, &$index) {
            $id = (string) ($node['category']['categoryId'] ?? '');
            $name = (string) ($node['category']['categoryName'] ?? '');
            if (!ctype_digit($id) || $name === '') {
                throw new RuntimeException('Malformed category in eBay tree.');
            }
            $index['nodes'][$id] = ['name' => $name, 'parent' => $parent];
            if ($parent !== null) {
                $index['names'][tcs_ebay_tree_name($name)][] = $id;
            }
            foreach ($node['childCategoryTreeNodes'] ?? [] as $child) {
                $walk($child, $id);
            }
        };
        $walk($body['rootCategoryNode'], null);
        if (count($index['nodes']) < 2 || !$index['names']) {
            throw new RuntimeException('Empty eBay category tree.');
        }
        set_transient($key, $index, 7 * DAY_IN_SECONDS);
        delete_transient($key . '_failure');
        return $memo = $index;
    } catch (Throwable $error) {
        set_transient($key . '_failure', true, MINUTE_IN_SECONDS);
        error_log('[TCS EBAY CATEGORY TREE] ' . $error->getMessage());
        return $memo = new WP_Error('tcs_tree_failure', $error->getMessage());
    } finally {
        TCS_Ebay_API_Client::release_lock($lock, $owner);
    }
}

/** Recover the structured WP trail instead of splitting category names on spaces. */
function tcs_ebay_wp_search_trail($phrase) {
    static $trails = null;
    if ($trails === null) {
        $trails = [];
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $ids = array_reverse(get_ancestors($term->term_id, 'category', 'taxonomy'));
                $ids[] = $term->term_id;
                $names = [];
                foreach ($ids as $id) {
                    $part = get_term($id, 'category');
                    if (!$part instanceof WP_Term || $part->slug === 'auctions') {
                        continue;
                    }
                    // This site's Collectibles root is a grouping container.
                    if ($part->slug === 'collectibles' && count($ids) > 1 && (int) $part->parent === 0) {
                        continue;
                    }
                    $names[] = html_entity_decode($part->name, ENT_QUOTES, 'UTF-8');
                }
                $key = trim(tcs_ebay_category_search_phrase($term));
                // Do not guess when separate WP branches produce identical phrases.
                if (isset($trails[$key]) && $trails[$key] !== $names) {
                    $trails[$key] = false;
                } elseif (!array_key_exists($key, $trails)) {
                    $trails[$key] = $names;
                }
            }
        }
    }
    $phrase = trim((string) $phrase);
    if (isset($trails[$phrase]) && is_array($trails[$phrase])) {
        return $trails[$phrase];
    }
    $term = get_category_by_slug($phrase);
    if ($term instanceof WP_Term) {
        $key = trim(tcs_ebay_category_search_phrase($term));
        if (isset($trails[$key]) && is_array($trails[$key])) {
            return $trails[$key];
        }
    }
    return [str_replace('-', ' ', $phrase)];
}

function tcs_ebay_resolve_search($phrase, $keywords = '') {
    $index = tcs_ebay_tree_index();
    if (is_wp_error($index)) {
        return $index;
    }
    $selected = null;
    $remaining = [];
    $trail = tcs_ebay_wp_search_trail($phrase);
    foreach ($trail as $name) {
        $candidates = $index['names'][tcs_ebay_tree_name($name)] ?? [];
        if ($selected !== null) {
            $candidates = array_values(array_filter($candidates, static function ($id) use ($index, $selected) {
                $cursor = (string) $id;
                while (isset($index['nodes'][$cursor])) {
                    if ($cursor === (string) $selected) {
                        return true;
                    }
                    $parent = $index['nodes'][$cursor]['parent'];
                    if ($parent === null) {
                        break;
                    }
                    $cursor = (string) $parent;
                }
                return false;
            }));
        }
        if (count($candidates) === 1) {
            $selected = (string) reset($candidates);
        } else {
            $remaining[] = $name;
        }
    }
    // Preserve custom keyword input unless it duplicates a WP trail component.
    $keyword_name = tcs_ebay_tree_name($keywords);
    $trail_names = array_map('tcs_ebay_tree_name', $trail);
    if (trim((string) $keywords) !== '' && !in_array($keyword_name, $trail_names, true)) {
        $remaining[] = trim($keywords);
    }
    $result = [
        'id' => $selected,
        'name' => $selected !== null ? $index['nodes'][$selected]['name'] : null,
        'keywords' => trim(implode(' ', array_unique($remaining))),
    ];
    if ($result['id'] === null && $result['keywords'] === '') {
        return new WP_Error('tcs_category_unresolved', 'No category or keywords could be resolved.');
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[TCS EBAY CATEGORY MATCH] ' . wp_json_encode([
            'phrase' => $phrase,
            'category_id' => $result['id'],
            'category_name' => $result['name'],
            'keywords' => $result['keywords'],
        ]));
    }
    return $result;
}
