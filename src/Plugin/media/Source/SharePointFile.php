<?php

namespace Drupal\sharepoint_media\Plugin\media\Source;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaTypeInterface;
use Drupal\sharepoint_media\Service\DownloadUrlManager;
use Drupal\sharepoint_media\Service\GraphApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * SharePoint file media source.
 *
 * @MediaSource(
 *   id = "sharepoint_file",
 *   label = @Translation("SharePoint File"),
 *   description = @Translation("Use SharePoint driveItems as media source."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "sharepoint.png",
 *   forms = {
 *     "media_library_add" = "Drupal\sharepoint_media\Form\SharePointFileForm",
 *   }
 * )
 */
class SharePointFile extends MediaSourceBase {

  /**
   * The graph API client.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient
   */
  protected $graphClient;

  /**
   * The download URL manager.
   *
   * @var \Drupal\sharepoint_media\Service\DownloadUrlManager
   */
  protected $urlManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManagerInterface $field_type_manager, ConfigFactoryInterface $config_factory, GraphApiClient $graph_client, DownloadUrlManager $url_manager, LoggerChannelFactoryInterface $logger_factory, FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $field_type_manager, $config_factory);
    $this->graphClient = $graph_client;
    $this->urlManager = $url_manager;
    $this->loggerFactory = $logger_factory;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory'),
      $container->get('sharepoint_media.graph_client'),
      $container->get('sharepoint_media.url_manager'),
      $container->get('logger.factory'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      'drive_item_id' => $this->t('SharePoint Drive Item ID'),
      'download_url' => $this->t('Download URL'),
      'web_url' => $this->t('SharePoint Web URL'),
      'mime_type' => $this->t('MIME type'),
      'file_size' => $this->t('File size'),
      'created_by' => $this->t('Created by'),
      'modified_by' => $this->t('Modified by'),
      'created_date' => $this->t('Created date'),
      'modified_date' => $this->t('Modified date'),
      'thumbnail_url' => $this->t('Thumbnail URL'),
      'sharepoint_path' => $this->t('SharePoint Path'),
      'width' => $this->t('Width'),
      'height' => $this->t('Height'),
      'duration' => $this->t('Duration'),
      'camera_make' => $this->t('Camera make'),
      'camera_model' => $this->t('Camera model'),
      'date_taken' => $this->t('Date taken'),
      'default_name' => $this->t('Default name'),
      'thumbnail_uri' => $this->t('Thumbnail URI'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    $source_field = $this->getSourceFieldDefinition($media->bundle->entity);
    $field_name = $source_field->getName();
    $drive_item_id = $media->get($field_name)->value;

    if (empty($drive_item_id)) {
      return parent::getMetadata($media, $attribute_name);
    }

    switch ($attribute_name) {
      case 'drive_item_id':
        return $drive_item_id;

      case 'download_url':
        try {
          return $this->urlManager->getFreshDownloadUrl($drive_item_id);
        }
        catch (\Exception $e) {
          $this->loggerFactory->get('sharepoint_media')->error('Failed to get download URL: @message', ['@message' => $e->getMessage()]);
          return NULL;
        }

      case 'default_name':
        $name = $media->get('name')->value;
        return $name ?: $this->getSharePointMetadata($drive_item_id, 'name');

      case 'thumbnail_uri':
        return $this->getThumbnailUri($media, $drive_item_id);

      default:
        return $this->getSharePointMetadata($drive_item_id, $attribute_name);
    }
  }

  /**
   * Get metadata from SharePoint.
   */
  protected function getSharePointMetadata($drive_item_id, $attribute_name) {
    try {
      $drive_item = $this->graphClient->getDriveItem($drive_item_id);
      
      if (!$drive_item) {
        return NULL;
      }

      switch ($attribute_name) {
        case 'name':
          return $drive_item['name'] ?? NULL;

        case 'web_url':
          return $drive_item['webUrl'] ?? NULL;

        case 'mime_type':
          return $drive_item['file']['mimeType'] ?? NULL;

        case 'file_size':
          return $drive_item['size'] ?? NULL;

        case 'created_by':
          return $drive_item['createdBy']['user']['displayName'] ?? NULL;

        case 'modified_by':
          return $drive_item['lastModifiedBy']['user']['displayName'] ?? NULL;

        case 'created_date':
          return $drive_item['createdDateTime'] ?? NULL;

        case 'modified_date':
          return $drive_item['lastModifiedDateTime'] ?? NULL;

        case 'thumbnail_url':
          return $drive_item['thumbnails'][0]['large']['url'] ?? NULL;

        case 'sharepoint_path':
          return $this->extractSharePointPath($drive_item['webUrl'] ?? '');

        case 'width':
          return $drive_item['image']['width'] ?? NULL;

        case 'height':
          return $drive_item['image']['height'] ?? NULL;

        case 'duration':
          return $drive_item['video']['duration'] ?? NULL;

        case 'camera_make':
          return $drive_item['photo']['cameraMake'] ?? NULL;

        case 'camera_model':
          return $drive_item['photo']['cameraModel'] ?? NULL;

        case 'date_taken':
          return $drive_item['photo']['takenDateTime'] ?? NULL;

        default:
          return NULL;
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get SharePoint metadata: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Get or create thumbnail URI.
   */
  protected function getThumbnailUri(MediaInterface $media, $drive_item_id) {
    // Check if we already have a local thumbnail.
    if ($media->hasField('field_thumbnail_file') && !$media->get('field_thumbnail_file')->isEmpty()) {
      $file = $media->get('field_thumbnail_file')->entity;
      if ($file) {
        return $file->getFileUri();
      }
    }

    // Try to download and store thumbnail locally.
    try {
      $thumbnail_url = $this->getSharePointMetadata($drive_item_id, 'thumbnail_url');
      if ($thumbnail_url) {
        return $this->downloadThumbnail($media, $thumbnail_url);
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to process thumbnail: @message', ['@message' => $e->getMessage()]);
    }

    // Return default thumbnail.
    return $this->getDefaultThumbnail();
  }

  /**
   * Download and store thumbnail locally.
   */
  protected function downloadThumbnail(MediaInterface $media, $thumbnail_url) {
    $directory = 'public://sharepoint_thumbnails';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $filename = 'thumbnail_' . $media->id() . '_' . Crypt::randomBytesBase64(8) . '.jpg';
    $destination = $directory . '/' . $filename;

    try {
      $response = $this->graphClient->getHttpClient()->get($thumbnail_url);
      $data = $response->getBody()->getContents();
      
      if (file_put_contents($destination, $data)) {
        return $destination;
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to download thumbnail: @message', ['@message' => $e->getMessage()]);
    }

    return $this->getDefaultThumbnail();
  }

  /**
   * Get default thumbnail path.
   */
  protected function getDefaultThumbnail() {
    return drupal_get_path('module', 'sharepoint_media') . '/images/sharepoint-default.png';
  }

  /**
   * Extract SharePoint path from web URL.
   */
  protected function extractSharePointPath($web_url) {
    if (empty($web_url)) {
      return '';
    }

    // Extract path after the domain and site collection.
    $parsed = parse_url($web_url);
    $path = $parsed['path'] ?? '';
    
    // Remove common SharePoint URL patterns.
    $patterns = [
      '/sites/[^/]+/Shared Documents/',
      '/personal/[^/]+/',
      '/_layouts/15/WopiFrame.aspx',
    ];
    
    foreach ($patterns as $pattern) {
      $path = preg_replace('#' . $pattern . '#', '/', $path);
    }
    
    return trim($path, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['auto_refresh_thumbnails'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-refresh thumbnails'),
      '#description' => $this->t('Automatically refresh thumbnails when they expire.'),
      '#default_value' => $this->configuration['auto_refresh_thumbnails'] ?? TRUE,
    ];

    $form['thumbnail_cache_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail cache duration (hours)'),
      '#description' => $this->t('How long to cache thumbnails locally before refreshing.'),
      '#default_value' => $this->configuration['thumbnail_cache_duration'] ?? 24,
      '#min' => 1,
      '#max' => 168, // 1 week
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    $this->configuration['auto_refresh_thumbnails'] = $form_state->getValue('auto_refresh_thumbnails');
    $this->configuration['thumbnail_cache_duration'] = $form_state->getValue('thumbnail_cache_duration');
  }

  /**
   * {@inheritdoc}
   */
  public function createSourceField(MediaTypeInterface $type) {
    $field = parent::createSourceField($type);
    $field->set('label', 'SharePoint Drive Item ID');
    $field->set('description', 'The unique identifier for the SharePoint drive item.');
    return $field;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareViewDisplay(MediaTypeInterface $type, $view_mode, array $configuration = []) {
    // Configure display settings for different view modes.
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('media.' . $type->id() . '.' . $view_mode);
    
    if (!$display) {
      $display = $this->entityTypeManager->getStorage('entity_view_display')->create([
        'targetEntityType' => 'media',
        'bundle' => $type->id(),
        'mode' => $view_mode,
        'status' => TRUE,
      ]);
    }

    // Configure field display settings.
    $display->setComponent('thumbnail', [
      'type' => 'image',
      'settings' => [
        'image_style' => $view_mode === 'thumbnail' ? 'thumbnail' : 'medium',
        'image_link' => '',
      ],
      'weight' => 0,
    ]);

    $display->save();
  }
}