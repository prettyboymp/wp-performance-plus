/*
Plugin Name: Performance Plus
Plugin URI: http://vocecommunications.com
Description: Performance Tweaks for WordPress
Author: prettyboymp
Version: 1.0
Author URI: http://vocecommunications
*/

/**
 * Caches the object terms if it is a single object and taxonomy
 *
 * @param array $terms
 * @param $object_ids comma seperated list of object ids
 * @param string $taxonomies comma separated list of taxonomies
 * @param array $args
 * @return array
 */
function pp_cache_object_terms($terms, $object_ids, $taxonomies, $args) {
	$object_ids = explode(', ', $object_ids);
	$taxonomies = explode(', ', $taxonomies);
	$exact_args = array ('orderby' => 'name', 'order' => 'ASC', 'fields' => 'all');
	if(count($object_ids) == 1 && count($taxonomies) == 1 && $args == $exact_args) {
		$taxonomy = str_replace("'", '', $taxonomies[0]);
		wp_cache_set($object_ids[0], $terms, "{$taxonomy}_relationships");
	}
	return $terms;
}
add_filter('wp_get_object_terms', 'pp_cache_object_terms', 10, 4);

function pp_clear_object_terms_cache($object_id, $terms, $tt_ids, $taxonomy) {
	wp_cache_delete($object_id, "{$taxonomy}_relationships");
}
add_action('set_object_terms', 'pp_clear_object_terms_cache', 10, 4);





//caching of SQL_CALC_FOUND_ROWS
function pp_filter_post_request($request, $wp_query, $is_quick_query) {
	if(false !== strpos($request, 'SQL_CALC_FOUND_ROWS') && false !== ($found_rows = pp_get_found_rows_cache($request)) ) {
		$wp_query->cached_found_rows = $found_rows;
		$request = str_replace('SQL_CALC_FOUND_ROWS', '', $request);
	}
	return $request;
}
add_filter('posts_request', 'pp_filter_post_request', 99, 3);

function pp_cache_sql_calc_found_rows_get_sql_key($sql) {
	return md5(str_replace('SQL_CALC_FOUND_ROWS', '', $sql));
}

function pp_filter_found_posts_query($request, $wp_query) {
	if(isset($wp_query->cached_found_rows)) {
		return 'SELECT 0';
	}
	return $request;
}
add_filter('found_posts_query', 'pp_filter_found_posts_query', 10, 99, 2);

function pp_filter_found_posts($found_posts, $wp_query) {
	if(isset($wp_query->cached_found_rows)) {
		$found_posts = $wp_query->cached_found_rows;
	} elseif(false !== strpos($wp_query->request, 'SQL_CALC_FOUND_ROWS')) {
		$cached_found_rows = wp_cache_get('pp_found_rows_cach');
		if(!is_array($cached_found_rows)) $cached_found_rows = array();
		$cache_key = pp_cache_sql_calc_found_rows_get_sql_key($wp_query->request);
		$cached_found_rows[$cache_key] = $found_posts;
		wp_cache_set('pp_found_rows_cach', $cached_found_rows);
	}
	return $found_posts;
}
add_filter('found_posts', 'pp_filter_found_posts', 10, 2);

function pp_get_found_rows_cache($sql) {
	$cached_found_rows = wp_cache_get('pp_found_rows_cach');
	if(!is_array($cached_found_rows)) return false;

	$cache_key = pp_cache_sql_calc_found_rows_get_sql_key($sql);

	return isset($cached_found_rows[$cache_key]) ? intval($cached_found_rows[$cache_key]) : false;
}

