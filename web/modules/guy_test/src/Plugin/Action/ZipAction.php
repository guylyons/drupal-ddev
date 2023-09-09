<?php

namespace Drupal\guy_test\Plugin\Action;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\Entity\Media;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Zip file action with default confirmation form.
 *
 * @Action(
 *   id = "zip_file",
 *   label = @Translation("Zip selected files"),
 *   type = "media",
 *   confirm = FALSE,
 * )
 */
class ZipAction extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  /**
   * Current User.
   *
   * @var Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Drupal LoggerInterface.
   *
   * @var Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * PHP ZipArchive.
   *
   * @var \ZipArchive
   */
  protected $zipArchive;

  /**
   * Drupal File system service.
   *
   * @var Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * File URL generator service.
   *
   * @var Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * File repository interface.
   *
   * @var Drupal\File\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * Entity Type Manager service.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructs FileZipAction plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountProxy $current_user
   *   The current user.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system.
   * @param Psr\Log\LoggerInterface $logger
   *   The LoggerInterface.
   * @param \Drupal\Core\File\FileUrlGenerator $file_url_generator
   *   The File URL generator.
   * @param \Drupal\File\FileRepositoryInterface $file_repository
   *   The file repository interface.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxy $current_user, FileSystem $file_system, LoggerInterface $logger, FileUrlGenerator $file_url_generator, FileRepositoryInterface $file_repository, EntityTypeManager $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->fileSystem = $file_system;
    $this->logger = $logger;
    $this->fileUrlGenerator = $file_url_generator;
    $this->fileRepository = $file_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->zipArchive = new \ZipArchive();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('file_system'),
      $container->get('logger.channel.file'),
      $container->get('file_url_generator'),
      $container->get('file.repository'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    return $this->executeMultiple([$entity]);
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {
    $results = [];

    // Sets up temp file for the current user.
    $user_id = $this->currentUser->id();
    $temp_zip_path = '/tmp/npin-group-temp-' . $user_id . '.zip';

    // Try to open the temp zip file.
    $opened = $this->zipArchive->open($temp_zip_path);

    // If first batch or file does not exist.
    if ($this->context['sandbox']['current_batch'] == 1 || $opened === 9) {
      $opened = $this->zipArchive->open($temp_zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    }

    if ($opened) {
      foreach ($objects as $entity) {
        if ($entity instanceof Media) {
          $media = $this->entityTypeManager->getStorage('media')->load($entity->id());
          $fid = $media->getSource()->getSourceFieldValue($media);
          $file = $this->entityTypeManager->getStorage('file')->load($fid);
          $uri = $file->getFileUri();
          $absolute_path = $this->fileSystem->realpath($uri);
          if (file_exists($uri)) {
            $this->zipArchive->addFile($absolute_path, $file->getFilename());
            $results[] = $file->getFilename();
          } else {
            // Handle missing files.
            $this->logger->error('FileZipAction.php: File not available during ZipArchive->addfile(): ' . $absolute_path);
          }
        }
      }
      $this->zipArchive->close();
    }

    $batch_size = $this->context['sandbox']['batch_size'] ?? 0;
    $total = $this->context['sandbox']['total'] ?? 0;
    $processed = $this->context['sandbox']['processed'] ?? 0;

    // On last batch.
    if ($processed + $batch_size >= $total) {

      // Save zip.
      $generated_path = $this->fileUrlGenerator->transformRelative($temp_zip_path);
      $output = file_get_contents($generated_path);
      $time = \Drupal::time()->getRequestTime();
      $file = $this->fileRepository->writeData($output, 'public://group-files-' . $time . '.zip', FileSystemInterface::EXISTS_REPLACE);
      $file->setTemporary();
      $file->save();

      // Generate link/message for downloading the zip.
      $relative_url = $this->fileUrlGenerator->transformRelative($this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()));
      $this->messenger()->addStatus($this->t('Zip file created, <a href=":url" target="_blank">Click here</a> to download.', [':url' => $relative_url]));
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('view', $account, $return_as_object);
  }
}
