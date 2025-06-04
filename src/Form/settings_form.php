<?php

namespace Drupal\sharepoint_media\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sharepoint_media\Service\GraphApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for SharePoint Media settings.
 */
class SharePointMediaSettingsForm extends ConfigFormBase {

  /**
   * The Graph API client.
   *
   * @var \Drupal\sharepoint_media\Service\GraphApiClient
   */
  protected $graphClient;

  /**
   * Constructs a new SharePointMediaSettingsForm.
   */
  public function __construct(ConfigFactoryInterface $config_factory, GraphApiClient $graph_client) {
    parent::__construct($config_factory);
    $this->graphClient = $graph_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('sharepoint_media.graph_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sharepoint_media.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sharepoint_media_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sharepoint_media.settings');

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('SharePoint Connection'),
      '#open' => TRUE,
      '#description' => $this->t('Configure your Microsoft Graph API connection to SharePoint.'),
    ];

    $form['connection']['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant ID'),
      '#description' => $this->t('Your Microsoft 365 tenant ID (GUID).'),
      '#default_value' => $config->get('tenant_id'),
      '#required' => TRUE,
      '#placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    ];

    $form['connection']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID (Application ID)'),
      '#description' => $this->t('The Application (client) ID from your Azure AD app registration.'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
      '#placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    ];

    $form['connection']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('The client secret from your Azure AD app registration. Leave empty to keep existing secret.'),
      '#placeholder' => $config->get('client_secret') ? '••••••••••••••••' : '',
    ];

