<?php

namespace Drupal\sharepoint_media\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\sharepoint_media\Service\DownloadUrlManager;
use Drupal\sharepoint_media\Service\GraphApiClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for streaming SharePoint media files.
 */
class StreamController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Graph API client.
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
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new StreamController.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GraphApiClient $graph_client, DownloadUrlManager $url_manager, ClientInterface $http_client) {
    $this->entityTypeManager = $entity_type_manager;
    $this->graphClient = $graph_client;
    $this->urlManager = $url_manager;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('sharepoint_media.graph_client'),
      $container->get('sharepoint_media.url_manager'),
      $container->get('http_client')
    );
  }

  /**
   * Stream a SharePoint media file.
   *
   * @param int $media_id
   *   The media entity ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The streamed response.
   */
  public function stream($media_id, Request $request) {
    $media = $this->loadAndValidateMedia($media_id);
    
    if (!$media) {
      throw new NotFoundHttpException('Media not found.');
    }

    // Get the download URL.
    $drive_item_id = $media->get('field_sharepoint_drive_item_id')->value;
    
    if (!$drive_item_id) {
      throw new NotFoundHttpException('SharePoint drive item ID not found.');
    }

    try {
      $download_url = $this->urlManager->getFreshDownloadUrl($drive_item_id);
      
      if (!$download_url) {
        throw new NotFoundHttpException('Download URL not available.');
      }

      // Get file information.
      $file_size = $media->get('field_file_size')->value ?? 0;
      $mime_type = $media->get('field_mime_type')->value ?? 'application/octet-stream';
      $filename = $media->getName();

      // Handle range requests for video streaming.
      $range = $request->headers->get('Range');
      
      if ($range) {
        return $this->streamPartialContent($download_url, $file_size, $mime_type, $filename, $range);
      }
      else {
        return $this->streamFullContent($download_url, $file_size, $mime_type, $filename);
      }

    }
    catch (\Exception $e) {
      $this->getLogger('sharepoint_media')->error('Failed to stream media @id: @message', [
        '@id' => $media_id,
        '@message' => $e->getMessage(),
      ]);
      
      throw new NotFoundHttpException('Unable to stream file: ' . $e->getMessage());
    }
  }

  /**
   * Download a SharePoint media file.
   *
   * @param int $media_id
   *   The media entity ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The download response.
   */
  public function download($media_id, Request $request) {
    $media = $this->loadAndValidateMedia($media_id);
    
    if (!$media) {
      throw new NotFoundHttpException('Media not found.');
    }

    // Get the download URL.
    $drive_item_id = $media->get('field_sharepoint_drive_item_id')->value;
    
    if (!$drive_item_id) {
      throw new NotFoundHttpException('SharePoint drive item ID not found.');
    }

    try {
      $download_url = $this->urlManager->getFreshDownloadUrl($drive_item_id);
      
      if (!$download_url) {
        throw new NotFoundHttpException('Download URL not available.');
      }

      // Get file information.
      $file_size = $media->get('field_file_size')->value ?? 0;
      $mime_type = $media->get('field_mime_type')->value ?? 'application/octet-stream';
      $filename = $media->getName();

      // Stream the file as a download.
      return $this->streamDownload($download_url, $file_size, $mime_type, $filename);

    }
    catch (\Exception $e) {
      $this->getLogger('sharepoint_media')->error('Failed to download media @id: @message', [
        '@id' => $media_id,
        '@message' => $e->getMessage(),
      ]);
      
      throw new NotFoundHttpException('Unable to download file: ' . $e->getMessage());
    }
  }

  /**
   * Stream full content.
   */
  protected function streamFullContent($download_url, $file_size, $mime_type, $filename) {
    $response = new StreamedResponse();
    
    $response->headers->set('Content-Type', $mime_type);
    $response->headers->set('Content-Length', $file_size);
    $response->headers->set('Accept-Ranges', 'bytes');
    $response->headers->set('Cache-Control', 'public, max-age=3600');
    
    // Add filename for certain file types.
    if ($this->shouldAddFilename($mime_type)) {
      $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    $response->setCallback(function () use ($download_url) {
      $this->streamFromUrl($download_url);
    });

    return $response;
  }

  /**
   * Stream partial content for range requests.
   */
  protected function streamPartialContent($download_url, $file_size, $mime_type, $filename, $range) {
    // Parse range header.
    $ranges = $this->parseRangeHeader($range, $file_size);
    
    if (empty($ranges)) {
      // Invalid range, return full content.
      return $this->streamFullContent($download_url, $file_size, $mime_type, $filename);
    }

    // For simplicity, handle only single range requests.
    $range_info = $ranges[0];
    $start = $range_info['start'];
    $end = $range_info['end'];
    $length = $end - $start + 1;

    $response = new StreamedResponse();
    $response->setStatusCode(206); // Partial Content
    
    $response->headers->set('Content-Type', $mime_type);
    $response->headers->set('Content-Length', $length);
    $response->headers->set('Accept-Ranges', 'bytes');
    $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$file_size}");
    $response->headers->set('Cache-Control', 'public, max-age=3600');

    $response->setCallback(function () use ($download_url, $start, $end) {
      $this->streamRangeFromUrl($download_url, $start, $end);
    });

    return $response;
  }

  /**
   * Stream file as download.
   */
  protected function streamDownload($download_url, $file_size, $mime_type, $filename) {
    $response = new StreamedResponse();
    
    $response->headers->set('Content-Type', 'application/octet-stream');
    $response->headers->set('Content-Length', $file_size);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'private, max-age=0');

    $response->setCallback(function () use ($download_url) {
      $this->streamFromUrl($download_url);
    });

    return $response;
  }

  /**
   * Stream content from URL.
   */
  protected function streamFromUrl($url) {
    try {
      $response = $this->httpClient->get($url, [
        'stream' => TRUE,
        'timeout' => 300, // 5 minutes
        'read_timeout' => 60,
      ]);

      $body = $response->getBody();
      
      while (!$body->eof()) {
        echo $body->read(8192); // Read in 8KB chunks
        
        // Check if client disconnected.
        if (connection_aborted()) {
          break;
        }
        
        // Flush output buffer.
        if (ob_get_level()) {
          ob_flush();
        }
        flush();
      }
    }
    catch (RequestException $e) {
      $this->getLogger('sharepoint_media')->error('Failed to stream from URL: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Send error response.
      http_response_code(500);
      echo 'Error streaming file.';
    }
  }

  /**
   * Stream range from URL.
   */
  protected function streamRangeFromUrl($url, $start, $end) {
    $range_header = "bytes={$start}-{$end}";
    
    try {
      $response = $this->httpClient->get($url, [
        'stream' => TRUE,
        'timeout' => 300,
        'read_timeout' => 60,
        'headers' => [
          'Range' => $range_header,
        ],
      ]);

      $body = $response->getBody();
      
      while (!$body->eof()) {
        echo $body->read(8192);
        
        if (connection_aborted()) {
          break;
        }
        
        if (ob_get_level()) {
          ob_flush();
        }
        flush();
      }
    }
    catch (RequestException $e) {
      $this->getLogger('sharepoint_media')->error('Failed to stream range from URL: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      http_response_code(500);
      echo 'Error streaming file range.';
    }
  }

  /**
   * Parse HTTP Range header.
   */
  protected function parseRangeHeader($range_header, $file_size) {
    $ranges = [];
    
    // Remove 'bytes=' prefix.
    if (strpos($range_header, 'bytes=') === 0) {
      $range_header = substr($range_header, 6);
    }
    
    $range_parts = explode(',', $range_header);
    
    foreach ($range_parts as $range_part) {
      $range_part = trim($range_part);
      
      if (strpos($range_part, '-') !== FALSE) {
        list($start, $end) = explode('-', $range_part, 2);
        
        $start = trim($start);
        $end = trim($end);
        
        // Handle different range formats.
        if ($start === '' && $end !== '') {
          // Suffix-byte-range-spec: -500 (last 500 bytes)
          $start = max(0, $file_size - (int) $end);
          $end = $file_size - 1;
        }
        elseif ($start !== '' && $end === '') {
          // Byte-range-spec without end: 500- (from byte 500 to end)
          $start = (int) $start;
          $end = $file_size - 1;
        }
        elseif ($start !== '' && $end !== '') {
          // Complete byte-range-spec: 0-499
          $start = (int) $start;
          $end = (int) $end;
        }
        else {
          continue; // Invalid range
        }
        
        // Validate range.
        if ($start >= 0 && $end >= $start && $start < $file_size) {
          $end = min($end, $file_size - 1);
          
          $ranges[] = [
            'start' => $start,
            'end' => $end,
          ];
        }
      }
    }
    
    return $ranges;
  }

  /**
   * Load and validate media entity.
   */
  protected function loadAndValidateMedia($media_id) {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media = $media_storage->load($media_id);
    
    if (!$media || $media->bundle() !== 'sharepoint_file') {
      return NULL;
    }
    
    return $media;
  }

  /**
   * Check if filename should be added to response headers.
   */
  protected function shouldAddFilename($mime_type) {
    $inline_types = [
      'image/',
      'video/',
      'audio/',
      'text/',
      'application/pdf',
    ];
    
    foreach ($inline_types as $type) {
      if (strpos($mime_type, $type) === 0) {
        return TRUE;
      }
    }
    
    return FALSE;
  }

  /**
   * Access check for streaming.
   */
  public function streamAccess($media_id, AccountInterface $account) {
    $media = $this->loadAndValidateMedia($media_id);
    
    if (!$media) {
      return AccessResult::forbidden('Media not found or not a SharePoint file.');
    }
    
    // Check if user can view this media entity.
    $access = $media->access('view', $account, TRUE);
    
    if ($access->isAllowed()) {
      // Add cache contexts and tags.
      return $access
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['media:' . $media_id]);
    }
    
    return AccessResult::forbidden('Access denied to media file.');
  }

  /**
   * Get file info for a media entity.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with file information.
   */
  public function fileInfo($media_id) {
    $media = $this->loadAndValidateMedia($media_id);
    
    if (!$media) {
      throw new NotFoundHttpException('Media not found.');
    }

    $file_info = [
      'id' => $media->id(),
      'name' => $media->getName(),
      'mime_type' => $media->get('field_mime_type')->value ?? '',
      'file_size' => $media->get('field_file_size')->value ?? 0,
      'formatted_size' => $this->formatFileSize($media->get('field_file_size')->value ?? 0),
      'created_date' => $media->get('field_created_date')->value ?? '',
      'created_by' => $media->get('field_created_by')->value ?? '',
      'sharepoint_url' => $media->get('field_web_url')->value ?? '',
      'thumbnail_url' => $media->get('field_thumbnail_url')->value ?? '',
    ];

    // Add media-specific metadata.
    if ($media->hasField('field_image_width') && !$media->get('field_image_width')->isEmpty()) {
      $file_info['width'] = $media->get('field_image_width')->value;
      $file_info['height'] = $media->get('field_image_height')->value ?? 0;
    }

    if ($media->hasField('field_duration') && !$media->get('field_duration')->isEmpty()) {
      $duration = $media->get('field_duration')->value;
      $file_info['duration'] = $duration;
      $file_info['formatted_duration'] = $this->formatDuration($duration);
    }

    return $this->jsonResponse($file_info);
  }

  /**
   * Format file size in human readable format.
   */
  protected function formatFileSize($bytes) {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    
    return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
  }

  /**
   * Format duration in human readable format.
   */
  protected function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;

    if ($hours > 0) {
      return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
    }
    else {
      return sprintf('%d:%02d', $minutes, $seconds);
    }
  }
}