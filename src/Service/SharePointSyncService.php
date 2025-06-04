<?php

namespace Drupal\sharepoint_media\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\media\Entity\Media;
use GuzzleHttp\ClientInterface;

/**
 * Service for syncing SharePoint drive items with Drupal media entities.
 */
class SharePointSyncService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The Graph API client.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient
   */
  protected $graphClient;

  /**
   * The metadata extractor.
   *
   * @var \Drupal\sharepoint_media\Service\MetadataExtractor
   */
  protected $metadataExtractor;

  /**
   * Constructs a new SharePointSyncService.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client, CacheBackendInterface $cache) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->httpClient = $http_client;
    $this->cache = $cache;
  }

  /**
   * Set the Graph API client.
   */
  public function setGraphClient(GraphApiClient $graph_client) {
    $this->graphClient = $graph_client;
  }

  /**
   * Set the metadata extractor.
   */
  public function setMetadataExtractor(MetadataExtractor $metadata_extractor) {
    $this->metadataExtractor = $metadata_extractor;
  }

  /**
   * Sync SharePoint drive items as media entities.
   *
   * @param string $drive_id
   *   The SharePoint drive ID.
   * @param string $folder_path
   *   The folder path to sync (default: root).
   * @param array $options
   *   Sync options.
   *
   * @return array
   *   Sync results with counts and errors.
   */
  public function syncDriveItems($drive_id, $folder_path = '/', array $options = []) {
    $results = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => [],
      'processed_items' => [],
    ];

    $options += [
      'recursive' => TRUE,
      'file_types' => [], // Empty means all types
      'max_file_size' => 0, // 0 means no limit
      'update_existing' => TRUE,
      'batch_size' => 50,
    ];

    try {
      $logger = $this->loggerFactory->get('sharepoint_media');
      $logger->info('Starting sync for drive @drive, path @path', [
        '@drive' => $drive_id,
        '@path' => $folder_path,
      ]);

      // Get drive items from SharePoint.
      $drive_items = $this->getDriveItems($drive_id, $folder_path, $options);

      foreach ($drive_items as $item) {
        try {
          if (!$this->shouldProcessItem($item, $options)) {
            $results['skipped']++;
            continue;
          }

          $result = $this->createOrUpdateMediaEntity($item, $options);
          
          if ($result['action'] === 'created') {
            $results['created']++;
          }
          elseif ($result['action'] === 'updated') {
            $results['updated']++;
          }
          else {
            $results['skipped']++;
          }

          $results['processed_items'][] = [
            'drive_item_id' => $item['id'],
            'name' => $item['name'],
            'action' => $result['action'],
            'media_id' => $result['media_id'] ?? NULL,
          ];

        }
        catch (\Exception $e) {
          $results['errors'][] = [
            'drive_item_id' => $item['id'] ?? 'unknown',
            'name' => $item['name'] ?? 'unknown',
            'error' => $e->getMessage(),
          ];
          
          $logger->error('Failed to sync item @name: @message', [
            '@name' => $item['name'] ?? 'unknown',
            '@message' => $e->getMessage(),
          ]);
        }
      }

      $logger->info('Sync completed. Created: @created, Updated: @updated, Skipped: @skipped, Errors: @errors', [
        '@created' => $results['created'],
        '@updated' => $results['updated'],
        '@skipped' => $results['skipped'],
        '@errors' => count($results['errors']),
      ]);

    }
    catch (\Exception $e) {
      $results['errors'][] = [
        'drive_item_id' => 'sync_operation',
        'name' => 'Sync operation',
        'error' => $e->getMessage(),
      ];
      
      $this->loggerFactory->get('sharepoint_media')->error('Sync operation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Get drive items from SharePoint.
   */
  protected function getDriveItems($drive_id, $folder_path, array $options) {
    $all_items = [];
    $next_link = NULL;

    do {
      $endpoint = $next_link ?: "/drives/{$drive_id}/root:/{$folder_path}:/children";
      
      $query_params = [
        'select' => 'id,name,file,folder,size,createdBy,modifiedBy,createdDateTime,lastModifiedDateTime,webUrl,@microsoft.graph.downloadUrl',
        'expand' => 'thumbnails',
        '$top' => $options['batch_size'],
      ];

      $response = $this->graphClient->request('GET', $endpoint, [
        'query' => $query_params,
      ]);

      $data = json_decode($response->getBody(), TRUE);
      
      if (isset($data['value'])) {
        foreach ($data['value'] as $item) {
          // Only process files, not folders (unless recursive and we want to process folders).
          if (isset($item['file'])) {
            $all_items[] = $item;
          }
          elseif (isset($item['folder']) && $options['recursive']) {
            // Recursively get items from subfolders.
            $subfolder_path = $folder_path . '/' . $item['name'];
            $subfolder_items = $this->getDriveItems($drive_id, $subfolder_path, $options);
            $all_items = array_merge($all_items, $subfolder_items);
          }
        }
      }

      $next_link = $data['@odata.nextLink'] ?? NULL;
      
    } while ($next_link && count($all_items) < 1000); // Safety limit

    return $all_items;
  }

  /**
   * Check if an item should be processed based on options.
   */
  protected function shouldProcessItem(array $item, array $options) {
    // Check file type filter.
    if (!empty($options['file_types'])) {
      $mime_type = $item['file']['mimeType'] ?? '';
      $allowed = FALSE;
      
      foreach ($options['file_types'] as $type) {
        if (strpos($mime_type, $type) === 0) {
          $allowed = TRUE;
          break;
        }
      }
      
      if (!$allowed) {
        return FALSE;
      }
    }

    // Check file size limit.
    if ($options['max_file_size'] > 0) {
      $file_size = $item['size'] ?? 0;
      if ($file_size > $options['max_file_size']) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Create or update media entity from SharePoint item.
   */
  protected function createOrUpdateMediaEntity(array $drive_item, array $options) {
    $media_storage = $this->entityTypeManager->getStorage('media');
    
    // Check if media entity already exists.
    $existing = $media_storage->loadByProperties([
      'field_sharepoint_drive_item_id' => $drive_item['id'],
    ]);

    $is_update = !empty($existing);
    
    if ($is_update) {
      if (!$options['update_existing']) {
        return ['action' => 'skipped'];
      }
      $media = reset($existing);
    }
    else {
      $media = Media::create([
        'bundle' => 'sharepoint_file',
        'uid' => 1, // System user
        'status' => TRUE,
      ]);
    }

    // Map SharePoint metadata to Drupal fields.
    $this->mapDriveItemToMedia($media, $drive_item);

    // Extract and set additional metadata.
    if ($this->metadataExtractor) {
      $additional_metadata = $this->metadataExtractor->extractMetadata($drive_item);
      $this->setAdditionalMetadata($media, $additional_metadata);
    }

    $media->save();

    return [
      'action' => $is_update ? 'updated' : 'created',
      'media_id' => $media->id(),
    ];
  }

  /**
   * Map SharePoint drive item data to media entity fields.
   */
  protected function mapDriveItemToMedia(Media $media, array $drive_item) {
    // Basic fields.
    $media->set('name', $drive_item['name']);
    $media->set('field_sharepoint_drive_item_id', $drive_item['id']);
    
    // URLs and paths.
    if (isset($drive_item['@microsoft.graph.downloadUrl'])) {
      $media->set('field_download_url', $drive_item['@microsoft.graph.downloadUrl']);
    }
    
    if (isset($drive_item['webUrl'])) {
      $media->set('field_web_url', $drive_item['webUrl']);
      $media->set('field_sharepoint_path', $this->extractPath($drive_item['webUrl']));
    }

    // File properties.
    if (isset($drive_item['file']['mimeType'])) {
      $media->set('field_mime_type', $drive_item['file']['mimeType']);
    }
    
    if (isset($drive_item['size'])) {
      $media->set('field_file_size', $drive_item['size']);
    }

    // User information.
    if (isset($drive_item['createdBy']['user']['displayName'])) {
      $media->set('field_created_by', $drive_item['createdBy']['user']['displayName']);
    }
    
    if (isset($drive_item['lastModifiedBy']['user']['displayName'])) {
      $media->set('field_modified_by', $drive_item['lastModifiedBy']['user']['displayName']);
    }

    // Dates.
    if (isset($drive_item['createdDateTime'])) {
      $media->set('field_created_date', $drive_item['createdDateTime']);
    }
    
    if (isset($drive_item['lastModifiedDateTime'])) {
      $media->set('field_modified_date', $drive_item['lastModifiedDateTime']);
    }

    // Thumbnails.
    if (!empty($drive_item['thumbnails'])) {
      $thumbnail_url = $drive_item['thumbnails'][0]['large']['url'] ?? '';
      if ($thumbnail_url) {
        $media->set('field_thumbnail_url', $thumbnail_url);
      }
    }
  }

  /**
   * Set additional metadata based on file type.
   */
  protected function setAdditionalMetadata(Media $media, array $metadata) {
    foreach ($metadata as $field_name => $value) {
      if ($media->hasField($field_name) && $value !== NULL) {
        $media->set($field_name, $value);
      }
    }
  }

  /**
   * Extract SharePoint path from web URL.
   */
  protected function extractPath($web_url) {
    if (empty($web_url)) {
      return '';
    }

    $parsed = parse_url($web_url);
    $path = $parsed['path'] ?? '';
    
    // Remove common SharePoint URL patterns to get clean path.
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
   * Refresh expired download URLs.
   */
  public function refreshExpiredUrls() {
    $media_storage = $this->entityTypeManager->getStorage('media');
    
    // Find SharePoint media entities with old download URLs.
    $query = $media_storage->getQuery()
      ->condition('bundle', 'sharepoint_file')
      ->condition('field_download_url', '', '<>')
      ->accessCheck(FALSE)
      ->range(0, 100); // Process in batches

    $entity_ids = $query->execute();
    
    if (empty($entity_ids)) {
      return;
    }

    $media_entities = $media_storage->loadMultiple($entity_ids);
    $refreshed = 0;

    foreach ($media_entities as $media) {
      try {
        $drive_item_id = $media->get('field_sharepoint_drive_item_id')->value;
        
        if ($drive_item_id) {
          // Get fresh download URL.
          $fresh_url = $this->graphClient->getFreshDownloadUrl($drive_item_id);
          
          if ($fresh_url) {
            $media->set('field_download_url', $fresh_url);
            $media->save();
            $refreshed++;
          }
        }
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('sharepoint_media')->error('Failed to refresh URL for media @id: @message', [
          '@id' => $media->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($refreshed > 0) {
      $this->loggerFactory->get('sharepoint_media')->info('Refreshed @count download URLs', [
        '@count' => $refreshed,
      ]);
    }
  }

  /**
   * Sync all configured drives.
   */
  public function syncConfiguredDrives() {
    $config = $this->configFactory->get('sharepoint_media.settings');
    $drives = $config->get('sync_drives') ?: [];

    foreach ($drives as $drive_config) {
      if (!empty($drive_config['enabled'])) {
        $this->syncDriveItems(
          $drive_config['drive_id'],
          $drive_config['folder_path'] ?? '/',
          $drive_config['options'] ?? []
        );
      }
    }
  }

  /**
   * Get sync statistics.
   */
  public function getSyncStatistics() {
    $media_storage = $this->entityTypeManager->getStorage('media');
    
    $query = $media_storage->getQuery()
      ->condition('bundle', 'sharepoint_file')
      ->accessCheck(FALSE);
    
    $total_count = $query->count()->execute();

    // Get counts by file type.
    $type_counts = [];
    $mime_types = [
      'image' => 'image/',
      'video' => 'video/',
      'audio' => 'audio/',
      'document' => 'application/',
    ];

    foreach ($mime_types as $type => $prefix) {
      $query = $media_storage->getQuery()
        ->condition('bundle', 'sharepoint_file')
        ->condition('field_mime_type', $prefix, 'STARTS_WITH')
        ->accessCheck(FALSE);
      
      $type_counts[$type] = $query->count()->execute();
    }

    // Get recent sync activity.
    $recent_query = $media_storage->getQuery()
      ->condition('bundle', 'sharepoint_file')
      ->condition('changed', strtotime('-24 hours'), '>')
      ->accessCheck(FALSE);
    
    $recent_count = $recent_query->count()->execute();

    return [
      'total_media' => $total_count,
      'by_type' => $type_counts,
      'recent_updates' => $recent_count,
      'last_sync' => \Drupal::state()->get('sharepoint_media.last_auto_sync', 0),
    ];
  }
}