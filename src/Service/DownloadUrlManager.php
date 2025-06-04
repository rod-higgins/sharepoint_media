<?php

namespace Drupal\sharepoint_media\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for managing SharePoint download URLs and their expiration.
 */
class DownloadUrlManager {

  /**
   * Default URL cache duration (50 minutes - URLs expire after ~1 hour).
   */
  const DEFAULT_CACHE_DURATION = 3000;

  /**
   * The Graph API client.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient
   */
  protected $graphClient;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * In-memory cache for URLs within the same request.
   *
   * @var array
   */
  protected $memoryCache = [];

  /**
   * Constructs a new DownloadUrlManager.
   */
  public function __construct(GraphApiClient $graph_client, CacheBackendInterface $cache, LoggerChannelFactoryInterface $logger_factory) {
    $this->graphClient = $graph_client;
    $this->cache = $cache;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get a fresh download URL for a drive item.
   *
   * @param string $drive_item_id
   *   The SharePoint drive item ID.
   * @param bool $force_refresh
   *   Whether to force refresh even if cached.
   *
   * @return string|null
   *   The download URL or NULL if not available.
   */
  public function getFreshDownloadUrl($drive_item_id, $force_refresh = FALSE) {
    // Check memory cache first.
    if (!$force_refresh && isset($this->memoryCache[$drive_item_id])) {
      $cached_data = $this->memoryCache[$drive_item_id];
      if (time() - $cached_data['timestamp'] < self::DEFAULT_CACHE_DURATION) {
        return $cached_data['url'];
      }
    }

    // Check persistent cache.
    $cache_key = $this->getCacheKey($drive_item_id);
    
    if (!$force_refresh) {
      $cached = $this->cache->get($cache_key);
      if ($cached && $cached->data && $cached->expire > time()) {
        $this->memoryCache[$drive_item_id] = [
          'url' => $cached->data,
          'timestamp' => time(),
        ];
        return $cached->data;
      }
    }

    // Fetch fresh URL from Graph API.
    try {
      $fresh_url = $this->graphClient->getFreshDownloadUrl($drive_item_id);
      
      if ($fresh_url) {
        // Cache the URL.
        $this->cacheDownloadUrl($drive_item_id, $fresh_url);
        
        $this->loggerFactory->get('sharepoint_media')->debug('Refreshed download URL for item @id', [
          '@id' => $drive_item_id,
        ]);
        
        return $fresh_url;
      }
      else {
        $this->loggerFactory->get('sharepoint_media')->warning('No download URL available for item @id', [
          '@id' => $drive_item_id,
        ]);
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get fresh download URL for item @id: @message', [
        '@id' => $drive_item_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Cache a download URL.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   * @param string $download_url
   *   The download URL.
   * @param int $cache_duration
   *   Cache duration in seconds.
   */
  public function cacheDownloadUrl($drive_item_id, $download_url, $cache_duration = NULL) {
    $cache_duration = $cache_duration ?: self::DEFAULT_CACHE_DURATION;
    $cache_key = $this->getCacheKey($drive_item_id);
    
    // Cache persistently.
    $this->cache->set($cache_key, $download_url, time() + $cache_duration);
    
    // Cache in memory.
    $this->memoryCache[$drive_item_id] = [
      'url' => $download_url,
      'timestamp' => time(),
    ];
  }

  /**
   * Get multiple download URLs efficiently.
   *
   * @param array $drive_item_ids
   *   Array of drive item IDs.
   * @param bool $force_refresh
   *   Whether to force refresh all URLs.
   *
   * @return array
   *   Array of drive_item_id => download_url.
   */
  public function getMultipleDownloadUrls(array $drive_item_ids, $force_refresh = FALSE) {
    $urls = [];
    $items_to_fetch = [];

    // First, check cache for all items.
    foreach ($drive_item_ids as $drive_item_id) {
      if (!$force_refresh) {
        $cached_url = $this->getCachedUrl($drive_item_id);
        if ($cached_url) {
          $urls[$drive_item_id] = $cached_url;
          continue;
        }
      }
      
      $items_to_fetch[] = $drive_item_id;
    }

    // Fetch URLs for items not in cache.
    if (!empty($items_to_fetch)) {
      foreach ($items_to_fetch as $drive_item_id) {
        $url = $this->getFreshDownloadUrl($drive_item_id, $force_refresh);
        if ($url) {
          $urls[$drive_item_id] = $url;
        }
      }
    }

    return $urls;
  }

  /**
   * Get cached URL without fetching fresh one.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   *
   * @return string|null
   *   Cached URL or NULL.
   */
  protected function getCachedUrl($drive_item_id) {
    // Check memory cache first.
    if (isset($this->memoryCache[$drive_item_id])) {
      $cached_data = $this->memoryCache[$drive_item_id];
      if (time() - $cached_data['timestamp'] < self::DEFAULT_CACHE_DURATION) {
        return $cached_data['url'];
      }
    }

    // Check persistent cache.
    $cache_key = $this->getCacheKey($drive_item_id);
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data && $cached->expire > time()) {
      // Update memory cache.
      $this->memoryCache[$drive_item_id] = [
        'url' => $cached->data,
        'timestamp' => time(),
      ];
      
      return $cached->data;
    }

    return NULL;
  }

  /**
   * Check if a download URL is likely expired.
   *
   * @param string $download_url
   *   The download URL to check.
   *
   * @return bool
   *   TRUE if the URL appears expired.
   */
  public function isUrlExpired($download_url) {
    if (empty($download_url)) {
      return TRUE;
    }

    // SharePoint download URLs contain a token with expiration info.
    // Extract the se parameter which indicates expiration.
    $parsed = parse_url($download_url);
    if (isset($parsed['query'])) {
      parse_str($parsed['query'], $params);
      
      if (isset($params['se'])) {
        $expiry_time = strtotime($params['se']);
        // Consider expired if less than 5 minutes remaining.
        return $expiry_time < (time() + 300);
      }
    }

    // If we can't determine expiration, assume it might be expired.
    return TRUE;
  }

  /**
   * Refresh expired URLs for media entities.
   *
   * @param array $media_entities
   *   Array of media entities to check.
   *
   * @return int
   *   Number of URLs refreshed.
   */
  public function refreshExpiredUrlsForEntities(array $media_entities) {
    $refreshed = 0;

    foreach ($media_entities as $media) {
      if ($media->bundle() !== 'sharepoint_file') {
        continue;
      }

      $current_url = $media->get('field_download_url')->value;
      $drive_item_id = $media->get('field_sharepoint_drive_item_id')->value;

      if (!$drive_item_id) {
        continue;
      }

      // Check if URL needs refreshing.
      if (empty($current_url) || $this->isUrlExpired($current_url)) {
        try {
          $fresh_url = $this->getFreshDownloadUrl($drive_item_id, TRUE);
          
          if ($fresh_url && $fresh_url !== $current_url) {
            $media->set('field_download_url', $fresh_url);
            $media->save();
            $refreshed++;
          }
        }
        catch (\Exception $e) {
          $this->loggerFactory->get('sharepoint_media')->error('Failed to refresh URL for media @id: @message', [
            '@id' => $media->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return $refreshed;
  }

  /**
   * Clear cached URLs.
   *
   * @param array $drive_item_ids
   *   Optional array of specific drive item IDs to clear.
   */
  public function clearUrlCache(array $drive_item_ids = []) {
    if (empty($drive_item_ids)) {
      // Clear all cached URLs.
      $this->cache->deleteMultiple($this->cache->getMultiple(['sharepoint_media:download_url:*']));
      $this->memoryCache = [];
    }
    else {
      // Clear specific URLs.
      $cache_keys = [];
      foreach ($drive_item_ids as $drive_item_id) {
        $cache_keys[] = $this->getCacheKey($drive_item_id);
        unset($this->memoryCache[$drive_item_id]);
      }
      $this->cache->deleteMultiple($cache_keys);
    }
  }

  /**
   * Get cache statistics.
   *
   * @return array
   *   Cache statistics.
   */
  public function getCacheStats() {
    $stats = [
      'memory_cached' => count($this->memoryCache),
      'recently_accessed' => 0,
      'cache_hits' => 0,
      'cache_misses' => 0,
    ];

    // Count recently accessed URLs (within last 5 minutes).
    $recent_threshold = time() - 300;
    foreach ($this->memoryCache as $cached_data) {
      if ($cached_data['timestamp'] > $recent_threshold) {
        $stats['recently_accessed']++;
      }
    }

    return $stats;
  }

  /**
   * Generate cache key for a drive item.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   *
   * @return string
   *   The cache key.
   */
  protected function getCacheKey($drive_item_id) {
    return 'sharepoint_media:download_url:' . $drive_item_id;
  }

  /**
   * Warm up cache for multiple drive items.
   *
   * @param array $drive_item_ids
   *   Array of drive item IDs.
   */
  public function warmUpCache(array $drive_item_ids) {
    $chunks = array_chunk($drive_item_ids, 10); // Process in small batches
    
    foreach ($chunks as $chunk) {
      $this->getMultipleDownloadUrls($chunk, FALSE);
      
      // Small delay between batches to avoid rate limiting.
      usleep(100000); // 0.1 seconds
    }
  }

  /**
   * Get proxy URL for streaming content.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   * @param int $media_id
   *   The media entity ID.
   *
   * @return string
   *   The proxy URL for streaming.
   */
  public function getProxyStreamUrl($drive_item_id, $media_id) {
    return \Drupal::url('sharepoint_media.stream', [
      'media_id' => $media_id,
    ], [
      'absolute' => TRUE,
      'query' => [
        'drive_item_id' => $drive_item_id,
        'token' => $this->generateStreamToken($drive_item_id, $media_id),
      ],
    ]);
  }

  /**
   * Generate a secure token for streaming.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   * @param int $media_id
   *   The media entity ID.
   *
   * @return string
   *   The secure token.
   */
  protected function generateStreamToken($drive_item_id, $media_id) {
    $data = $drive_item_id . ':' . $media_id . ':' . time();
    return hash_hmac('sha256', $data, \Drupal::service('private_key')->get());
  }

  /**
   * Validate a streaming token.
   *
   * @param string $token
   *   The token to validate.
   * @param string $drive_item_id
   *   The drive item ID.
   * @param int $media_id
   *   The media entity ID.
   *
   * @return bool
   *   TRUE if token is valid.
   */
  public function validateStreamToken($token, $drive_item_id, $media_id) {
    // For now, we'll implement a simple validation.
    // In production, you might want more sophisticated token validation.
    $expected_prefix = hash_hmac('sha256', $drive_item_id . ':' . $media_id, \Drupal::service('private_key')->get());
    return strpos($token, substr($expected_prefix, 0, 16)) === 0;
  }
}