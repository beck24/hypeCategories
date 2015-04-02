<?php

namespace hypeJunction\Categories\Listeners;

use ElggEntity;
use ElggGroup;
use ElggMenuItem;
use ElggObject;
use ElggSite;
use hypeJunction\Categories\Category;
use hypeJunction\Categories\Config\Config;
use hypeJunction\Categories\Models\Model;
use hypeJunction\Categories\Models\TreeNode;
use hypeJunction\Categories\Services\Router;

/**
 * Plugin hooks service
 */
class PluginHooks {

	private $config;
	private $router;
	private $model;

	/**
	 * Constructor
	 * @param Config $config
	 * @param Router $router
	 */
	public function __construct(Config $config, Router $router, Model $model) {
		$this->config = $config;
		$this->router = $router;
		$this->model = $model;
	}

	/**
	 * Perform tasks on system init
	 * @return void
	 */
	public function init() {
		elgg_register_plugin_hook_handler('entity:url', 'object', array($this, 'handleEntityUrls'));
		elgg_register_plugin_hook_handler('entity:icon:url', 'object', array($this, 'handleEntityIconUrls'));
		elgg_register_plugin_hook_handler('register', 'menu:categories', array($this, 'setupCategoriesMenu'));
		elgg_register_plugin_hook_handler('register', 'menu:category-filter', array($this, 'setupCategoryFilterMenu'));

		if ($this->config->allowsCategoriesInMenu()) {
			elgg_register_plugin_hook_handler('register', 'menu:entity', array($this, 'setupEntityMenu'));
		}
	}

	/**
	 * Filter category object URLs
	 *
	 * @param string $hook   Equals 'entity:url'
	 * @param string $type   Equals 'object'
	 * @param string $return Current URL
	 * @param array $params  Additional params
	 * @return string
	 */
	public function handleEntityUrls($hook, $type, $return, $params) {

		$entity = elgg_extract('entity', $params);

		if (!$entity instanceof Category) {
			return $return;
		}

		return $this->router->getEntityUrl($entity);
	}

	/**
	 * Update category icon URL
	 *
	 * @param string $hook		Equals 'entity:icon:url'
	 * @param string $type		Equals 'object'
	 * @param string $return	Current icon URL
	 * @param array $params		Additional params
	 * @return string			Updated icon URL
	 */
	public function handleEntityIconUrls($hook, $type, $return, $params) {

		$entity = elgg_extract('entity', $params);
		$size = elgg_extract('size', $params, 'medium');

		if (!$entity instanceof Category) {
			return $return;
		}

		if ($entity->icontime) {
			return $this->router->normalize(array('icon', $entity->guid, $size));
		}

		return elgg_normalize_url('/mod/hypeCategories/graphics/node.svg');
	}

	/**
	 * Adds categories to the entity menu
	 *
	 * @param str   $hook   Equals 'register'
	 * @param str   $type   Equals 'menu:entity'
	 * @param array $return An array of menu items
	 * @param array $params An array of additional parameters
	 * @return array
	 */
	public function setupEntityMenu($hook, $type, $return, $params) {

		$entity = elgg_extract('entity', $params);

		if (!$entity instanceof ElggEntity) {
			return $return;
		}

		$count = $this->model->getItemCategories($entity, array('count' => true));
		$entity->setVolatileData('categories_count', $count);

		if ($count) {
			$return[] = ElggMenuItem::factory(array(
						'name' => 'categories',
						'text' => elgg_view('output/category', array(
							'entity' => $entity
						)),
						'href' => false,
			));
		}

		return $return;
	}

