<?php

namespace Drupal\sharepoint_media\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for extracting metadata from SharePoint drive items.
 */
class MetadataExtractor {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new MetadataExtractor.
   */
  public function __construct(FileSystemInterface $file_system, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Extract metadata from a SharePoint drive item.
   *
   * @param array $drive_item
   *   The SharePoint drive item data.
   *
   * @return array
   *   Extracted metadata keyed by field name.
   */
  public function extractMetadata(array $drive_item) {
    $metadata = [];

    if (!isset($drive_item['file'])) {
      return $metadata;
    }

    $mime_type = $drive_item['file']['mimeType'] ?? '';
    $file_type = $this->getFileTypeCategory($mime_type);

    switch ($file_type) {
      case 'image':
        $metadata = array_merge($metadata, $this->extractImageMetadata($drive_item));
        break;

      case 'video':
        $metadata = array_merge($metadata, $this->extractVideoMetadata($drive_item));
        break;

      case 'audio':
        $metadata = array_merge($metadata, $this->extractAudioMetadata($drive_item));
        break;

      case 'document':
        $metadata = array_merge($metadata, $this->extractDocumentMetadata($drive_item));
        break;
    }

    // Extract common file metadata.
    $metadata = array_merge($metadata, $this->extractCommonMetadata($drive_item));

    return $metadata;
  }

  /**
   * Extract image-specific metadata.
   */
  protected function extractImageMetadata(array $drive_item) {
    $metadata = [];

    // Basic image properties from SharePoint.
    if (isset($drive_item['image'])) {
      $image_data = $drive_item['image'];
      
      if (isset($image_data['width'])) {
        $metadata['field_image_width'] = $image_data['width'];
      }
      
      if (isset($image_data['height'])) {
        $metadata['field_image_height'] = $image_data['height'];
      }
    }

    // Photo metadata (EXIF data).
    if (isset($drive_item['photo'])) {
      $photo_data = $drive_item['photo'];
      
      if (isset($photo_data['cameraMake'])) {
        $metadata['field_camera_make'] = $photo_data['cameraMake'];
      }
      
      if (isset($photo_data['cameraModel'])) {
        $metadata['field_camera_model'] = $photo_data['cameraModel'];
      }
      
      if (isset($photo_data['takenDateTime'])) {
        $metadata['field_date_taken'] = $photo_data['takenDateTime'];
      }
      
      if (isset($photo_data['fNumber'])) {
        $metadata['field_f_number'] = $photo_data['fNumber'];
      }
      
      if (isset($photo_data['exposureTime'])) {
        $metadata['field_exposure_time'] = $photo_data['exposureTime'];
      }
      
      if (isset($photo_data['iso'])) {
        $metadata['field_iso'] = $photo_data['iso'];
      }
      
      if (isset($photo_data['focalLength'])) {
        $metadata['field_focal_length'] = $photo_data['focalLength'];
      }
    }

    // Attempt to extract additional EXIF data if available.
    $additional_exif = $this->extractAdvancedExifData($drive_item);
    if (!empty($additional_exif)) {
      $metadata = array_merge($metadata, $additional_exif);
    }

    return $metadata;
  }

  /**
   * Extract video-specific metadata.
   */
  protected function extractVideoMetadata(array $drive_item) {
    $metadata = [];

    if (isset($drive_item['video'])) {
      $video_data = $drive_item['video'];
      
      if (isset($video_data['duration'])) {
        // Duration is in milliseconds, convert to seconds.
        $metadata['field_duration'] = round($video_data['duration'] / 1000);
      }
      
      if (isset($video_data['width'])) {
        $metadata['field_video_width'] = $video_data['width'];
      }
      
      if (isset($video_data['height'])) {
        $metadata['field_video_height'] = $video_data['height'];
      }
      
      if (isset($video_data['bitrate'])) {
        $metadata['field_bitrate'] = $video_data['bitrate'];
      }
      
      if (isset($video_data['audioBitsPerSample'])) {
        $metadata['field_audio_bits_per_sample'] = $video_data['audioBitsPerSample'];
      }
      
      if (isset($video_data['audioChannels'])) {
        $metadata['field_audio_channels'] = $video_data['audioChannels'];
      }
      
      if (isset($video_data['audioSamplesPerSecond'])) {
        $metadata['field_audio_sample_rate'] = $video_data['audioSamplesPerSecond'];
      }
      
      if (isset($video_data['fourCC'])) {
        $metadata['field_video_codec'] = $video_data['fourCC'];
      }
      
      if (isset($video_data['frameRate'])) {
        $metadata['field_frame_rate'] = $video_data['frameRate'];
      }
    }

    return $metadata;
  }

  /**
   * Extract audio-specific metadata.
   */
  protected function extractAudioMetadata(array $drive_item) {
    $metadata = [];

    if (isset($drive_item['audio'])) {
      $audio_data = $drive_item['audio'];
      
      if (isset($audio_data['duration'])) {
        // Duration is in milliseconds, convert to seconds.
        $metadata['field_duration'] = round($audio_data['duration'] / 1000);
      }
      
      if (isset($audio_data['bitrate'])) {
        $metadata['field_bitrate'] = $audio_data['bitrate'];
      }
      
      if (isset($audio_data['channels'])) {
        $metadata['field_audio_channels'] = $audio_data['channels'];
      }
      
      if (isset($audio_data['samplesPerSecond'])) {
        $metadata['field_audio_sample_rate'] = $audio_data['samplesPerSecond'];
      }
      
      if (isset($audio_data['bitsPerSample'])) {
        $metadata['field_audio_bits_per_sample'] = $audio_data['bitsPerSample'];
      }
      
      // Music-specific metadata.
      if (isset($audio_data['album'])) {
        $metadata['field_album'] = $audio_data['album'];
      }
      
      if (isset($audio_data['albumArtist'])) {
        $metadata['field_album_artist'] = $audio_data['albumArtist'];
      }
      
      if (isset($audio_data['artist'])) {
        $metadata['field_artist'] = $audio_data['artist'];
      }
      
      if (isset($audio_data['composer'])) {
        $metadata['field_composer'] = $audio_data['composer'];
      }
      
      if (isset($audio_data['copyright'])) {
        $metadata['field_copyright'] = $audio_data['copyright'];
      }
      
      if (isset($audio_data['disc'])) {
        $metadata['field_disc_number'] = $audio_data['disc'];
      }
      
      if (isset($audio_data['discCount'])) {
        $metadata['field_disc_count'] = $audio_data['discCount'];
      }
      
      if (isset($audio_data['genre'])) {
        $metadata['field_genre'] = $audio_data['genre'];
      }
      
      if (isset($audio_data['hasDrm'])) {
        $metadata['field_has_drm'] = $audio_data['hasDrm'];
      }
      
      if (isset($audio_data['isVariableBitrate'])) {
        $metadata['field_variable_bitrate'] = $audio_data['isVariableBitrate'];
      }
      
      if (isset($audio_data['title'])) {
        $metadata['field_audio_title'] = $audio_data['title'];
      }
      
      if (isset($audio_data['track'])) {
        $metadata['field_track_number'] = $audio_data['track'];
      }
      
      if (isset($audio_data['trackCount'])) {
        $metadata['field_track_count'] = $audio_data['trackCount'];
      }
      
      if (isset($audio_data['year'])) {
        $metadata['field_year'] = $audio_data['year'];
      }
    }

    return $metadata;
  }

  /**
   * Extract document-specific metadata.
   */
  protected function extractDocumentMetadata(array $drive_item) {
    $metadata = [];

    // Extract basic document properties if available.
    if (isset($drive_item['file']['processingMetadata'])) {
      $processing_data = $drive_item['file']['processingMetadata'];
      
      // Document properties might be available here.
      if (isset($processing_data['pageCount'])) {
        $metadata['field_page_count'] = $processing_data['pageCount'];
      }
      
      if (isset($processing_data['wordCount'])) {
        $metadata['field_word_count'] = $processing_data['wordCount'];
      }
    }

    // Office document metadata.
    if (isset($drive_item['package'])) {
      $package_data = $drive_item['package'];
      
      if (isset($package_data['type'])) {
        $metadata['field_package_type'] = $package_data['type'];
      }
    }

    return $metadata;
  }

  /**
   * Extract common metadata for all file types.
   */
  protected function extractCommonMetadata(array $drive_item) {
    $metadata = [];

    // File extension.
    $name = $drive_item['name'] ?? '';
    if ($name) {
      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $metadata['field_file_extension'] = $extension;
    }

    // File type category.
    $mime_type = $drive_item['file']['mimeType'] ?? '';
    $metadata['field_file_type_category'] = $this->getFileTypeCategory($mime_type);

    // Hash information if available.
    if (isset($drive_item['file']['hashes'])) {
      $hashes = $drive_item['file']['hashes'];
      
      if (isset($hashes['sha1Hash'])) {
        $metadata['field_sha1_hash'] = $hashes['sha1Hash'];
      }
      
      if (isset($hashes['quickXorHash'])) {
        $metadata['field_quickxor_hash'] = $hashes['quickXorHash'];
      }
    }

    // Parent reference.
    if (isset($drive_item['parentReference'])) {
      $parent_ref = $drive_item['parentReference'];
      
      if (isset($parent_ref['path'])) {
        $metadata['field_parent_path'] = $parent_ref['path'];
      }
      
      if (isset($parent_ref['name'])) {
        $metadata['field_parent_folder'] = $parent_ref['name'];
      }
    }

    // Extract tags from filename or path.
    $extracted_tags = $this->extractTagsFromFilename($name);
    if (!empty($extracted_tags)) {
      $metadata['field_auto_tags'] = $extracted_tags;
    }

    return $metadata;
  }

  /**
   * Get file type category from MIME type.
   */
  protected function getFileTypeCategory($mime_type) {
    if (strpos($mime_type, 'image/') === 0) {
      return 'image';
    }
    elseif (strpos($mime_type, 'video/') === 0) {
      return 'video';
    }
    elseif (strpos($mime_type, 'audio/') === 0) {
      return 'audio';
    }
    elseif (in_array($mime_type, [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-powerpoint',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ])) {
      return 'document';
    }
    elseif (strpos($mime_type, 'text/') === 0) {
      return 'text';
    }
    else {
      return 'other';
    }
  }

  /**
   * Extract advanced EXIF data (if accessible).
   */
  protected function extractAdvancedExifData(array $drive_item) {
    $metadata = [];

    // This would require additional API calls or analysis.
    // For now, we'll return empty array as SharePoint provides
    // most EXIF data in the photo object.

    return $metadata;
  }

  /**
   * Extract tags from filename patterns.
   */
  protected function extractTagsFromFilename($filename) {
    $tags = [];

    if (empty($filename)) {
      return $tags;
    }

    // Remove extension.
    $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);

    // Look for common patterns that might indicate tags.
    
    // Dates in filename (YYYY-MM-DD, YYYY_MM_DD, etc.).
    if (preg_match('/(\d{4})[_-](\d{2})[_-](\d{2})/', $name_without_ext, $matches)) {
      $tags[] = $matches[1]; // Year
      $date_tag = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
      $tags[] = $date_tag;
    }

    // Common keywords/patterns.
    $keyword_patterns = [
      '/\b(draft|final|review|approved)\b/i',
      '/\b(presentation|slides|deck)\b/i',
      '/\b(report|summary|analysis)\b/i',
      '/\b(meeting|conference|workshop)\b/i',
      '/\b(photo|picture|image)\b/i',
      '/\b(video|recording|clip)\b/i',
      '/\b(document|doc|file)\b/i',
    ];

    foreach ($keyword_patterns as $pattern) {
      if (preg_match_all($pattern, $name_without_ext, $matches)) {
        $tags = array_merge($tags, $matches[1]);
      }
    }

    // Extract words separated by underscores or dashes.
    $words = preg_split('/[_\-\s]+/', $name_without_ext);
    foreach ($words as $word) {
      $word = trim($word);
      // Add words that are 3+ characters and not numbers.
      if (strlen($word) >= 3 && !is_numeric($word)) {
        $tags[] = strtolower($word);
      }
    }

    // Remove duplicates and common stop words.
    $stop_words = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was', 'one', 'our', 'has', 'have'];
    $tags = array_diff(array_unique($tags), $stop_words);

    return array_slice($tags, 0, 10); // Limit to 10 tags.
  }

  /**
   * Extract text content from supported document types.
   */
  public function extractTextContent(array $drive_item) {
    $mime_type = $drive_item['file']['mimeType'] ?? '';
    
    // For now, we'll rely on SharePoint's search indexing.
    // In the future, we could download and process files for text extraction.
    
    return '';
  }

  /**
   * Generate searchable keywords from metadata.
   */
  public function generateSearchKeywords(array $metadata) {
    $keywords = [];

    // Extract keywords from various metadata fields.
    $text_fields = [
      'field_camera_make',
      'field_camera_model',
      'field_album',
      'field_artist',
      'field_album_artist',
      'field_genre',
      'field_audio_title',
      'field_composer',
      'field_auto_tags',
    ];

    foreach ($text_fields as $field) {
      if (isset($metadata[$field]) && !empty($metadata[$field])) {
        if (is_array($metadata[$field])) {
          $keywords = array_merge($keywords, $metadata[$field]);
        }
        else {
          $keywords[] = $metadata[$field];
        }
      }
    }

    // Add technical specifications as keywords.
    if (isset($metadata['field_video_width']) && isset($metadata['field_video_height'])) {
      $resolution = $metadata['field_video_width'] . 'x' . $metadata['field_video_height'];
      $keywords[] = $resolution;
      
      // Add common resolution names.
      $resolution_names = [
        '1920x1080' => '1080p',
        '1280x720' => '720p',
        '3840x2160' => '4K',
        '1920x1200' => 'WUXGA',
      ];
      
      if (isset($resolution_names[$resolution])) {
        $keywords[] = $resolution_names[$resolution];
      }
    }

    return array_unique(array_filter($keywords));
  }
}