<?php

/**
 * Group Subtypes
 *
 * @author Ismayil Khayredinov <info@hypejunction.com>
 * @copyright Copyright (c) 2015, Ismayil Khayredinov
 */
require_once __DIR__ . '/autoloader.php';

elgg_register_event_handler('init', 'system', 'group_subtypes_init', 900);

/**
 * Initialize the plugin
 * @return void
 */
function group_subtypes_init() {

	// Search
	elgg_unregister_entity_type('group', '');
	elgg_unregister_plugin_hook_handler('search', 'group', 'search_groups_hook');
	elgg_register_plugin_hook_handler('search', 'group', 'group_subtypes_search_hook');

	elgg_register_plugin_hook_handler('route', 'groups', 'group_subtypes_route_edit_pages');

	$hooks = _elgg_services()->hooks->getAllHandlers();
	$conf = group_subtypes_get_config();

	$identifiers = array();
	foreach ($conf as $subtype => $options) {

		elgg_register_entity_type('group', $subtype);

		// If a custom form/action has been setup for the subtype,
		// apply custom tools logic
		elgg_register_plugin_hook_handler('action', "groups/edit/$subtype", 'group_subtypes_update_fields_config');

		$identifier = elgg_extract('identifier', $options, 'groups');
		if (in_array($identifier, $identifiers)) {
			continue;
		}

		elgg_register_plugin_hook_handler('page_identifier', "group:$subtype", 'group_subtypes_page_identifier');
		elgg_register_plugin_hook_handler('list_subtypes', $identifier, 'group_subtypes_list_subtypes');

		if ($identifier !== 'groups') {

			elgg_register_menu_item('site', array(
				'name' => $identifier,
				'href' => "$identifier/all",
				'text' => elgg_echo($identifier),
			));

			elgg_register_plugin_hook_handler('route', $identifier, 'group_subtypes_router', 1);

			// core doesn't run 'route' hooks on an updated page identifier, so we will register the callback manually
			if (!isset($hooks['route']['groups'])) {
				continue;
			}

			foreach ($hooks['route']['groups'] as $priority => $callbacks) {
				$callbacks = (array) $callbacks;
				foreach ($callbacks as $callback) {
					elgg_register_plugin_hook_handler('route', $identifier, $callback, $priority);
				}
			}
		}
	}

	elgg_register_plugin_hook_handler('permissions_check:parent', 'group', 'group_subtypes_can_parent');

	// Form hacks
	elgg_register_plugin_hook_handler('action', 'groups/edit', 'group_subtypes_update_fields_config');
	elgg_extend_view('forms/groups/edit', 'forms/groups/edit/subtype');
	elgg_extend_view('forms/groups/edit', 'forms/groups/edit/parent_guid');

	// Group URL
	elgg_register_plugin_hook_handler('entity:url', 'group', 'group_subtypes_rewrite_group_urls');

	// Admin
	elgg_register_admin_menu_item('administer', 'groups', null, 20);
	elgg_register_admin_menu_item('administer', 'subtypes', 'groups', 10);
	elgg_register_action('admin/groups/subtypes/add', __DIR__ . '/actions/admin/groups/subtypes/add.php', 'admin');
	elgg_register_action('admin/groups/subtypes/config', __DIR__ . '/actions/admin/groups/subtypes/config.php', 'admin');
	elgg_register_action('admin/groups/subtypes/change_subtype', __DIR__ . '/actions/admin/groups/subtypes/change_subtype.php', 'admin');
	elgg_extend_view('admin.css', 'forms/admin/groups/subtypes/config.css');

	// Group tools
	if (elgg_is_active_plugin('group_tools')) {
		elgg_unextend_view("groups/edit", "group_tools/group_edit_tabbed", 1);
		elgg_unextend_view("groups/edit", "group_tools/group_edit_tabbed_js", 999999999);
		elgg_extend_view('css/elgg', 'forms/groups/edit/group_tools.css');
	}

	// Menus
	elgg_register_plugin_hook_handler('register', 'menu:owner_block', 'group_subtypes_replace_owner_block_labels', 999);

	// Modules
	elgg_register_plugin_hook_handler('view_vars', 'groups/profile/module', 'groups_subtypes_replace_group_module_title', 999);
}

