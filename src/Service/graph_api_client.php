<?php

namespace Drupal\sharepoint_media\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Service for Microsoft Graph API interactions.
 */
class GraphApiClient {

  /**
   * The Graph API base URL.
   */
  const GRAPH_API_BASE_URL = 'https://graph.microsoft.com/v1.0';

  /**
   * The Microsoft identity platform token endpoint.
   */
  const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current access token.
   *
   * @var string
   */
  protected $accessToken;

  /**
   * Constructs a new GraphApiClient.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, CacheBackendInterface $cache, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->cache = $cache;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get the HTTP client.
   */
  public function getHttpClient() {
    return $this->httpClient;
  }

  /**
   * Get an access token for Graph API.
   */
  protected function getAccessToken() {
    if ($this->accessToken) {
      return $this->accessToken;
    }

    // Check cache first.
    $cache_key = 'sharepoint_media:access_token';
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data && $cached->expire > time()) {
      $this->accessToken = $cached->data;
      return $this->accessToken;
    }

    // Get new token.
    $config = $this->configFactory->get('sharepoint_media.settings');
    $tenant_id = $config->get('tenant_id');
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');

    if (empty($tenant_id) || empty($client_id) || empty($client_secret)) {
      throw new \Exception('SharePoint Media module is not properly configured. Please configure tenant ID, client ID, and client secret.');
    }

    $token_url = sprintf(self::TOKEN_ENDPOINT, $tenant_id);

    try {
      $response = $this->httpClient->post($token_url, [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'scope' => 'https://graph.microsoft.com/.default',
          'grant_type' => 'client_credentials',
        ],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      
      if (isset($data['access_token'])) {
        $this->accessToken = $data['access_token'];
        $expires_in = $data['expires_in'] ?? 3600;
        
        // Cache token with some buffer time.
        $this->cache->set($cache_key, $this->accessToken, time() + $expires_in - 300);
        
        return $this->accessToken;
      }
      else {
        throw new \Exception('Failed to obtain access token: ' . $response->getBody());
      }
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get access token: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to authenticate with Microsoft Graph API: ' . $e->getMessage());
    }
  }

  /**
   * Make a request to the Graph API.
   *
   * @param string $method
   *   HTTP method.
   * @param string $endpoint
   *   API endpoint.
   * @param array $options
   *   Request options.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  public function request($method, $endpoint, array $options = []) {
    $url = self::GRAPH_API_BASE_URL . '/' . ltrim($endpoint, '/');
    
    $default_options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->getAccessToken(),
        'Content-Type' => 'application/json',
      ],
    ];

    $options = array_merge_recursive($default_options, $options);

    try {
      $response = $this->httpClient->request($method, $url, $options);
      
      // Log successful requests in debug mode.
      if ($this->configFactory->get('sharepoint_media.settings')->get('debug_mode')) {
        $this->loggerFactory->get('sharepoint_media')->debug('Graph API request: @method @url', [
          '@method' => $method,
          '@url' => $url,
        ]);
      }
      
      return $response;
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Graph API request failed: @method @url - @message', [
        '@method' => $method,
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      
      // If token expired, clear cache and retry once.
      if ($e->getCode() === 401) {
        $this->cache->delete('sharepoint_media:access_token');
        $this->accessToken = NULL;
        
        // Retry once with fresh token.
        $options['headers']['Authorization'] = 'Bearer ' . $this->getAccessToken();
        
        try {
          return $this->httpClient->request($method, $url, $options);
        }
        catch (GuzzleException $retry_exception) {
          throw $retry_exception;
        }
      }
      
      throw $e;
    }
  }

  /**
   * Get a specific drive item.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   *
   * @return array|null
   *   The drive item data or NULL if not found.
   */
  public function getDriveItem($drive_item_id) {
    $cache_key = 'sharepoint_media:drive_item:' . $drive_item_id;
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data && $cached->expire > time()) {
      return $cached->data;
    }

    try {
      $response = $this->request('GET', "/drives/items/{$drive_item_id}", [
        'query' => [
          'select' => 'id,name,file,folder,size,createdBy,modifiedBy,createdDateTime,lastModifiedDateTime,webUrl,@microsoft.graph.downloadUrl,image,video,photo',
          'expand' => 'thumbnails',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      
      if ($data) {
        // Cache for 1 hour.
        $this->cache->set($cache_key, $data, time() + 3600);
      }
      
      return $data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get drive item @id: @message', [
        '@id' => $drive_item_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get a fresh download URL for a drive item.
   *
   * @param string $drive_item_id
   *   The drive item ID.
   *
   * @return string|null
   *   The download URL or NULL if not available.
   */
  public function getFreshDownloadUrl($drive_item_id) {
    try {
      $response = $this->request('GET', "/drives/items/{$drive_item_id}", [
        'query' => [
          'select' => '@microsoft.graph.downloadUrl',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return $data['@microsoft.graph.downloadUrl'] ?? NULL;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get download URL for item @id: @message', [
        '@id' => $drive_item_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Search for drive items.
   *
   * @param string $query
   *   Search query.
   * @param array $options
   *   Search options.
   *
   * @return array
   *   Search results.
   */
  public function searchDriveItems($query, array $options = []) {
    $default_options = [
      'drive_id' => NULL,
      'top' => 50,
      'select' => 'id,name,file,size,createdBy,lastModifiedDateTime,webUrl',
    ];

    $options = array_merge($default_options, $options);

    $endpoint = $options['drive_id'] 
      ? "/drives/{$options['drive_id']}/search(q='{$query}')"
      : "/me/drive/search(q='{$query}')";

    try {
      $response = $this->request('GET', $endpoint, [
        'query' => [
          '$top' => $options['top'],
          '$select' => $options['select'],
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return $data['value'] ?? [];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Search failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get available drives.
   *
   * @return array
   *   Array of available drives.
   */
  public function getDrives() {
    $cache_key = 'sharepoint_media:drives';
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $cached->data && $cached->expire > time()) {
      return $cached->data;
    }

    try {
      $response = $this->request('GET', '/sites/root/drives', [
        'query' => [
          'select' => 'id,name,description,driveType,webUrl',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $drives = $data['value'] ?? [];
      
      // Cache for 4 hours.
      $this->cache->set($cache_key, $drives, time() + 14400);
      
      return $drives;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get drives: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get drive folders.
   *
   * @param string $drive_id
   *   The drive ID.
   * @param string $folder_path
   *   The folder path.
   *
   * @return array
   *   Array of folders.
   */
  public function getDriveFolders($drive_id, $folder_path = '/') {
    try {
      $endpoint = "/drives/{$drive_id}/root:/{$folder_path}:/children";
      
      $response = $this->request('GET', $endpoint, [
        'query' => [
          '$filter' => 'folder ne null',
          'select' => 'id,name,folder,webUrl',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return $data['value'] ?? [];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get folders for drive @drive: @message', [
        '@drive' => $drive_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Test the API connection.
   *
   * @return bool
   *   TRUE if connection is successful.
   */
  public function testConnection() {
    try {
      $response = $this->request('GET', '/me/drive');
      return $response->getStatusCode() === 200;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get usage statistics.
   *
   * @return array
   *   Usage statistics.
   */
  public function getUsageStats() {
    try {
      $response = $this->request('GET', '/me/drive', [
        'query' => [
          'select' => 'quota',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return $data['quota'] ?? [];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sharepoint_media')->error('Failed to get usage stats: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }
}