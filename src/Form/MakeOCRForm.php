<?php

declare(strict_types=1);

namespace Drupal\nyhs_update_ocr\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\IslandoraUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a NYHS Update OCR form.
 */
final class MakeOCRForm extends FormBase {

  /**
   * The Islandora Utils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * The Entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The action plugin manager.
   *
   * @var \Drupal\Core\Action\ActionManager
   */
  protected $manager;

  /**
   * Standard Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   The Islandora Utilities.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param Psr\Log\LoggerInterface\ $logger
   *   The logger.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, IslandoraUtils $utils, Connection $database, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->utils = $utils;
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('islandora.utils'),
      $container->get('database'),
      $container->get('logger.channel.islandora'),
      $container->get('plugin.manager.action')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nyhs_update_ocr_makeocr';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['collection'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#title' => $this->t('Collection'),
      '#description' => $this->t('Select collection.'),
      '#tags' => TRUE,
      '#selection_settings' => [
        'target_bundles' => ['islandora_object'],
      ],
      '#weight' => '0',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->action = $this->entityTypeManager->getStorage('action')
      ->load('extract_text_from_service_file');
    $term_uris = [
      'http://purl.org/dc/dcmitype/Collection',
      'http://vocab.getty.edu/aat/300242735',
      'https://schema.org/Newspaper',
      'https://schema.org/Book',
      'https://schema.org/PublicationIssue',
    ];
    foreach ($term_uris as $uri) {
      $term = $this->utils->getTermForUri($uri);
      $this->collectionTerms[] = $term->id();
    }
    $collection = $form_state->getValue('collection');
    $collection_nid = $collection[0]['target_id'];
    $containers = [$collection_nid];
    $subcollections = [$collection_nid];
    while (count($subcollections) > 0) {
      $subcollections = $this->getCollectors($subcollections);
      $containers = array_merge($subcollections, $containers);
    }
    $results = $this->getResults($containers);
    $operations = [];
    $this->logger->info("Processing " . count($results));
    $this->messenger()
      ->addStatus($this->t('Pages have been added to the queue, but may take some time to process'));

    foreach ($results as $result) {
      $operations[] = [
        [$this, 'processResult'],
        [$result],
      ];
    }
    $batch = [
      'title' => $this->t("Extracting text..."),
      'operations' => $operations,
      'progress_message' => $this->t('Processed @current out of @total. Estimated time: @estimate.'),
      'error_message' => $this->t('The process has encountered an error.'),
    ];
    batch_set($batch);
  }

  /**
   * Gets results from database.
   *
   * @param array $collection_ids
   *   Nids of all nodes with children.
   *
   * @return array
   *   All children of collection nodes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getResults(array $collection_ids): array {
    $model_term = $this->utils->getTermForUri('http://id.loc.gov/ontologies/bibframe/part');
    $mt_tid = $model_term->id();
    $extractedTextTerm = $this->utils->getTermForUri('http://pcdm.org/use#ExtractedText');
    $et_tid = $extractedTextTerm->id();
    $sql = <<<"SQL"
select n.nid
from node n,
     node__field_member_of me,
     node__field_model mo
where n.nid = mo.entity_id
  and n.nid = me.entity_id
  and me.field_member_of_target_id in (:collection_ids[])
  and mo.field_model_target_id = $mt_tid
  and n.nid not in (select mmo.field_media_of_target_id
                    from media__field_media_use mmu,
                         media__field_media_of mmo
                    where field_media_use_target_id = $et_tid
                      and mmu.entity_id = mmo.entity_id);
SQL;
    $query = $this->database->query($sql, [':collection_ids[]' => $collection_ids]);
    return $query->fetchAll();
  }

  /**
   * Gets all sub nodes with children.
   *
   * @param array $parents
   *   The parent nodes.
   *
   * @return array
   *   The child nodes.
   */
  protected function getCollectors(array $parents) {
    $sql = <<<"SQL"
    select n.nid
from node n,
     node__field_member_of me,
     node__field_model mo
where n.nid = mo.entity_id
    and n.nid = me.entity_id
    and me.field_member_of_target_id in (:parents[])
    and mo.field_model_target_id in (:terms[]);
SQL;
    $query = $this->database->query($sql, [
      ':parents[]' => $parents,
      ':terms[]' => $this->collectionTerms,
    ]);
    return $query->fetchCol();
  }

  /**
   * Processes each node.
   *
   * @param object $result
   *   Object with nid.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processResult($result) {
    $entity = $this->entityTypeManager->getStorage('node')->load($result->nid);
    $this->action->execute([$entity]);
  }

}