/**
 * Returns configuration
 * @return array
 */
function group_subtypes_get_config() {
	static $config;
	if (!isset($config)) {
		$setting = elgg_get_plugin_setting('config', 'group_subtypes');
		if ($setting) {
			$config = unserialize($setting);
		} else {
			$config = array();
		}
	}
	return $config;
}

/**
 * Route groups add page
 * 
 * @param string $hook   "route"
 * @param string $type   "groups"
 * @param mixed  $return Identifier and segments
 * @param array  $params Hook params
 * @return mixed
 */
function group_subtypes_route_edit_pages($hook, $type, $return, $params) {

	if (!is_array($return)) {
		return;
	}

	$initial_identifier = elgg_extract('identifier', $params, 'groups');
	$identifier = elgg_extract('identifier', $return);
	$segments = elgg_extract('segments', $return);

	$page = array_shift($segments);
	if ($page == 'add') {
		$parent_guid = array_shift($segments);
		$subtype = array_shift($segments);
		echo elgg_view_resource('groups/add', array(
			'parent_guid' => $parent_guid,
			'subtype' => $subtype,
			'segments' => $segments,
			'identifier' => $initial_identifier,
		));
		return false;
	}

	if ($page == 'edit') {
		$guid = array_shift($segments);
		echo elgg_view_resource('groups/edit', array(
			'guid' => $guid,
			'segments' => $segments,
			'identifier' => $initial_identifier,
		));
		return false;
	}
}

/**
 * Returns 'subtypes' ege* value for given page identifier
 *
 * @param string $identifier Page identifier
 * @return mixed
 */
function group_subtypes_get_subtypes($identifier = 'groups') {
	$subtypes = elgg_trigger_plugin_hook('list_subtypes', $identifier, null, ELGG_ENTITIES_ANY_VALUE);
	if (is_array($subtypes)) {
		return array_unique($subtypes);
	}
	return $subtypes;
}

/**
 * Returns page identifier for a group entity
 *
 * @param ElggGroup $entity Group entity
 * @return string
 */
function group_subtypes_get_identifier(ElggGroup $entity) {

	$type = $entity->getType();
	$subtype = $entity->getSubtype();
	return elgg_trigger_plugin_hook('page_identifier', "$type:$subtype", ['entity' => $entity], 'groups');
}

/**
 * Route group subtypes identifiers to groups page handler
 * 
 * @param string $hook   "route"
 * @param string $type   Subtype name
 * @param array  $return Identifier and segments
 * @param array  $params Hook params
 * @return array
 */
function group_subtypes_router($hook, $type, $return, $params) {
	if (!is_array($return)) {
		return;
	}

	// Get original identifier
	$identifier = elgg_extract('identifier', $params);
	if ($identifier !== 'groups') {
		$tools = elgg_get_config('group_tool_options');
		// Namespace tool options
		foreach ($tools as $key => $tool) {
			$tool->label = elgg_echo("$identifier:tools:$tool->name:label");
			// Let group options prevail. Do not enable anything by default
			$tool->default_on = false;
			$tools[$key] = $tool;
		}
		elgg_set_config('group_tool_options', $tools);
	}

	$segments = elgg_extract('segments', $return);
	return array(
		'identifier' => 'groups',
		'handler' => 'groups',
		'segments' => $segments,
	);
}

/**
 * Specify which group subtypes are to be shown on the page
 * 
 * @param string $hook   "list_subtypes"
 * @param string $type   Page identifier
 * @param mixed  $return Subtypes
 * @param array  $params Hook params
 * @return mixed
 */
function group_subtypes_list_subtypes($hook, $type, $return, $params) {

	$subtypes = array();
	$conf = group_subtypes_get_config();
	foreach ($conf as $subtype => $options) {
		$identifier = elgg_extract('identifier', $options, 'groups');
		if ($identifier == $type) {
			$subtypes[] = $subtype;
		}
	}

	if (empty($subtypes)) {
		return;
	}

	if (is_array($return)) {
		return array_merge($return, $subtypes);
	}

	return $subtypes;
}

