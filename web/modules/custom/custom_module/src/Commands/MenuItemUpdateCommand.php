<?php

namespace Drupal\custom_module\Commands;

use Drush\Commands\DrushCommands;

/**
 * Custom Drush command for updating chiropractor menu item ownerships.
 */
class MenuItemUpdateCommand extends DrushCommands
{

    /**
     * Update menu_item ownerships for chiropractor users.
     *
     * @command custom:update-menu-item
     * @aliases cumi
     */
    public function update()
    {
        $menu_item_ids = \Drupal::entityQuery('menu_item')
            ->condition('type', 'menu_item')
            ->accessCheck(FALSE)
            ->execute();

        $menu_items = \Drupal::entityTypeManager()->getStorage('menu_item')->loadMultiple($menu_item_ids);

        $this->output()->writeln("Found " . count($menu_items) . " menu items to process.");

        foreach ($menu_items as $menu_item) {
            $mid = $menu_item->id();
            $this->output()->writeln("Processing menu_item ID: $mid");

            if ($menu_item->hasField('field_my_clinic') && !$menu_item->get('field_my_clinic')->isEmpty()) {
                $clinic_id = $menu_item->get('field_my_clinic')->target_id;
                $clinic = \Drupal::entityTypeManager()->getStorage('clinic')->load($clinic_id);

                if ($clinic) {
                    $uid = $clinic->getOwnerId();
                    $menu_item->setOwnerId($uid);
                    $menu_item->save();

                    $this->output()->writeln("  Set owner of menu_item ID: $mid");
                } else {
                    $this->output()->writeln("  Menu item with ID $mid not found.");
                }
            } else {
                $this->output()->writeln("  No clinic referenced for menu item ID: $mid");
            }
        }

        $this->output()->writeln("Finished updating menu item ownerships.");
    }

}
