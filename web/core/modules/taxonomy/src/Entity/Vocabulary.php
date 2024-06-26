<?php

namespace Drupal\taxonomy\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\taxonomy\VocabularyInterface;

/**
 * Defines the taxonomy vocabulary entity.
 *
 * @ConfigEntityType(
 *   id = "taxonomy_vocabulary",
 *   label = @Translation("Taxonomy vocabulary"),
 *   label_singular = @Translation("vocabulary"),
 *   label_plural = @Translation("vocabularies"),
 *   label_collection = @Translation("Taxonomy"),
 *   label_count = @PluralTranslation(
 *     singular = "@count vocabulary",
 *     plural = "@count vocabularies"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\taxonomy\VocabularyStorage",
 *     "list_builder" = "Drupal\taxonomy\VocabularyListBuilder",
 *     "access" = "Drupal\taxonomy\VocabularyAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\taxonomy\VocabularyForm",
 *       "reset" = "Drupal\taxonomy\Form\VocabularyResetForm",
 *       "delete" = "Drupal\taxonomy\Form\VocabularyDeleteForm",
 *       "overview" = "Drupal\taxonomy\Form\OverviewTerms"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\taxonomy\Entity\Routing\VocabularyRouteProvider",
 *       "permissions" = "Drupal\user\Entity\EntityPermissionsRouteProvider",
 *     }
 *   },
 *   admin_permission = "administer taxonomy",
 *   collection_permission = "access taxonomy overview",
 *   config_prefix = "vocabulary",
 *   bundle_of = "taxonomy_term",
 *   entity_keys = {
 *     "id" = "vid",
 *     "label" = "name",
 *     "weight" = "weight"
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/taxonomy/add",
 *     "delete-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/delete",
 *     "reset-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/reset",
 *     "overview-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/overview",
 *     "edit-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}",
 *     "entity-permissions-form" = "/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/overview/permissions",
 *     "collection" = "/admin/structure/taxonomy",
 *   },
 *   config_export = {
 *     "name",
 *     "vid",
 *     "description",
 *     "weight",
 *     "new_revision",
 *   }
 * )
 */
class Vocabulary extends ConfigEntityBundleBase implements VocabularyInterface {

  /**
   * The taxonomy vocabulary ID.
   *
   * @var string
   */
  protected $vid;

  /**
   * Name of the vocabulary.
   *
   * @var string
   */
  protected $name;

  /**
   * Description of the vocabulary.
   *
   * @var string
   */
  protected $description;

  /**
   * The weight of this vocabulary in relation to other vocabularies.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->vid;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->description;
  }

  /**
   * The default revision setting for a vocabulary.
   *
   * @var bool
   */
  protected $new_revision = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);

    // Only load terms without a parent, child terms will get deleted too.
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $term_storage->loadMultiple($storage->getToplevelTids(array_keys($entities)));
    $term_storage->delete($terms);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);

    // Reset caches.
    $storage->resetCache(array_keys($entities));

    if (reset($entities)->isSyncing()) {
      return;
    }

    $vocabularies = [];
    foreach ($entities as $vocabulary) {
      $vocabularies[$vocabulary->id()] = $vocabulary->id();
    }
    // Load all Taxonomy module fields and delete those which use only this
    // vocabulary.
    $field_storages = \Drupal::entityTypeManager()->getStorage('field_storage_config')->loadByProperties(['module' => 'taxonomy']);
    foreach ($field_storages as $field_storage) {
      $modified_storage = FALSE;
      // Term reference fields may reference terms from more than one
      // vocabulary.
      foreach ($field_storage->getSetting('allowed_values') as $key => $allowed_value) {
        if (isset($vocabularies[$allowed_value['vocabulary']])) {
          $allowed_values = $field_storage->getSetting('allowed_values');
          unset($allowed_values[$key]);
          $field_storage->setSetting('allowed_values', $allowed_values);
          $modified_storage = TRUE;
        }
      }
      if ($modified_storage) {
        $allowed_values = $field_storage->getSetting('allowed_values');
        if (empty($allowed_values)) {
          $field_storage->delete();
        }
        else {
          // Update the field definition with the new allowed values.
          $field_storage->save();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setNewRevision($new_revision) {
    $this->new_revision = $new_revision;
  }

  /**
   * {@inheritdoc}
   */
  public function shouldCreateNewRevision() {
    return $this->new_revision;
  }

}