/**
 * Returns page identifier for a given group subtype
 * 
 * @param string $hook   "page_identifier"
 * @param string $type   "$type:$subtype"
 * @param string $return Page identifier
 * @param array  $params Hook params
 * @return string
 */
function group_subtypes_page_identifier($hook, $type, $return, $params) {

	list($type, $subtype) = explode(':', $type);
	if ($type != 'group') {
		return;
	}

	$conf = group_subtypes_get_config();
	$options = elgg_extract($subtype, $conf, array());
	return elgg_extract('identifier', $options, 'groups');
}

/**
 * Check if an entity can parent(contain) another entity
 * 
 * @param string $hook   "permissions_check:parent"
 * @param string $type   "group"
 * @param bool   $return Permission
 * @param array  $params Hook params
 * @return bool
 */
function group_subtypes_can_parent($hook, $type, $return, $params) {

	$parent = elgg_extract('parent', $params);
	$subtype = elgg_extract('subtype', $params);

	$conf = group_subtypes_get_config();

	if (empty($conf)) {
		return false;
	}

	if (!$parent instanceof ElggGroup) {
		return $conf[$subtype]['root'];
	}

	return in_array($parent->getSubtype(), $conf[$subtype]['parents']);
}

/**
 * Update group fields config before the group is saved
 * 
 * @param string $hook   "action"
 * @param string $type   "groups/edit"
 * @param mixed  $return Proceed with action?
 * @param array  $params Hook params
 * @return void
 */
function group_subtypes_update_fields_config($hook, $type, $return, $params) {

	$fields = elgg_get_config('group');

	$guid = get_input('group_guid');
	$entity = get_entity($guid);
	if ($entity) {
		$subtype = $entity->getSubtype();
	} else {
		$subtype = get_input('subtype');
		if ($subtype) {
			// only add subtype to group fields if it's a new entity
			$fields['subtype'] = 'hidden';
		}
	}

	if ($subtype) {
		// apply subtype tool presets
		$conf = group_subtypes_get_config();
		$tools = elgg_get_config('group_tool_options');
		if ($tools) {
			foreach ($tools as $group_option) {
				$option_name = $group_option->name . "_enable";
				$option_value = in_array($group_option->name, $conf[$subtype]['tools']) ? 'yes' : 'no';
				if (empty(get_input($option_name))) {
					set_input($option_name, $option_value);
				}
			}
		}
	}

	if (get_input('container_guid')) {
		$fields['container_guid'] = 'hidden';
	}

	if (get_input('parent_guid')) {
		$fields['parent_guid'] = 'hidden';
	}

	elgg_set_config('group', $fields);
}

/**
 * Rewrite group urls to match configured identifier
 * 
 * @param string $hook   "entity:url"
 * @param string $type   "group"
 * @param string $return URL
 * @param array  $params Hook params
 * @return string
 */
function group_subtypes_rewrite_group_urls($hook, $type, $return, $params) {

	$entity = elgg_extract('entity', $params);
	if (!$entity instanceof ElggGroup) {
		return;
	}
	$subtype = $entity->getSubtype();
	if (!$subtype) {
		return;
	}
	$identifier = group_subtypes_get_identifier($entity);
	if ($identifier != 'groups') {
		$title = elgg_get_friendly_title($entity->name);
		return elgg_normalize_url("$identifier/profile/$entity->guid/$title");
	}
}

/**
 * Configure tools available to subtype
 * Called before the group edit form is rendered
 *
 * @param string $subtype Group subtype
 * @return void
 */
function group_subtypes_configure_tools($subtype) {

	$conf = group_subtypes_get_config();
	if ($conf[$subtype]['preset_tools']) {
		elgg_set_config('group_tool_options', null);
	} else {
		$tools = elgg_get_config('group_tool_options');
		if ($tools) {
			foreach ($tools as $key => $group_option) {
				if (!in_array($group_option->name, $conf[$subtype]['tools'])) {
					unset($tools[$key]);
				}
			}
		}
		elgg_set_config('group_tool_options', $tools);
	}
}

