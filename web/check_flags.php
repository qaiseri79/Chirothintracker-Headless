<?php
require_once "autoload.php";
$kernel = \Drupal\Core\DrupalKernel::createFromRequest(\Symfony\Component\HttpFoundation\Request::create("/", "GET"), "prod", FALSE);
$kernel->boot();
$container = $kernel->getContainer();
$config_factory = $container->get("config.factory");
$flags = $config_factory->listAll("flag.flag.");
foreach ($flags as $flag) {
  $config = $config_factory->get($flag)->get();
  if (isset($config["entity_type"]) && $config["entity_type"] == "contact_message") {
    echo "Flag: " . $config["id"] . " - " . $config["label"] . "\n";
  }
}