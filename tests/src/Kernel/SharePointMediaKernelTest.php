<?php

namespace Drupal\Tests\sharepoint_media\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;

/**
 * Kernel tests for SharePoint Media module.
 *
 * @group sharepoint_media
 */
class SharePointMediaKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'sharepoint_media',
    'media',
    'field',
    'image',
    'file',
    'datetime',
    'user',
    'system',
    'taxonomy',
  ];

  /**
   * The media type entity.
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected $mediaType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('taxonomy_vocabulary');
    $this->installConfig(['sharepoint_media', 'media', 'field', 'system']);

    // Create the SharePoint media type.
    $this->mediaType = MediaType::create([
      'id' => 'sharepoint_file',
      'label' => 'SharePoint File',
      'description' => 'Media type for SharePoint drive items.',
      'source' => 'sharepoint_file',
      'queue_thumbnail_downloads' => FALSE,
      'new_revision' => FALSE,
    ]);
    $this->mediaType->save();

    // Create required fields.
    $this->createRequiredFields();
  }

  /**
   * Tests SharePoint media type creation.
   */
  public function testMediaTypeCreation() {
    $this->assertNotNull($this->mediaType);
    $this->assertEquals('sharepoint_file', $this->mediaType->id());
    $this->assertEquals('sharepoint_file', $this->mediaType->getSource()->getPluginId());
  }

  /**
   * Tests SharePoint media entity creation.
   */
  public function testMediaEntityCreation() {
    $media = Media::create([
      'bundle' => 'sharepoint_file',
      'name' => 'Test SharePoint File',
      'field_sharepoint_drive_item_id' => 'test-drive-item-id',
      'field_mime_type' => 'image/jpeg',
      'field_file_size' => 1024,
      'field_created_by' => 'Test User',
      'uid' => 1,
      'status' => TRUE,
    ]);

    $media->save();

    $this->assertNotNull($media->id());
    $this->assertEquals('Test SharePoint File', $media->getName());
    $this->assertEquals('test-drive-item-id', $media->get('field_sharepoint_drive_item_id')->value);
    $this->assertEquals('image/jpeg', $media->get('field_mime_type')->value);
    $this->assertEquals(1024, $media->get('field_file_size')->value);
    $this->assertEquals('Test User', $media->get('field_created_by')->value);
  }

  /**
   * Tests field validation.
   */
  public function testFieldValidation() {
    // Test that required fields are enforced.
    $media = Media::create([
      'bundle' => 'sharepoint_file',
      'name' => 'Test SharePoint File',
      // Missing required field_sharepoint_drive_item_id
      'uid' => 1,
      'status' => TRUE,
    ]);

    $violations = $media->validate();
    $this->assertGreaterThan(0, $violations->count());

    // Find the specific violation for the missing field.
    $found_violation = FALSE;
    foreach ($violations as $violation) {
      if ($violation->getPropertyPath() === 'field_sharepoint_drive_item_id.0.value') {
        $found_violation = TRUE;
        break;
      }
    }
    $this->assertTrue($found_violation, 'Required field validation should trigger');
  }

  /**
   * Tests metadata field storage and retrieval.
   */
  public function testMetadataFields() {
    $media = Media::create([
      'bundle' => 'sharepoint_file',
      'name' => 'Test Image File',
      'field_sharepoint_drive_item_id' => 'test-image-id',
      'field_mime_type' => 'image/jpeg',
      'field_file_size' => 2048576, // 2MB
      'field_image_width' => 1920,
      'field_image_height' => 1080,
      'field_camera_make' => 'Canon',
      'field_camera_model' => 'EOS R5',
      'field_date_taken' => '2024-01-01T12:00:00',
      'field_f_number' => 2.8,
      'field_iso' => 400,
      'field_created_by' => 'Photographer',
      'uid' => 1,
      'status' => TRUE,
    ]);

    $media->save();

    // Reload and verify all fields.
    $media = Media::load($media->id());
    $this->assertEquals(1920, $media->get('field_image_width')->value);
    $this->assertEquals(1080, $media->get('field_image_height')->value);
    $this->assertEquals('Canon', $media->get('field_camera_make')->value);
    $this->assertEquals('EOS R5', $media->get('field_camera_model')->value);
    $this->assertEquals('2024-01-01T12:00:00', $media->get('field_date_taken')->value);
    $this->assertEquals(2.8, $media->get('field_f_number')->value);
    $this->assertEquals(400, $media->get('field_iso')->value);
  }

  /**
   * Tests video metadata fields.
   */
  public function testVideoMetadataFields() {
    $media = Media::create([
      'bundle' => 'sharepoint_file',
      'name' => 'Test Video File',
      'field_sharepoint_drive_item_id' => 'test-video-id',
      'field_mime_type' => 'video/mp4',
      'field_file_size' => 104857600, // 100MB
      'field_video_width' => 1920,
      'field_video_height' => 1080,
      'field_duration' => 120, // 2 minutes
      'field_frame_rate' => 30.0,
      'field_bitrate' => 5000,
      'field_audio_channels' => 2,
      'field_audio_sample_rate' => 48000,
      'field_created_by' => 'Video Creator',
      'uid' => 1,
      'status' => TRUE,
    ]);

    $media->save();

    // Reload and verify video fields.
    $media = Media::load($media->id());
    $this->assertEquals(1920, $media->get('field_video_width')->value);
    $this->assertEquals(1080, $media->get('field_video_height')->value);
    $this->assertEquals(120, $media->get('field_duration')->value);
    $this->assertEquals(30.0, $media->get('field_frame_rate')->value);
    $this->assertEquals(5000, $media->get('field_bitrate')->value);
    $this->assertEquals(2, $media->get('field_audio_channels')->value);
    $this->assertEquals(48000, $media->get('field_audio_sample_rate')->value);
  }

  /**
   * Tests audio metadata fields.
   */
  public function testAudioMetadataFields() {
    $media = Media::create([
      'bundle' => 'sharepoint_file',
      'name' => 'Test Audio File',
      'field_sharepoint_drive_item_id' => 'test-audio-id',
      'field_mime_type' => 'audio/mp3',
      'field_file_size' => 5242880, // 5MB
      'field_duration' => 180, // 3 minutes
      'field_artist' => 'Test Artist',
      'field_album' => 'Test Album',
      'field_album_artist' => 'Album Artist',
      'field_genre' => 'Rock',
      'field_year' => 2024,
      'field_track_number' => 3,
      'field_audio_channels' => 2,
      'field_audio_sample_rate' => 44100,
      'field_audio_bits_per_sample' => 16,
      'field_created_by' => 'Music Producer',
      'uid' => 1,
      'status' => TRUE,
    ]);

    $media->save();

    // Reload and verify audio fields.
    $media = Media::load($media->id());
    $this->assertEquals(180, $media->get('field_duration')->value);
    $this->assertEquals('Test Artist', $media->get('field_artist')->value);
    $this->assertEquals('Test Album', $media->get('field_album')->value);
    $this->assertEquals('Album Artist', $media->get('field_album_artist')->value);
    $this->assertEquals('Rock', $media->get('field_genre')->value);
    $this->assertEquals(2024, $media->get('field_year')->value);
    $this->assertEquals(3, $media->get('field_track_number')->value);
    $this->assertEquals(2, $media->get('field_audio_channels')->value);
    $this->assertEquals(44100, $media->get('field_audio_sample_rate')->value);
    $this->assertEquals(16, $media->get('field_audio_bits_per_sample')->value);
  }

  /**
   * Tests module configuration defaults.
   */
  public function testModuleConfiguration() {
    $config = $this->config('sharepoint_media.settings');

    // Test default configuration values.
    $this->assertFalse($config->get('auto_sync_enabled'));
    $this->assertEquals(3600, $config->get('auto_sync_interval'));
    $this->assertTrue($config->get('auto_refresh_urls'));
    $this->assertEquals(50, $config->get('batch_size'));
    $this->assertEquals([], $config->get('allowed_file_types'));
    $this->assertEquals(0, $config->get('max_file_size'));
    $this->assertEquals('', $config->get('exclude_paths'));
    $this->assertFalse($config->get('debug_mode'));
    $this->assertEquals(24, $config->get('thumbnail_cache_duration'));
    $this->assertEquals(50, $config->get('url_cache_duration'));
    $this->assertEquals(5, $config->get('concurrent_requests'));
    $this->assertTrue($config->get('enable_search_preprocessing'));
    $this->assertTrue($config->get('cache_metadata'));
    $this->assertTrue($config->get('lazy_load_thumbnails'));
  }

  /**
   * Tests that services are properly registered.
   */
  public function testServiceRegistration() {
    $container = \Drupal::getContainer();

    // Test that all required services are available.
    $this->assertTrue($container->has('sharepoint_media.graph_client'));
    $this->assertTrue($container->has('sharepoint_media.sync_service'));
    $this->assertTrue($container->has('sharepoint_media.url_manager'));
    $this->assertTrue($container->has('sharepoint_media.metadata_extractor'));

    // Test that services can be instantiated.
    $graph_client = $container->get('sharepoint_media.graph_client');
    $this->assertInstanceOf('Drupal\sharepoint_media\Service\GraphApiClient', $graph_client);

    $sync_service = $container->get('sharepoint_media.sync_service');
    $this->assertInstanceOf('Drupal\sharepoint_media\Service\SharePointSyncService', $sync_service);

    $url_manager = $container->get('sharepoint_media.url_manager');
    $this->assertInstanceOf('Drupal\sharepoint_media\Service\DownloadUrlManager', $url_manager);

    $metadata_extractor = $container->get('sharepoint_media.metadata_extractor');
    $this->assertInstanceOf('Drupal\sharepoint_media\Service\MetadataExtractor', $metadata_extractor);
  }

  /**
   * Create required fields for testing.
   */
  protected function createRequiredFields() {
    $fields = [
      'field_sharepoint_drive_item_id' => [
        'type' => 'string',
        'required' => TRUE,
        'cardinality' => 1,
        'settings' => ['max_length' => 255],
      ],
      'field_mime_type' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 127],
      ],
      'field_file_size' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_created_by' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 255],
      ],
      'field_image_width' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_image_height' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_video_width' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_video_height' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_duration' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_frame_rate' => [
        'type' => 'decimal',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['precision' => 8, 'scale' => 2],
      ],
      'field_bitrate' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_audio_channels' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_audio_sample_rate' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_audio_bits_per_sample' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_artist' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 255],
      ],
      'field_album' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 255],
      ],
      'field_album_artist' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 255],
      ],
      'field_genre' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 100],
      ],
      'field_year' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_track_number' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
      'field_camera_make' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 100],
      ],
      'field_camera_model' => [
        'type' => 'string',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['max_length' => 100],
      ],
      'field_date_taken' => [
        'type' => 'datetime',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['datetime_type' => 'datetime'],
      ],
      'field_f_number' => [
        'type' => 'decimal',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['precision' => 4, 'scale' => 1],
      ],
      'field_iso' => [
        'type' => 'integer',
        'required' => FALSE,
        'cardinality' => 1,
        'settings' => ['unsigned' => TRUE],
      ],
    ];

    foreach ($fields as $field_name => $field_config) {
      // Create field storage.
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'media',
        'type' => $field_config['type'],
        'cardinality' => $field_config['cardinality'],
        'settings' => $field_config['settings'],
      ]);
      $field_storage->save();

      // Create field instance.
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => 'sharepoint_file',
        'label' => ucwords(str_replace(['field_', '_'], ['', ' '], $field_name)),
        'required' => $field_config['required'],
      ]);
      $field->save();
    }
  }

}