	/**
	 * Setup categories tree using Elgg menu
	 *
	 * @param string $hook   Equals 'register'
	 * @param string $type   Equals 'menu:categories'
	 * @param array  $return An array of category menu items
	 * @param array  $params Additional parameters passed to elgg_view_menu()
	 *                       'entity' Entity
	 *                       'depth'  Depth of the tree
	 *                       'limit'  Number of subcategories to display
	 *                       'icons'  Show icons
	 *                       'collapse' Collapse by default
	 *
	 * @return array
	 */
	public function setupCategoriesMenu($hook, $type, $return, $params) {

		elgg_require_js('framework/categories/menu');

		$entity = elgg_extract('entity', $params, elgg_get_site_entity()); // container group or site or category

		if (!$entity instanceof ElggEntity) {
			return $return;
		}

		unset($params['name']);
		$defaults = array(
			'depth' => false,
			'icons' => true,
			'collapse' => true,
			'limit' => false,
			'badge' => true,
		);
		$params = array_merge($defaults, $params);

		$root_nodes = array($entity->guid);

		$tree = new TreeNode($entity, null, null, $params);
		$nodes = $tree->toArray($params['depth']);

		if (!$entity instanceof ElggSite) {
			if (!elgg_in_context('categories-manage')) {
				if (($entity instanceof ElggGroup && $this->config->allowsGroupCategories()) || $entity instanceof ElggObject) {
					// Add site wide categories if we are in a group context with group categories enabled
					// or we are in an object context
					$site = elgg_get_site_entity();
					$root_nodes[] = $site->guid;
					$site_tree = new TreeNode($site, null, null, $params);
					$site_nodes = $site_tree->toArray($params['depth']);
					$nodes = array_merge($site_nodes, $nodes);
				}
			}
		}

		if (!empty($nodes)) {
			foreach ($nodes as $node_opts) {
				$node_guid = elgg_extract('node_guid', $node_opts);
				$parent_guid = elgg_extract('parent_guid', $node_opts);

				$node = get_entity($node_guid);
				$parent = get_entity($parent_guid);

				if (!$node instanceof ElggEntity || in_array($node->guid, $root_nodes)) {
					continue;
				}

				$has_children = elgg_extract('has_children', $node_opts);
				$item_params = array_merge($params, array(
					'entity' => $node,
					'has_children' => $has_children,
				));
				
				$return[] = ElggMenuItem::factory(array(
							'name' => "category:{$node->guid}",
							'parent_name' => ($parent && !in_array($parent->guid, $root_nodes)) ? "category:{$parent->guid}" : null,
							'text' => elgg_view('framework/categories/node', $item_params),
							'href' => false,
							'priority' => ($node->priority) ? (int) $node->priority: 999,
							'data' => $item_params,
				));
			}
		}

		if (elgg_in_context('categories-manage')) {
			// Adds an empty subcategory form
			$return[] = ElggMenuItem::factory(array(
						'name' => 'placeholder',
						//'parent_name' => "category:{$node->guid}",
						'text' => elgg_view('framework/categories/placeholder', array(
							'container' => $entity->guid,
						)),
						'href' => false,
						'priority' => 1000,
						'data-guid' => $node->guid
			));
		}

		return $return;
	}

	/**
	 * Setup type/subtype filter for a category
	 *
	 * @param string $hook   "register"
	 * @param string $type   "menu:category-filter"
	 * @param array  $return Menu
	 * @param array  $params Hook params
	 * @return array
	 */
	function setupCategoryFilterMenu($hook, $type, $return, $params) {

		$entity = elgg_extract('entity', $params);

		if (!hypeCategories()->model->instanceOfCategory($entity)) {
			return $return;
		}

		$stats = array();
		$pairs = hypeCategories()->config->getEntityTypeSubtypePairs();
		$grouped_entities = hypeCategories()->model->getItemsInCategory($entity, array(
			'selects' => array('COUNT(*) as cnt'),
			'types_subtype_pairs' => $pairs,
			'group_by' => 'e.type, e.subtype',
			'limit' => 0,
		));

		if (empty($grouped_entities)) {
			return $return;
		}

		foreach ($grouped_entities as $entity) {
			$count = $entity->getVolatileData('select:cnt');
			if (!$count) {
				continue;
			}

			$type = $entity->getType();
			$subtype = $entity->getSubtype();

			if (!$subtype) {
				$text = elgg_echo("item:$type");
				$subtype = 'default';
			} else {
				$text = elgg_echo("item:$type:$subtype");
			}

			$counter = elgg_format_element('span', array(
				'class' => 'cateogires-category-badge',
					), $count);

			$url = $entity->getURL();
			$return[] = ElggMenuItem::factory(array(
						'name' => "$type:$subtype",
						'text' => $text . '<em>' . $counter . '</em>',
						'href' => elgg_http_add_url_query_elements($url, array(
							'type' => $type,
							'subtype' => $subtype
						)),
			));
		}

		return $return;
	}

}