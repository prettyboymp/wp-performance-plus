<?php
/*
Plugin Name: Performance Plus
Plugin URI: http://vocecommunications.com
Description: Performance Tweaks for WordPress
Author: Voce Connect
Contributors: prettyboymp, jeffstieler
Version: 2.0
Author URI: http://vocecommunications.com
*/

class WP_Performance_Plus {

	public function initialize() {
		register_activation_hook(basename(__FILE__), array($this, 'alter_tables'));
		register_deactivation_hook(basename(__FILE__), array($this, 'restore_tables'));

		add_filter('wp_get_object_terms', array($this, 'cache_object_terms'), 10, 4);
		add_action('set_object_terms', array($this, 'clear_object_terms_cache'), 10, 4);
		add_filter('posts_request', array($this, 'filter_post_request'), 99, 3);
		add_filter('found_posts_query', array($this, 'filter_found_posts_query'), 10, 99, 2);
		add_filter('found_posts', array($this, 'filter_found_posts'), 10, 2);

		add_filter('posts_where', array($this, 'clause_filter_10964'), 100, 2);
		add_filter('posts_orderby', array($this, 'clause_filter_10964'), 100, 2);
		add_filter('posts_groupby', array($this, 'clause_filter_10964'), 100, 2);
		add_action('wp_insert_post', array($this, 'update_extra_cols_10964'), 100, 2);
	}

	public function alter_tables() {
		global $wpdb;

		if ($tables_altered = get_option('pp_tr_table_altered')) {
			return;
		}

		$alterations = array(
			"ALTER TABLE {$wpdb->term_relationships} ADD COLUMN post_type varchar(20) DEFAULT NULL;",
			"ALTER TABLE {$wpdb->term_relationships} ADD COLUMN post_date datetime DEFAULT '0000-00-00 00:00:00';",
			"ALTER TABLE {$wpdb->term_relationships} ADD COLUMN post_status varchar(20) DEFAULT NULL;",
			"ALTER TABLE {$wpdb->term_relationships} ADD KEY date_status (post_date, post_status);",
			"UPDATE {$wpdb->term_relationships} wtr, {$wpdb->posts} wpp
				SET wtr.post_type = wpp.post_type,
				wtr.post_status = wpp.post_status,
				wtr.post_date = wpp.post_date
				WHERE wtr.object_id = wpp.ID"
		);

		foreach ($alterations as $alter) {
			$wpdb->query($alter);
		}

		update_option('pp_tr_table_altered', true);
	}

	public function restore_tables() {
		global $wpdb;

		$tables_altered = get_option('pp_tr_table_altered');
		if (!$tables_altered) {
			return;
		}

		$alterations = array(
			"ALTER TABLE {$wpdb->term_relationships} DROP COLUMN post_type;",
			"ALTER TABLE {$wpdb->term_relationships} DROP COLUMN post_date;",
			"ALTER TABLE {$wpdb->term_relationships} DROP COLUMN post_status;",
			"ALTER TABLE {$wpdb->term_relationships} DROP KEY date_status;"
		);

		foreach ($alterations as $alter) {
			$wpdb->query($alter);
		}

		delete_option('pp_tr_table_altered');
	}

	public function clause_filter_10964($clause, $wp_query) {
		global $wpdb;

		if (!empty($wp_query->tax_query->queries)) {

			$needles = array(
				"{$wpdb->posts}.post_type",
				"{$wpdb->posts}.post_status",
				"{$wpdb->posts}.post_date",
				"{$wpdb->posts}.ID",
			);

			$replacements = array(
				"{$wpdb->term_relationships}.post_type",
				"{$wpdb->term_relationships}.post_status",
				"{$wpdb->term_relationships}.post_date",
				"{$wpdb->term_relationships}.object_id",
			);

			$clause = str_replace($needles, $replacements, $clause);
		}
		return $clause;
	}

	public function update_extra_cols_10964($post_id, $post) {

		global $wpdb;

		if (!wp_is_post_revision($post)) {

			$data = array(
				'post_type' => $post->post_type,
				'post_status' => $post->post_status,
				'post_date' => $post->post_date
			);

			$where = array(
				'object_id' => $post_id
			);

			$wpdb->update($wpdb->term_relationships, $data, $where);

		}

	}

	/**
	 * Caches the object terms if it is a single object and taxonomy
	 *
	 * @param array $terms
	 * @param $object_ids comma seperated list of object ids
	 * @param string $taxonomies comma separated list of taxonomies
	 * @param array $args
	 * @return array
	 */
	function cache_object_terms($terms, $object_ids, $taxonomies, $args) {
		$object_ids = explode(', ', $object_ids);
		$taxonomies = explode(', ', $taxonomies);
		$exact_args = array ('orderby' => 'name', 'order' => 'ASC', 'fields' => 'all');
		if(count($object_ids) == 1 && count($taxonomies) == 1 && $args == $exact_args) {
			$taxonomy = str_replace("'", '', $taxonomies[0]);
			wp_cache_set($object_ids[0], $terms, "{$taxonomy}_relationships");
		}
		return $terms;
	}

	function clear_object_terms_cache($object_id, $terms, $tt_ids, $taxonomy) {
		wp_cache_delete($object_id, "{$taxonomy}_relationships");
	}

	//caching of SQL_CALC_FOUND_ROWS
	function filter_post_request($request, $wp_query, $is_quick_query = false) {
		if(false !== strpos($request, 'SQL_CALC_FOUND_ROWS') && false !== ($found_rows = $this->get_found_rows_cache($request)) ) {
			$wp_query->cached_found_rows = $found_rows;
			$request = str_replace('SQL_CALC_FOUND_ROWS', '', $request);
		}
		return $request;
	}

	function cache_sql_calc_found_rows_get_sql_key($sql) {
		return md5(str_replace('SQL_CALC_FOUND_ROWS', '', $sql));
	}

	function filter_found_posts_query($request, $wp_query) {
		if(isset($wp_query->cached_found_rows)) {
			return 'SELECT 0';
		}
		return $request;
	}

	function filter_found_posts($found_posts, $wp_query) {
		if(isset($wp_query->cached_found_rows)) {
			$found_posts = $wp_query->cached_found_rows;
		} elseif(false !== strpos($wp_query->request, 'SQL_CALC_FOUND_ROWS')) {
			$cached_found_rows = wp_cache_get('pp_found_rows_cach');
			if(!is_array($cached_found_rows)) $cached_found_rows = array();
			$cache_key = $this->cache_sql_calc_found_rows_get_sql_key($wp_query->request);
			$cached_found_rows[$cache_key] = $found_posts;
			wp_cache_set('pp_found_rows_cach', $cached_found_rows);
		}
		return $found_posts;
	}

	function get_found_rows_cache($sql) {
		$cached_found_rows = wp_cache_get('pp_found_rows_cach');
		if(!is_array($cached_found_rows)) return false;

		$cache_key = $this->cache_sql_calc_found_rows_get_sql_key($sql);

		return isset($cached_found_rows[$cache_key]) ? intval($cached_found_rows[$cache_key]) : false;
	}

}

$wpp = new WP_Performance_Plus();
$wpp->initialize();