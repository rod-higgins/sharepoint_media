<?php

namespace Drupal\sharepoint_media\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sharepoint_media\Service\SharePointSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for SharePoint media sync operations.
 */
class SyncController extends ControllerBase {

  /**
   * The sync service.
   *
   * @var \Drupal\sharepoint_media\Service\SharePointSyncService
   */
  protected $syncService;

  /**
   * Constructs a new SyncController.
   */
  public function __construct(SharePointSyncService $sync_service) {
    $this->syncService = $sync_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sharepoint_media.sync_service')
    );
  }

  /**
   * Manual sync operation.
   */
  public function sync(Request $request) {
    // Check if this is an AJAX request.
    $is_ajax = $request->isXmlHttpRequest();
    
    // Get sync parameters.
    $drive_id = $request->request->get('drive_id');
    $folder_path = $request->request->get('folder_path', '/');
    $options = [
      'recursive' => $request->request->get('recursive', TRUE),
      'update_existing' => $request->request->get('update_existing', TRUE),
      'file_types' => $request->request->all('file_types'),
      'max_file_size' => $request->request->get('max_file_size', 0),
      'batch_size' => $request->request->get('batch_size', 50),
    ];

    // Validate parameters.
    if (empty($drive_id)) {
      $message = $this->t('Drive ID is required for sync operation.');
      
      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $message,
        ], 400);
      }
      
      $this->messenger()->addError($message);
      return $this->redirect('sharepoint_media.settings');
    }

    try {
      // Run the sync operation.
      $start_time = microtime(TRUE);
      $results = $this->syncService->syncDriveItems($drive_id, $folder_path, $options);
      $end_time = microtime(TRUE);
      $duration = round($end_time - $start_time, 2);

      // Prepare response data.
      $response_data = [
        'success' => TRUE,
        'results' => $results,
        'duration' => $duration,
        'summary' => $this->formatSyncSummary($results, $duration),
      ];

      if ($is_ajax) {
        return new JsonResponse($response_data);
      }

      // For non-AJAX requests, show messages and redirect.
      $this->displaySyncMessages($results, $duration);
      return $this->redirect('sharepoint_media.search');

    }
    catch (\Exception $e) {
      $error_message = $this->t('Sync operation failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->getLogger('sharepoint_media')->error('Manual sync failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $error_message,
        ], 500);
      }

      $this->messenger()->addError($error_message);
      return $this->redirect('sharepoint_media.settings');
    }
  }

  /**
   * Get sync status/progress.
   */
  public function syncStatus(Request $request) {
    $statistics = $this->syncService->getSyncStatistics();
    
    // Get last sync information.
    $last_sync = \Drupal::state()->get('sharepoint_media.last_auto_sync', 0);
    $next_sync = $this->calculateNextSyncTime();
    
    $status_data = [
      'statistics' => $statistics,
      'last_sync' => $last_sync,
      'last_sync_formatted' => $last_sync ? $this->formatDate($last_sync) : $this->t('Never'),
      'next_sync' => $next_sync,
      'next_sync_formatted' => $next_sync ? $this->formatDate($next_sync) : $this->t('Not scheduled'),
      'auto_sync_enabled' => $this->config('sharepoint_media.settings')->get('auto_sync_enabled'),
    ];

    return new JsonResponse($status_data);
  }

  /**
   * Refresh expired URLs.
   */
  public function refreshUrls(Request $request) {
    $is_ajax = $request->isXmlHttpRequest();

    try {
      $start_time = microtime(TRUE);
      $this->syncService->refreshExpiredUrls();
      $end_time = microtime(TRUE);
      $duration = round($end_time - $start_time, 2);

      $message = $this->t('Download URLs refreshed successfully in @duration seconds.', [
        '@duration' => $duration,
      ]);

      if ($is_ajax) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => $message,
          'duration' => $duration,
        ]);
      }

      $this->messenger()->addStatus($message);
      return $this->redirect('sharepoint_media.search');

    }
    catch (\Exception $e) {
      $error_message = $this->t('Failed to refresh URLs: @message', [
        '@message' => $e->getMessage(),
      ]);

      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $error_message,
        ], 500);
      }

      $this->messenger()->addError($error_message);
      return $this->redirect('sharepoint_media.search');
    }
  }

  /**
   * Get available drives for sync.
   */
  public function getDrives(Request $request) {
    try {
      $graph_client = \Drupal::service('sharepoint_media.graph_client');
      $drives = $graph_client->getDrives();
      
      $formatted_drives = [];
      foreach ($drives as $drive) {
        $formatted_drives[] = [
          'id' => $drive['id'],
          'name' => $drive['name'],
          'type' => $drive['driveType'],
          'description' => $drive['description'] ?? '',
          'web_url' => $drive['webUrl'] ?? '',
        ];
      }

      return new JsonResponse([
        'success' => TRUE,
        'drives' => $formatted_drives,
      ]);

    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Failed to load drives: @message', [
          '@message' => $e->getMessage(),
        ]),
      ], 500);
    }
  }

  /**
   * Get folders within a drive.
   */
  public function getDriveFolders(Request $request) {
    $drive_id = $request->query->get('drive_id');
    $folder_path = $request->query->get('folder_path', '/');

    if (empty($drive_id)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Drive ID is required.'),
      ], 400);
    }

    try {
      $graph_client = \Drupal::service('sharepoint_media.graph_client');
      $folders = $graph_client->getDriveFolders($drive_id, $folder_path);
      
      $formatted_folders = [];
      foreach ($folders as $folder) {
        $formatted_folders[] = [
          'id' => $folder['id'],
          'name' => $folder['name'],
          'path' => trim($folder_path . '/' . $folder['name'], '/'),
          'web_url' => $folder['webUrl'] ?? '',
          'has_children' => isset($folder['folder']['childCount']) && $folder['folder']['childCount'] > 0,
        ];
      }

      return new JsonResponse([
        'success' => TRUE,
        'folders' => $formatted_folders,
      ]);

    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Failed to load folders: @message', [
          '@message' => $e->getMessage(),
        ]),
      ], 500);
    }
  }

  /**
   * Validate sync configuration.
   */
  public function validateSync(Request $request) {
    $config = $this->config('sharepoint_media.settings');
    $validation = [
      'valid' => TRUE,
      'issues' => [],
    ];

    // Check connection settings.
    if (empty($config->get('tenant_id'))) {
      $validation['valid'] = FALSE;
      $validation['issues'][] = $this->t('Tenant ID is not configured.');
    }

    if (empty($config->get('client_id'))) {
      $validation['valid'] = FALSE;
      $validation['issues'][] = $this->t('Client ID is not configured.');
    }

    if (empty($config->get('client_secret'))) {
      $validation['valid'] = FALSE;
      $validation['issues'][] = $this->t('Client secret is not configured.');
    }

    // Test connection if all settings are present.
    if ($validation['valid']) {
      try {
        $graph_client = \Drupal::service('sharepoint_media.graph_client');
        $connection_test = $graph_client->testConnection();
        
        if (!$connection_test) {
          $validation['valid'] = FALSE;
          $validation['issues'][] = $this->t('Cannot connect to Microsoft Graph API.');
        }
      }
      catch (\Exception $e) {
        $validation['valid'] = FALSE;
        $validation['issues'][] = $this->t('Connection test failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Check Search API configuration.
    $index = \Drupal\search_api\Entity\Index::load('sharepoint_media');
    if (!$index) {
      $validation['issues'][] = $this->t('Search index is not configured. Media search may not work properly.');
    }
    elseif (!$index->status()) {
      $validation['issues'][] = $this->t('Search index is disabled. Media search will not work.');
    }

    return new JsonResponse($validation);
  }

  /**
   * Clear sync cache.
   */
  public function clearCache(Request $request) {
    try {
      // Clear various caches.
      \Drupal::cache()->deleteAll();
      \Drupal::cache('data')->deleteAll();
      
      // Clear URL cache.
      $url_manager = \Drupal::service('sharepoint_media.url_manager');
      $url_manager->clearUrlCache();

      $message = $this->t('SharePoint media cache cleared successfully.');

      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => $message,
        ]);
      }

      $this->messenger()->addStatus($message);
      return $this->redirect('sharepoint_media.settings');

    }
    catch (\Exception $e) {
      $error_message = $this->t('Failed to clear cache: @message', [
        '@message' => $e->getMessage(),
      ]);

      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $error_message,
        ], 500);
      }

      $this->messenger()->addError($error_message);
      return $this->redirect('sharepoint_media.settings');
    }
  }

  /**
   * Format sync summary for display.
   */
  protected function formatSyncSummary(array $results, $duration) {
    $total_processed = $results['created'] + $results['updated'] + $results['skipped'];
    
    $summary = $this->t('Sync completed in @duration seconds. Processed @total items: @created created, @updated updated, @skipped skipped.', [
      '@duration' => $duration,
      '@total' => $total_processed,
      '@created' => $results['created'],
      '@updated' => $results['updated'],
      '@skipped' => $results['skipped'],
    ]);

    if (!empty($results['errors'])) {
      $summary .= ' ' . $this->t('@count errors occurred.', [
        '@count' => count($results['errors']),
      ]);
    }

    return $summary;
  }

  /**
   * Display sync messages to user.
   */
  protected function displaySyncMessages(array $results, $duration) {
    $total_processed = $results['created'] + $results['updated'] + $results['skipped'];

    if ($total_processed > 0) {
      $this->messenger()->addStatus($this->formatSyncSummary($results, $duration));
    }
    else {
      $this->messenger()->addWarning($this->t('No items were processed during sync.'));
    }

    // Show errors if any.
    if (!empty($results['errors'])) {
      foreach (array_slice($results['errors'], 0, 5) as $error) { // Show max 5 errors
        $this->messenger()->addError($this->t('Error with @name: @error', [
          '@name' => $error['name'],
          '@error' => $error['error'],
        ]));
      }

      if (count($results['errors']) > 5) {
        $remaining = count($results['errors']) - 5;
        $this->messenger()->addWarning($this->t('... and @count more errors. Check logs for details.', [
          '@count' => $remaining,
        ]));
      }
    }
  }

  /**
   * Calculate next sync time.
   */
  protected function calculateNextSyncTime() {
    $config = $this->config('sharepoint_media.settings');
    
    if (!$config->get('auto_sync_enabled')) {
      return NULL;
    }

    $last_sync = \Drupal::state()->get('sharepoint_media.last_auto_sync', 0);
    $interval = $config->get('auto_sync_interval') ?: 3600;
    
    return $last_sync + $interval;
  }

  /**
   * Format timestamp for display.
   */
  protected function formatDate($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
      return $this->t('Just now');
    }
    elseif ($diff < 3600) {
      $minutes = floor($diff / 60);
      return $this->t('@count minutes ago', ['@count' => $minutes]);
    }
    elseif ($diff < 86400) {
      $hours = floor($diff / 3600);
      return $this->t('@count hours ago', ['@count' => $hours]);
    }
    else {
      return \Drupal::service('date.formatter')->format($timestamp, 'medium');
    }
  }
}