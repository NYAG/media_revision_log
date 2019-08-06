<?php

namespace Drupal\media_revision_log\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\media\MediaInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Returns a response for a media entity route on revisions.
 */
class RevisionController extends ControllerBase {
  
  /**
   * Drupal's entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_manager;

  /**
   * Drupal's language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $language_manager;

  /**
   * Drupal's current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $current_route_match;

  /**
   * Drupal's database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  
  /**
   * Creates the RevisionController object, extracts the services we need, and passes it to the constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The services container.
   */
  public static function create(ContainerInterface $container) {
    $entity_manager = $container->get('entity.manager');
    $language_manager = $container->get('language_manager');
    $current_route_match = $container->get('current_route_match');
    $database = $container->get('database');

    return new static($entity_manager, $language_manager, $current_route_match, $database);
  }  

  /**
   * Constructs a RevisionController object.
   * 
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Language\LanguageManager $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   The current route match service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, LanguageManager $language_manager, CurrentRouteMatch $current_route_match, Connection $database) {
    $this->entity_manager = $entity_manager;
    $this->language_manager = $language_manager;
    $this->current_route_match = $current_route_match;
    $this->database = $database;
  }
  
  /**
   * Generates an overview table of older revisions of a media.
   * 
   * @return array
   *   An array as expected by \Drupal\Core\Render\RendererInterface::render().
   */
  public function revisionOverview() {
    // Gets loaded media object from path parameter.
    $media = $this->current_route_match->getParameter('media');
    // Gets the media ID.
    if ($media instanceof \Drupal\media\MediaInterface) {
      $mid = $media->id();
    }
    // Gets the cache tag for the corresponding media.
    $cache_tag = $media->getCacheTags();
    // Gets the language code the user is on.
    $language = $this->language_manager->getCurrentLanguage()->getId();
    // Gets the full language name the user is on.
    $langname = $this->language_manager->getCurrentLanguage()->getName();
    // Sets the title of the revision log for the media and indicates which language translation it is for.
    $build['title'] = $this->t('@langname revisions for %title', ['@langname' => $langname, '%title' => $media->label()]);
    // Sets the amount of columns for the table from the render array.
    $header = [$this->t('Revision'), $this->t('Operations')];
    // Creates a new storage instance of the Media entity.
    $media_storage = $this->entity_manager->getStorage('media');

    // Loops through the revision Ids of the media, filters relevant revisions, and populates the table through the $rows array.
    foreach ($this->getRevisionIds($media, $media_storage) as $vid) {
      // Gets the Media revision.
      $revision = $media_storage->loadRevision($vid);

      $media_name = null;
      $media_link = null;
      $fieldsValues = [];

      foreach ($revision->getFields() as $fieldName => $field) {
        if (substr($fieldName, 0, 12) == 'field_media_'){
          $fid = $revision->$fieldName->target_id;
          $file = File::load($fid);
          $file_uri = $file->getFileUri();

          $media_name = $file->filename->value;
          $media_link = file_create_url($file_uri);
        }
        elseif (substr($fieldName, 0, 6) == 'field_'){
          if ($field->getFieldDefinition()->getType() == 'entity_reference'){
            switch (get_class($field->entity)) {
              case 'Drupal\node\Entity\Node':
                  $fieldsValues[] = $field->getFieldDefinition()->getLabel().' : '.$field->entity->title->value;
                break;
              case 'Drupal\taxonomy\Entity\Term':
                  $fieldsValues[] = $field->getFieldDefinition()->getLabel().' : '.$field->entity->name->value;
                break;
            }
          } else {
            $fieldsValues[] = $field->getFieldDefinition()->getLabel().' : '.$field->value;
          }
        }
      }

      // Gets the timestamp of the revision.
      $changed = $this->getChangedTimestamp($mid, $revision, $language);
      // Filters revisions that are relevant to the language the user is on.
      if ($revision->hasTranslation($language) && $revision->getTranslation($language)->isRevisionTranslationAffected()) {

        $username2 = ($revision->getRevisionUser())? $revision->getRevisionUser()->name->value: null;
        $date = date('m/d/Y - H:i', $changed);
        $row = [];
        $column = [
 		      'data' => [
 		        '#type' => 'inline_template',
 		        '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if fields %}<p>{{ fields|join("<br>")|raw }}</p>{% endif %}{% if media_link %}<p class="revision-log"><a href="{{ media_link }}" target="_blank">{{ media_name }}</a></p>{% endif %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
 		        '#context' => [
 		          'date' => $date,
              // Replace $username2 with $username if the users on your site are configured with a realname field (e.g. 'Bruce Yuen' instead of 'bruce.yuen').
 		          'username' => $username2,
              'media_name' => $media_name,
              'media_link' => $media_link,
              'fields' => $fieldsValues,
 		          'message' => ['#markup' => $revision->revision_log_message->value, '#allowed_tags' => Xss::getHtmlTagList()],
 		        ],
 		      ],
        ];
        $row[] = $column;

        $resetUrl = new Url('entity.media.revision_revert_confirm', ['media' => $media->id(), 'media_revision' => $vid]);
        $row[] = Link::fromTextAndUrl(t('Revert'), $resetUrl);
        $rows[] = $row;
      }
    }

    // Define the render array
    $build['media_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => [
        'library' => ['node/drupal.node.admin'],
      ],
      // Cache tag to invalidate the revision log when the corresponding media changes.
      '#cache' => [
        'tags' => [$cache_tag[0]],
      ],
      '#attribute' => ['class' => 'node-revision-table'],
    ];
    // Adds pagination.
    $build[] = ['#type' => 'pager'];

    return $build;
  }
  
  /**
   * Gets an array of all the revision Ids for a peticular media.
   */
  public function getRevisionIds(MediaInterface $media, EntityStorageInterface $media_storage) {
    $result = $media_storage->getQuery()
      ->allRevisions()
      ->condition($media->getEntityType()->getKey('id'), $media->id())
      ->sort($media->getEntityType()->getKey('revision'), 'DESC')
      ->pager(50)
      ->execute();
    return array_keys($result);
  }

  /**
   * Gets the correct timestamp for a revision.
   */
  public function getChangedTimestamp($mid, EntityInterface $revision, $language) {
    // Using query that wants revisions that match the media ID, revision ID, and language user is on.
  	$query = $this->database->select('media_field_revision', 'a')
  	  ->fields('a', ['changed'])
  	  ->condition('a.mid', $mid, '=')
  	  ->condition('a.vid', $revision->vid->value, '=')
  	  ->condition('a.langcode', $language, '=')
  	  ->orderBy('a.vid', 'DESC');

    // Obtaining a MySQL object.
  	$results = $query->execute();

    // Get the timestamp from the 'changed' field of the media_field_revision table.
  	foreach ($results as $record) {
  	  $changed = $record->changed;
  	}
  	return $changed;
  }
}