/**
 * Get groups that match the search parameters.
 *
 * @param string $hook   Hook name
 * @param string $type   Hook type
 * @param array  $value  Empty array
 * @param array  $params Search parameters
 * @return array
 */
function group_subtypes_search_hook($hook, $type, $value, $params) {

	$params['joins'] = (array) elgg_extract('joins', $params, array());
	$params['wheres'] = (array) elgg_extract('wheres', $params, array());

	$db_prefix = elgg_get_config('dbprefix');

	$query = sanitise_string($params['query']);

	$join = "JOIN {$db_prefix}groups_entity ge ON e.guid = ge.guid";
	array_unshift($params['joins'], $join);

	$fields = array('name', 'description');
	$where = search_get_where_sql('ge', $fields, $params);
	$params['wheres'][] = $where;

	$params['count'] = TRUE;
	$count = elgg_get_entities($params);

	// no need to continue if nothing here.
	if (!$count) {
		return array('entities' => array(), 'count' => $count);
	}

	$params['count'] = FALSE;
	if (isset($params['sort']) || !isset($params['order_by'])) {
		$params['order_by'] = search_get_order_by_sql('e', 'ge', $params['sort'], $params['order']);
	}
	$entities = elgg_get_entities($params);

	// add the volatile data for why these entities have been returned.
	foreach ($entities as $entity) {
		$name = search_get_highlighted_relevant_substrings($entity->name, $query);
		$entity->setVolatileData('search_matched_title', $name);

		$description = search_get_highlighted_relevant_substrings($entity->description, $query);
		$entity->setVolatileData('search_matched_description', $description);
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

/**
 * Returns an array of group subtypes that can be added to the parent
 *
 * @param ElggEntity $parent Parent entity
 * @return array
 */
function group_subtypes_get_allowed_subtypes_for_parent($parent = null) {
	$allowed_subtypes = array();
	$subtypes = get_registered_entity_types('group');
	foreach ($subtypes as $subtype) {
		$params = array(
			'parent' => $parent,
			'type' => 'group',
			'subtype' => $subtype,
		);
		$can_parent = elgg_trigger_plugin_hook('permissions_check:parent', 'group', $params, true);
		if ($can_parent) {
			$allowed_subtypes[] = $subtype;
		}
	}

	return $allowed_subtypes;
}

/**
 * Replaces owner block labels with type specific strings
 * 
 * @param string $hook   "register"
 * @param string $type   "menu:owner_block"
 * @param array  $return Menu
 * @param array  $params Hook params
 * @return array
 */
function group_subtypes_replace_owner_block_labels($hook, $type, $return, $params) {

	$entity = elgg_extract('entity', $params);
	if (!$entity instanceof ElggGroup) {
		return;
	}

	$identifier = group_subtypes_get_identifier($entity) ? : 'groups';
	if ($identifier == 'groups') {
		return;
	}

	foreach ($return as $key => $item) {
		$item->setText(elgg_echo("$identifier:tools:{$item->getName()}"));
		$return[$key] = $item;
	}

	return $return;
}

/**
 * Replace group profile module title
 *
 * @param string $hook   "view_vars"
 * @param string $type   "groups/profile/module"
 * @param array  $vars   View vars
 * @param array  $params Hook params
 * @return array
 */
function groups_subtypes_replace_group_module_title($hook, $type, $vars, $params) {

	$route = _elgg_services()->request->getUrlSegments();
	$context = array_shift($route);

	if ($context == 'groups') {
		return;
	}

	$group_str = elgg_echo('groups:group');
	$groups_str = elgg_echo('item:group');

	$title = elgg_extract('title', $vars, '');
	$title = preg_replace("/{$group_str}|{$groups_str}/i", '', $title);
	$title = ucfirst(trim($title, ' '));
	$vars['title'] = $title;

	return $vars;
}