    $form['connection']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'connection-test-result',
        'method' => 'replace',
      ],
    ];

    $form['connection']['connection_status'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    // Show current connection status.
    if ($config->get('tenant_id') && $config->get('client_id') && $config->get('client_secret')) {
      $form['connection']['connection_status']['#markup'] = '<div class="messages messages--status">' . 
        $this->t('Connection configured. Click "Test Connection" to verify.') . '</div>';
    }

    // Sync Configuration.
    $form['sync'] = [
      '#type' => 'details',
      '#title' => $this->t('Sync Configuration'),
      '#open' => TRUE,
      '#description' => $this->t('Configure how SharePoint content is synchronized.'),
    ];

    $form['sync']['auto_sync_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync'),
      '#description' => $this->t('Automatically sync SharePoint content during cron runs.'),
      '#default_value' => $config->get('auto_sync_enabled') ?? FALSE,
    ];

    $form['sync']['auto_sync_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Auto sync interval'),
      '#description' => $this->t('How often to run automatic sync.'),
      '#options' => [
        1800 => $this->t('Every 30 minutes'),
        3600 => $this->t('Every hour'),
        7200 => $this->t('Every 2 hours'),
        14400 => $this->t('Every 4 hours'),
        28800 => $this->t('Every 8 hours'),
        86400 => $this->t('Daily'),
      ],
      '#default_value' => $config->get('auto_sync_interval') ?? 3600,
      '#states' => [
        'visible' => [
          ':input[name="auto_sync_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['sync']['auto_refresh_urls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-refresh download URLs'),
      '#description' => $this->t('Automatically refresh expired download URLs during cron runs.'),
      '#default_value' => $config->get('auto_refresh_urls') ?? TRUE,
    ];

    $form['sync']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#description' => $this->t('Number of items to process in each sync batch.'),
      '#default_value' => $config->get('batch_size') ?? 50,
      '#min' => 1,
      '#max' => 200,
    ];

    // Drive Configuration.
    $form['drives'] = [
      '#type' => 'details',
      '#title' => $this->t('SharePoint Drives'),
      '#open' => FALSE,
      '#description' => $this->t('Configure which SharePoint drives to sync.'),
    ];

    // Get available drives if connection is configured.
    $available_drives = [];
    if ($config->get('tenant_id') && $config->get('client_id') && $config->get('client_secret')) {
      try {
        $available_drives = $this->graphClient->getDrives();
      }
      catch (\Exception $e) {
        $this->messenger()->addWarning($this->t('Could not load drives. Please check your connection settings.'));
      }
    }

    if (!empty($available_drives)) {
      $drive_options = [];
      foreach ($available_drives as $drive) {
        $drive_options[$drive['id']] = $drive['name'] . ' (' . $drive['driveType'] . ')';
      }

      $form['drives']['sync_drives'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Drives to sync'),
        '#description' => $this->t('Select which SharePoint drives to include in synchronization.'),
        '#options' => $drive_options,
        '#default_value' => array_keys(array_filter($config->get('sync_drives') ?? [])),
      ];
    }
    else {
      $form['drives']['no_drives'] = [
        '#markup' => '<p>' . $this->t('No drives available. Please configure your connection settings first.') . '</p>',
      ];
    }

    // File Type Filters.
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('File Filters'),
      '#open' => FALSE,
      '#description' => $this->t('Configure which types of files to sync.'),
    ];

    $form['filters']['allowed_file_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed file types'),
      '#description' => $this->t('Only sync files of these types. Leave empty to sync all file types.'),
      '#options' => [
        'image/' => $this->t('Images (JPG, PNG, GIF, etc.)'),
        'video/' => $this->t('Videos (MP4, AVI, MOV, etc.)'),
        'audio/' => $this->t('Audio (MP3, WAV, etc.)'),
        'application/pdf' => $this->t('PDF documents'),
        'application/msword' => $this->t('Word documents'),
        'application/vnd.ms-excel' => $this->t('Excel spreadsheets'),
        'application/vnd.ms-powerpoint' => $this->t('PowerPoint presentations'),
        'text/' => $this->t('Text files'),
      ],
      '#default_value' => $config->get('allowed_file_types') ?? [],
    ];

    $form['filters']['max_file_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum file size (MB)'),
      '#description' => $this->t('Skip files larger than this size. Set to 0 for no limit.'),
      '#default_value' => $config->get('max_file_size') ?? 0,
      '#min' => 0,
      '#field_suffix' => 'MB',
    ];

    $form['filters']['exclude_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclude paths'),
      '#description' => $this->t('Enter path patterns to exclude from sync, one per line. Use * as wildcard.'),
      '#default_value' => $config->get('exclude_paths') ?? '',
      '#placeholder' => "/temp/*\n/private/*\n*/.git/*",
      '#rows' => 4,
    ];

    // Advanced Settings.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('Log detailed information about sync operations and API calls.'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    $form['advanced']['thumbnail_cache_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail cache duration (hours)'),
      '#description' => $this->t('How long to cache thumbnails locally before re-downloading.'),
      '#default_value' => $config->get('thumbnail_cache_duration') ?? 24,
      '#min' => 1,
      '#max' => 168,
    ];

    $form['advanced']['url_cache_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Download URL cache duration (minutes)'),
      '#description' => $this->t('How long to cache SharePoint download URLs before refreshing.'),
      '#default_value' => $config->get('url_cache_duration') ?? 50,
      '#min' => 10,
      '#max' => 60,
    ];

    $form['advanced']['concurrent_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Concurrent requests'),
      '#description' => $this->t('Maximum number of concurrent requests to Microsoft Graph API.'),
      '#default_value' => $config->get('concurrent_requests') ?? 5,
      '#min' => 1,
      '#max' => 20,
    ];

    // Performance Settings.
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
    ];

    $form['performance']['enable_search_preprocessing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable search preprocessing'),
      '#description' => $this->t('Pre-process and extract keywords for better search performance.'),
      '#default_value' => $config->get('enable_search_preprocessing') ?? TRUE,
    ];

    $form['performance']['cache_metadata'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache metadata'),
      '#description' => $this->t('Cache extracted metadata to improve performance.'),
      '#default_value' => $config->get('cache_metadata') ?? TRUE,
    ];

    $form['performance']['lazy_load_thumbnails'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Lazy load thumbnails'),
      '#description' => $this->t('Load thumbnails only when needed to improve page load times.'),
      '#default_value' => $config->get('lazy_load_thumbnails') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate tenant ID format.
    $tenant_id = $form_state->getValue('tenant_id');
    if (!empty($tenant_id) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant_id)) {
      $form_state->setErrorByName('tenant_id', $this->t('Tenant ID must be a valid GUID format.'));
    }

    // Validate client ID format.
    $client_id = $form_state->getValue('client_id');
    if (!empty($client_id) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $client_id)) {
      $form_state->setErrorByName('client_id', $this->t('Client ID must be a valid GUID format.'));
    }

    // Validate file size.
    $max_file_size = $form_state->getValue('max_file_size');
    if ($max_file_size < 0) {
      $form_state->setErrorByName('max_file_size', $this->t('Maximum file size cannot be negative.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('sharepoint_media.settings');
    $values = $form_state->getValues();

    // Save connection settings.
    $config->set('tenant_id', $values['tenant_id']);
    $config->set('client_id', $values['client_id']);
    
    // Only update client secret if a new one was provided.
    $client_secret = $values['client_secret'];
    if (!empty($client_secret)) {
      $config->set('client_secret', $client_secret);
    }

    // Save sync settings.
    $config->set('auto_sync_enabled', $values['auto_sync_enabled']);
    $config->set('auto_sync_interval', $values['auto_sync_interval']);
    $config->set('auto_refresh_urls', $values['auto_refresh_urls']);
    $config->set('batch_size', $values['batch_size']);

    // Save drive configuration.
    if (isset($values['sync_drives'])) {
      $sync_drives = array_filter($values['sync_drives']);
      $config->set('sync_drives', $sync_drives);
    }

    // Save filter settings.
    $allowed_file_types = array_filter($values['allowed_file_types'] ?? []);
    $config->set('allowed_file_types', array_keys($allowed_file_types));
    $config->set('max_file_size', $values['max_file_size']);
    $config->set('exclude_paths', $values['exclude_paths']);

    // Save advanced settings.
    $config->set('debug_mode', $values['debug_mode']);
    $config->set('thumbnail_cache_duration', $values['thumbnail_cache_duration']);
    $config->set('url_cache_duration', $values['url_cache_duration']);
    $config->set('concurrent_requests', $values['concurrent_requests']);

    // Save performance settings.
    $config->set('enable_search_preprocessing', $values['enable_search_preprocessing']);
    $config->set('cache_metadata', $values['cache_metadata']);
    $config->set('lazy_load_thumbnails', $values['lazy_load_thumbnails']);

    $config->save();

    parent::submitForm($form, $form_state);

    // Clear caches if connection settings changed.
    if ($form_state->hasValue('tenant_id') || $form_state->hasValue('client_id') || $form_state->hasValue('client_secret')) {
      \Drupal::cache()->deleteAll();
      $this->messenger()->addStatus($this->t('Configuration saved. Caches have been cleared.'));
    }
  }

  /**
   * AJAX callback to test the connection.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Use form values for testing.
    $tenant_id = $values['tenant_id'];
    $client_id = $values['client_id'];
    $client_secret = $values['client_secret'];
    
    // If no new secret provided, use existing one.
    if (empty($client_secret)) {
      $client_secret = $this->config('sharepoint_media.settings')->get('client_secret');
    }

    if (empty($tenant_id) || empty($client_id) || empty($client_secret)) {
      $form['connection']['connection_status']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('Please fill in all connection fields before testing.') . 
        '</div>';
      return $form['connection']['connection_status'];
    }

    try {
      // Temporarily set config for testing.
      $temp_config = [
        'tenant_id' => $tenant_id,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
      ];
      
      // Test the connection (you'll need to implement this in GraphApiClient).
      $success = $this->graphClient->testConnection();
      
      if ($success) {
        $form['connection']['connection_status']['#markup'] = 
          '<div class="messages messages--status">' . 
          $this->t('✓ Connection successful! You can connect to Microsoft Graph API.') . 
          '</div>';
      }
      else {
        $form['connection']['connection_status']['#markup'] = 
          '<div class="messages messages--error">' . 
          $this->t('✗ Connection failed. Please check your credentials.') . 
          '</div>';
      }
    }
    catch (\Exception $e) {
      $form['connection']['connection_status']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('✗ Connection error: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }

    return $form['connection']['connection_status'];
  }
}