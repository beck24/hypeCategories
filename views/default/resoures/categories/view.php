<?php

namespace hypeJunction\Categories;

$guid = get_input('guid');
$entity = get_entity($guid);

if (!elgg_instanceof($entity, 'object', HYPECATEGORIES_SUBTYPE)) {
	return false;
}

$crumbs = get_hierarchy($entity->guid, false);
if ($crumbs) {
	foreach ($crumbs as $crumb) {
		if (elgg_instanceof($crumb)) {
			elgg_push_breadcrumb($crumb->title, $crumb->getURL());
			$container = $crumb->getContainerEntity();
			if (elgg_instanceof($container, 'group')) {
				elgg_set_page_owner_guid($container->guid);
			}
		}
	}
}

elgg_push_breadcrumb($entity->title);

$title = elgg_echo('categories:category', array($entity->title));

$content = elgg_view_entity($entity, array(
	'full_view' => true
		));

$sidebar = elgg_view('framework/categories/filter', array(
	'entity' => $entity
		));

$layout = elgg_view_layout('content', array(
	'title' => $title,
	'content' => $content,
	'sidebar' => $sidebar,
	'filter' => false
		));

echo elgg_view_page($title, $layout);
