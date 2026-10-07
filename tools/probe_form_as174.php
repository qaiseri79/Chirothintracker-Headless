<?php
$user = \Drupal::entityTypeManager()->getStorage('user')->load(174);
if (!$user) { echo "no user 174\n"; exit; }
$accountSwitcher = \Drupal::service('account_switcher');
$accountSwitcher->switchTo($user);
$proxy = \Drupal::currentUser();
echo "proxy uid: ", $proxy->id(), "\n";
echo "proxy name: ", $proxy->getDisplayName(), "\n";
echo "proxy hasPermission('administer site configuration'): ", var_export($proxy->hasPermission('administer site configuration'), true), "\n";
echo "proxy roles: ", implode(',', $proxy->getRoles()), "\n";
$form_object = \Drupal::formBuilder()->getForm(\Drupal\ctt_patient_intake\Form\IntakeLinksForm::class);
echo "form_id: ", $form_object['#form_id'] ?? '(none)', "\n";
if (isset($form_object['clinic']['#options'])) {
  echo "clinic option count: ", count($form_object['clinic']['#options']), "\n";
  echo "clinic options: ", json_encode($form_object['clinic']['#options']), "\n";
}
else {
  echo "no clinic select (options path): keys=", implode(',', array_keys($form_object)), "\n";
}
$accountSwitcher->switchBack();