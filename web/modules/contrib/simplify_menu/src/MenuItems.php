<?php

declare(strict_types=1);

namespace Drupal\simplify_menu;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;

/**
 * Class for menu items.
 *
 * @package \Drupal\simplify_menu
 */
class MenuItems {

  /**
   * MenuLinkTree definition.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * Active menu tree definition.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface[]
   */
  protected $activeMenuTree;

  /**
   * MenuItems constructor.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_link_tree
   *   The MenuLinkTree service.
   */
  public function __construct(MenuLinkTreeInterface $menu_link_tree) {
    $this->menuLinkTree = $menu_link_tree;
  }

  /**
   * Map menu tree into an array.
   *
   * @param array $links
   *   The array of menu tree links.
   * @param string $submenuKey
   *   The key for the submenu to simplify.
   *
   * @return array
   *   The simplified menu tree array.
   */
  protected function simplifyLinks(array $links, string $submenuKey = 'submenu'): array {
    $result = [];
    foreach ($links as $item) {
      // Per DefaultMenuLinkTreeManipulators::checkAccess(), which we run in
      // getMenuTree, "inaccessible links are *not* removed; it's up to the code
      // doing something with the tree to exclude inaccessible links, just like
      // MenuLinkTree::build() does" - whose code we replicate here.
      //
      /** @var \Drupal\Core\Menu\MenuLinkInterface $link */
      $link = $item->link;
      // Generally we only deal with visible links, but just in case.
      if (!$link->isEnabled()) {
        continue;
      }

      if ($item->access !== NULL && !$item->access instanceof AccessResultInterface) {
        throw new \DomainException('MenuLinkTreeElement::access must be either NULL or an AccessResultInterface object.');
      }

      // Only render accessible links.
      if ($item->access instanceof AccessResultInterface && !$item->access->isAllowed()) {
        continue;
      }

      // Build the link item.
      $simplifiedLink = [
        'text' => $item->link->getTitle(),
        'url' => $item->link->getUrlObject()->toString(),
        'active_trail' => FALSE,
        'active' => FALSE,
      ];

      // Allows modules to add items to the link.
      \Drupal::moduleHandler()->alter('simplify_menu_simplified_link', $simplifiedLink, $link);

      $current_path = \Drupal::request()->getRequestUri();
      if ($current_path == $simplifiedLink['url']) {
        $simplifiedLink['active'] = TRUE;
      }

      /** @var string $plugin_id */
      $plugin_id = $item->link->getPluginId();
      if (isset($this->activeMenuTree[$plugin_id]) && $this->activeMenuTree[$plugin_id] == TRUE) {
        $simplifiedLink['active_trail'] = TRUE;
      }
      if ($item->hasChildren) {
        $simplifiedLink[$submenuKey] = $this->simplifyLinks($item->subtree);
      }
      $result[] = $simplifiedLink;
    }
    return $result;
  }

  /**
   * Get header menu links.
   *
   * @param string $menuId
   *   Menu drupal id.
   *
   * @return array
   *   Render array of menu items.
   */
  public function getMenuTree(string $menuId = 'main'): array {
    $this->setActiveMenuTree($menuId);

    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];

    $headerTreeLoad = $this->menuLinkTree->load($menuId, $parameters);
    $headerTransform = $this->menuLinkTree->transform($headerTreeLoad, $manipulators);

    return [
      'menu_tree' => $this->simplifyLinks($headerTransform),
    ];
  }

  /**
   * Loads up the current active menu tree and sets it to a variable.
   *
   * @param string $menu_id
   *   The id of the menu to check for active links.
   */
  public function setActiveMenuTree(string $menu_id): void {
    $menu_tree = \Drupal::menuTree();
    $parameters = \Drupal::menuTree()->getCurrentRouteMenuTreeParameters($menu_id);
    $loaded_tree = $menu_tree->load($menu_id, $parameters);
    $this->activeMenuTree = $this->checkActiveTrail($loaded_tree);
  }

  /**
   * Loops through a menu tree array to flag menu items in the active trail.
   *
   * @param array $menuTree
   *   An array returned from loading a menu tree.
   *
   * @return array
   *   The menu items keyed by their plugin IDs.
   *   Set to TRUE if in the active trail.
   */
  protected function checkActiveTrail(array $menuTree): array {
    $active = [];
    foreach ($menuTree as $index => $tree) {
      if ($tree->inActiveTrail) {
        $active[$index] = TRUE;
      }
      else {
        $active[$index] = FALSE;
      }
      if ($tree->hasChildren) {
        $active += $this->checkActiveTrail($tree->subtree);
      }
    }
    return $active;
  }

}
