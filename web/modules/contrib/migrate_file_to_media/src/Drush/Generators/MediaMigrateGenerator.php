<?php

declare(strict_types=1);

namespace Drupal\migrate_file_to_media\Drush\Generators;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use DrupalCodeGenerator\Asset\AssetCollection as Assets;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\GeneratorType;
use DrupalCodeGenerator\Validator\MachineName;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Automatically generates yml files for migrations.
 */
#[Generator(
  name: 'migrate_file_to_media:media_migration_generator',
  description: 'Generates yml for File to Media Migration.',
  aliases: ['mf2m_media'],
  templatePath: __DIR__ . '/../Templates',
  type: GeneratorType::MODULE_COMPONENT,
)]
class MediaMigrateGenerator extends BaseGenerator {

  /**
   * {@inheritdoc}
   */
  public function __construct(protected ModuleHandlerInterface $moduleHandler, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $commandHandler = new static(
        $container->get('module_handler'),
        $container->get('entity_type.manager'),
    );

    return $commandHandler;
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, Assets $assets): void {
    $ir = $this->createInterviewer($vars);

    $vars['machine_name'] = $ir->askMachineName();
    $vars['plugin_id'] = $ir->ask('Plugin ID', 'Example', [$this, 'validatePluginId']);
    $vars['migration_group'] = $ir->ask('Migration Group', 'media');
    $vars['entity_type'] = $ir->ask('Entity Type', 'node');
    $vars['source_bundle'] = $ir->ask('Source Bundle', '');
    $vars['source_field_name'] = $ir->ask('Source Field Names (comma separated)', 'field_image');
    $vars['target_bundle'] = $ir->ask('Target Media Type', 'image');
    $vars['target_field'] = $ir->ask('Target Media Type Field', 'field_media_image');
    $vars['source_lang_code'] = $ir->ask('Language Code', 'en');
    $vars['translation_languages'] = $ir->ask('Translation languages (comma separated)', 'none');
    $vars['include_revisions'] = $ir->ask(
      'Include field migration for all content revisions',
      'no',
      [$this, 'validateRevisions']
    );
    $vars['destination'] = $ir->ask(
      'Use migration file as a migration "plugin" or as "config"',
      'plugin',
      [$this, 'validateDestination']
    );

    $vars['translation_language'] = NULL;

    if ($vars['translation_languages']) {
      $translation_languages = array_map('trim', array_unique(explode(',', strtolower($vars['translation_languages']))));
      // Validate the default language was not included
      // in the translation languages.
      foreach ($translation_languages as $key => $language) {
        if ($language == $vars['source_lang_code']) {
          unset($translation_languages[$key]);
        }
      }
      $vars['translation_languages'] = $translation_languages;
    }

    // If we need migrate translation languages then the first default migration
    // step 1 must migrate all values without filtering by langcode to handle
    // the case when default langcode of the entity could be different from
    // the site default langcode.
    // Otherwise we will use the langcode filter for the default step 1.
    $vars['lang_code'] = $vars['translation_languages'] ? 'null' : $vars['source_lang_code'];

    if ($vars['source_field_name']) {
      $vars['source_field_name'] = array_map('trim', explode(',', strtolower($vars['source_field_name'])));
    }

    // ID Key for the entity type (nid for node, id for paragraphs).
    /** @var \Drupal\Core\Entity\EntityTypeInterface $entityType */
    $entityType = $this->entityTypeManager->getDefinition($vars['entity_type']);
    $vars['id_key'] = $entityType->getKey('id');
    $vars['vid_key'] = $entityType->getKey('revision');

    $assets->addFile('{destination}/migrate_plus.migration.{plugin_id}_step1.yml')
      ->template('media-migration-step1.yml.twig')
      ->vars($vars);

    // Validates if there are translation languages and includes a new variable
    // to add translations or not.
    $vars['has_translation'] = (count($vars['translation_languages']) > 0 && $vars['translation_languages'][0] != 'none');
    $assets->addFile('{destination}/migrate_plus.migration.{plugin_id}_step2.yml')
      ->template('media-migration-step2.yml.twig')
      ->vars($vars);

    foreach ($vars['translation_languages'] as $language) {
      if ($language == 'none' || $language == $vars['lang_code']) {
        continue;
      }
      $vars['translation_language'] = $vars['lang_code'] = $language;

      $assets->addFile("{destination}/migrate_plus.migration.{plugin_id}_step1_{$language}.yml")
        ->template('media-migration-step1.yml.twig')
        ->vars($vars);
    }
  }

  /**
   * Plugin id validator.
   */
  public static function validatePluginId(string $value): string {
    // Check the length of the global table name prefix.
    /** @var array $connection_info */
    $connection_info = Database::getConnectionInfo();
    /** @var array $db_info */
    $db_info = array_shift($connection_info);
    $db_info = Database::parseConnectionInfo($db_info);

    $prefix = strlen($db_info['prefix']);
    if (!empty($db_info['prefix']['default'])) {
      $prefix = strlen($db_info['prefix']['default']);
    }
    $max_length = 42 - $prefix;

    // Check if the plugin machine name is valid.
    $validate_machine_name = new MachineName();
    $validate_machine_name($value);

    // Check the maximum number of characters for the migration name. The name
    // should not exceed 42 characters to prevent mysql table name limitation of
    // 64 characters for the table: migrate_message_[PLUGIN_ID]_step1
    // or migrate_message_[PLUGIN_ID]_step2.
    if (strlen($value) > $max_length) {
      throw new \UnexpectedValueException('The plugin id should not exceed more than ' . strval($max_length) . ' characters.');
    }
    return $value;
  }

  /**
   * Plugin 'include revisions' validator.
   */
  public static function validateRevisions(string $value): bool {
    if (!in_array($value, ['no', 'n', 'yes', 'y'])) {
      throw new \UnexpectedValueException('Only two options are available: "yes/y" and "no/n".');
    }
    return $value === 'yes' || $value === 'y';
  }

  /**
   * Plugin destination validator.
   */
  public static function validateDestination(string $value): string {
    if (!in_array($value, ['plugin', 'config'])) {
      throw new \UnexpectedValueException('Only two options are available: "plugin" and "configs".');
    }
    return $value === 'plugin' ? 'migrations' : 'config/install';
  }

}
