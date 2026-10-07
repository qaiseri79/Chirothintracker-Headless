# Menu Markup
Module provides ability to allow HTML markup in menu titles.

By default, Drupal will not accept HTML markup as part of a menu title.
This module allows you to configure markup to be shown along with specific
menu titles (including submenu items).

This can be very handy if you want to show a glyphicon from Bootstrap or
an icon from FontAwesome, etc. in front of your menu titles.

Additionally, this module has functionality that will easily allow you to
put Bootstrap-style "badges" next to your menu items, representing node
counts (e.h. "Articles (30)", etc.).

There are similar modules for D7, but they seem to have been abandoned.
Further, this module takes the concept a step or two further with
functionality.

## Requirements

No special requirements at this time.

## Install via Composer

```bash
composer require drupal/menu_markup
```

## Enable via Drush

```bash
drush en menu_markup
```

## Standard usage scenario

1. Download and install the module.
2. Enable module via Drush command above or through admin UI.
3. Open up the edit page for a menu link or create a new menu link.
4. Expand the Menu Markup section to see the options available.
5. Enter in your link markup.
6. A special token @title can be used to substitute in the translated original
title text.
7. If you select a node type count, another special token called @nodeCount
will be available representing the total count of published nodes of the
selected type (useful for Bootstrap badges in menu items).

### Demo

To see a demo, visit the
<a href="http://incurs.us" target="_blank">Incursus web site</a> and
check out our top navbar menu.

### Example menu link markup

```html
<span class="fa fa-home"></span>&nbsp;&nbsp;@title
```

```html
<span class="fa fa-star"></span>&nbsp;&nbsp;
<strong>@title</strong> <span class="badge">@nodeCount</span>
```

### Similar/Related Modules

1. <a href="https://www.drupal.org/project/special_menu_items">
    Special Menu Items</a>
2. <a href="https://www.drupal.org/project/menu_attributes">menu_attributes</a>

### Credits/Maintainers

Maintained by Scott Burkett.
Maintained by George Anderson (geoanders).
