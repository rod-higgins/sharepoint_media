<?php

namespace Drupal\sharepoint_media\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Property\Property;

/**
 * Enhances SharePoint media items with additional searchable metadata.
 *
 * @SearchApiProcessor(
 *   id = "sharepoint_metadata",
 *   label = @Translation("SharePoint Metadata Processor"),
 *   description = @Translation("Adds SharePoint-specific metadata for enhanced search capabilities."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class SharePointMetadataProcessor extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource || $datasource->getEntityTypeId() === 'media') {
      $definition = [
        'label' => $this->t('File Type Category'),
        'description' => $this->t('Categorized file type (image, video, audio, document)'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_file_type'] = new Property($definition);

      $definition = [
        'label' => $this->t('File Extension'),
        'description' => $this->t('File extension extracted from name'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_extension'] = new Property($definition);

      $definition = [
        'label' => $this->t('File Size Category'),
        'description' => $this->t('Categorized file size (small, medium, large, etc.)'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_size_category'] = new Property($definition);

      $definition = [
        'label' => $this->t('Resolution Category'),
        'description' => $this->t('Categorized image/video resolution'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_resolution'] = new Property($definition);

      $definition = [
        'label' => $this->t('Duration Category'),
        'description' => $this->t('Categorized duration for video/audio files'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_duration_category'] = new Property($definition);

      $definition = [
        'label' => $this->t('Creation Year'),
        'description' => $this->t('Year when the file was created'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_creation_year'] = new Property($definition);

      $definition = [
        'label' => $this->t('Searchable Keywords'),
        'description' => $this->t('Generated keywords for enhanced search'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_keywords'] = new Property($definition);

      $definition = [
        'label' => $this->t('Technical Specifications'),
        'description' => $this->t('Technical details and specifications'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_technical_specs'] = new Property($definition);

      $definition = [
        'label' => $this->t('Path Components'),
        'description' => $this->t('Individual components of the SharePoint path'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_path_components'] = new Property($definition);

      $definition = [
        'label' => $this->t('Quality Score'),
        'description' => $this->t('Calculated quality score based on resolution, file size, etc.'),
        'type' => 'decimal',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['sharepoint_quality_score'] = new Property($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()->getValue();
    
    if (!($entity instanceof MediaInterface) || $entity->bundle() !== 'sharepoint_file') {
      return;
    }

    $fields = $item->getFields(FALSE);

    // Add file type category.
    if (isset($fields['sharepoint_file_type'])) {
      $mime_type = $entity->get('field_mime_type')->value ?? '';
      $file_type = $this->categorizeFileType($mime_type);
      $fields['sharepoint_file_type']->addValue($file_type);
    }

    // Add file extension.
    if (isset($fields['sharepoint_extension'])) {
      $name = $entity->getName();
      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if ($extension) {
        $fields['sharepoint_extension']->addValue($extension);
      }
    }

    // Add file size category.
    if (isset($fields['sharepoint_size_category'])) {
      $file_size = $entity->get('field_file_size')->value ?? 0;
      $size_category = $this->categorizeFileSize($file_size);
      $fields['sharepoint_size_category']->addValue($size_category);
    }

    // Add resolution category for images/videos.
    if (isset($fields['sharepoint_resolution'])) {
      $resolution = $this->getResolutionCategory($entity);
      if ($resolution) {
        $fields['sharepoint_resolution']->addValue($resolution);
      }
    }

    // Add duration category for audio/video.
    if (isset($fields['sharepoint_duration_category'])) {
      $duration_category = $this->getDurationCategory($entity);
      if ($duration_category) {
        $fields['sharepoint_duration_category']->addValue($duration_category);
      }
    }

    // Add creation year.
    if (isset($fields['sharepoint_creation_year'])) {
      $creation_year = $this->getCreationYear($entity);
      if ($creation_year) {
        $fields['sharepoint_creation_year']->addValue($creation_year);
      }
    }

    // Add searchable keywords.
    if (isset($fields['sharepoint_keywords'])) {
      $keywords = $this->generateKeywords($entity);
      if (!empty($keywords)) {
        $fields['sharepoint_keywords']->addValue(implode(' ', $keywords));
      }
    }

    // Add technical specifications.
    if (isset($fields['sharepoint_technical_specs'])) {
      $tech_specs = $this->getTechnicalSpecs($entity);
      if (!empty($tech_specs)) {
        $fields['sharepoint_technical_specs']->addValue(implode(' ', $tech_specs));
      }
    }

    // Add path components.
    if (isset($fields['sharepoint_path_components'])) {
      $path_components = $this->getPathComponents($entity);
      if (!empty($path_components)) {
        $fields['sharepoint_path_components']->addValue(implode(' ', $path_components));
      }
    }

    // Add quality score.
    if (isset($fields['sharepoint_quality_score'])) {
      $quality_score = $this->calculateQualityScore($entity);
      $fields['sharepoint_quality_score']->addValue($quality_score);
    }
  }

  /**
   * Categorize file type from MIME type.
   */
  protected function categorizeFileType($mime_type) {
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
   * Categorize file size.
   */
  protected function categorizeFileSize($file_size) {
    if ($file_size < 1024 * 1024) { // < 1MB
      return 'small';
    }
    elseif ($file_size < 10 * 1024 * 1024) { // < 10MB
      return 'medium';
    }
    elseif ($file_size < 100 * 1024 * 1024) { // < 100MB
      return 'large';
    }
    elseif ($file_size < 1024 * 1024 * 1024) { // < 1GB
      return 'very-large';
    }
    else {
      return 'huge';
    }
  }

  /**
   * Get resolution category for images/videos.
   */
  protected function getResolutionCategory(MediaInterface $entity) {
    $width = 0;
    $height = 0;

    // Check for image dimensions.
    if ($entity->hasField('field_image_width') && !$entity->get('field_image_width')->isEmpty()) {
      $width = $entity->get('field_image_width')->value;
      $height = $entity->get('field_image_height')->value ?? 0;
    }
    // Check for video dimensions.
    elseif ($entity->hasField('field_video_width') && !$entity->get('field_video_width')->isEmpty()) {
      $width = $entity->get('field_video_width')->value;
      $height = $entity->get('field_video_height')->value ?? 0;
    }

    if ($width && $height) {
      $resolution = $width . 'x' . $height;
      
      // Common resolution mappings.
      $resolution_map = [
        '1920x1080' => '1080p',
        '1280x720' => '720p',
        '3840x2160' => '4K',
        '2560x1440' => '1440p',
        '1920x1200' => 'WUXGA',
        '1680x1050' => 'WSXGA+',
        '1440x900' => 'WXGA+',
        '1366x768' => 'WXGA',
        '1024x768' => 'XGA',
      ];

      if (isset($resolution_map[$resolution])) {
        return $resolution_map[$resolution];
      }

      // Categorize by pixel count.
      $pixel_count = $width * $height;
      if ($pixel_count >= 8000000) { // 8MP+
        return 'ultra-high';
      }
      elseif ($pixel_count >= 2000000) { // 2MP+
        return 'high';
      }
      elseif ($pixel_count >= 500000) { // 0.5MP+
        return 'medium';
      }
      else {
        return 'low';
      }
    }

    return NULL;
  }

  /**
   * Get duration category for audio/video files.
   */
  protected function getDurationCategory(MediaInterface $entity) {
    if (!$entity->hasField('field_duration') || $entity->get('field_duration')->isEmpty()) {
      return NULL;
    }

    $duration = $entity->get('field_duration')->value; // In seconds

    if ($duration < 30) {
      return 'very-short';
    }
    elseif ($duration < 300) { // 5 minutes
      return 'short';
    }
    elseif ($duration < 1800) { // 30 minutes
      return 'medium';
    }
    elseif ($duration < 7200) { // 2 hours
      return 'long';
    }
    else {
      return 'very-long';
    }
  }

  /**
   * Get creation year from various date fields.
   */
  protected function getCreationYear(MediaInterface $entity) {
    // Try date taken first (for photos).
    if ($entity->hasField('field_date_taken') && !$entity->get('field_date_taken')->isEmpty()) {
      $date = $entity->get('field_date_taken')->value;
      if ($date) {
        return (int) date('Y', strtotime($date));
      }
    }

    // Try created date.
    if ($entity->hasField('field_created_date') && !$entity->get('field_created_date')->isEmpty()) {
      $date = $entity->get('field_created_date')->value;
      if ($date) {
        return (int) date('Y', strtotime($date));
      }
    }

    // Fall back to entity created date.
    return (int) date('Y', $entity->getCreatedTime());
  }

  /**
   * Generate searchable keywords from entity data.
   */
  protected function generateKeywords(MediaInterface $entity) {
    $keywords = [];

    // Camera information.
    if ($entity->hasField('field_camera_make') && !$entity->get('field_camera_make')->isEmpty()) {
      $keywords[] = $entity->get('field_camera_make')->value;
    }
    if ($entity->hasField('field_camera_model') && !$entity->get('field_camera_model')->isEmpty()) {
      $keywords[] = $entity->get('field_camera_model')->value;
    }

    // Audio metadata.
    $audio_fields = ['field_artist', 'field_album', 'field_album_artist', 'field_genre', 'field_composer'];
    foreach ($audio_fields as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        $keywords[] = $entity->get($field)->value;
      }
    }

    // File extension as keyword.
    $name = $entity->getName();
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    if ($extension) {
      $keywords[] = strtoupper($extension);
    }

    // Creator information.
    if ($entity->hasField('field_created_by') && !$entity->get('field_created_by')->isEmpty()) {
      $created_by = $entity->get('field_created_by')->value;
      $keywords[] = $created_by;
      
      // Extract first and last name if it's a full name.
      $name_parts = explode(' ', $created_by);
      if (count($name_parts) > 1) {
        $keywords = array_merge($keywords, $name_parts);
      }
    }

    // Auto-generated tags.
    if ($entity->hasField('field_auto_tags') && !$entity->get('field_auto_tags')->isEmpty()) {
      $auto_tags = $entity->get('field_auto_tags')->value;
      if (is_string($auto_tags)) {
        $tag_array = explode(',', $auto_tags);
        $keywords = array_merge($keywords, array_map('trim', $tag_array));
      }
    }

    return array_unique(array_filter($keywords));
  }

  /**
   * Get technical specifications as searchable text.
   */
  protected function getTechnicalSpecs(MediaInterface $entity) {
    $specs = [];

    // Resolution specs.
    if ($entity->hasField('field_image_width') && !$entity->get('field_image_width')->isEmpty()) {
      $width = $entity->get('field_image_width')->value;
      $height = $entity->get('field_image_height')->value ?? 0;
      if ($width && $height) {
        $specs[] = $width . 'x' . $height;
        $specs[] = 'resolution-' . $width . 'x' . $height;
      }
    }

    // Video specs.
    if ($entity->hasField('field_frame_rate') && !$entity->get('field_frame_rate')->isEmpty()) {
      $fps = $entity->get('field_frame_rate')->value;
      $specs[] = $fps . 'fps';
    }

    if ($entity->hasField('field_bitrate') && !$entity->get('field_bitrate')->isEmpty()) {
      $bitrate = $entity->get('field_bitrate')->value;
      $specs[] = $bitrate . 'kbps';
    }

    // Audio specs.
    if ($entity->hasField('field_audio_sample_rate') && !$entity->get('field_audio_sample_rate')->isEmpty()) {
      $sample_rate = $entity->get('field_audio_sample_rate')->value;
      $specs[] = $sample_rate . 'Hz';
    }

    if ($entity->hasField('field_audio_channels') && !$entity->get('field_audio_channels')->isEmpty()) {
      $channels = $entity->get('field_audio_channels')->value;
      $channel_names = [1 => 'mono', 2 => 'stereo', 6 => '5.1', 8 => '7.1'];
      if (isset($channel_names[$channels])) {
        $specs[] = $channel_names[$channels];
      }
      $specs[] = $channels . '-channel';
    }

    // MIME type as spec.
    if ($entity->hasField('field_mime_type') && !$entity->get('field_mime_type')->isEmpty()) {
      $mime_type = $entity->get('field_mime_type')->value;
      $specs[] = str_replace('/', '-', $mime_type);
    }

    return $specs;
  }

  /**
   * Get path components for searching.
   */
  protected function getPathComponents(MediaInterface $entity) {
    $components = [];

    if ($entity->hasField('field_sharepoint_path') && !$entity->get('field_sharepoint_path')->isEmpty()) {
      $path = $entity->get('field_sharepoint_path')->value;
      $path_parts = array_filter(explode('/', trim($path, '/')));
      
      foreach ($path_parts as $part) {
        $components[] = $part;
        // Also add URL-decoded version.
        $decoded = urldecode($part);
        if ($decoded !== $part) {
          $components[] = $decoded;
        }
      }
    }

    if ($entity->hasField('field_parent_folder') && !$entity->get('field_parent_folder')->isEmpty()) {
      $components[] = $entity->get('field_parent_folder')->value;
    }

    return array_unique($components);
  }

  /**
   * Calculate a quality score for the media item.
   */
  protected function calculateQualityScore(MediaInterface $entity) {
    $score = 0.5; // Base score

    // Resolution bonus.
    if ($entity->hasField('field_image_width') && !$entity->get('field_image_width')->isEmpty()) {
      $width = $entity->get('field_image_width')->value;
      $height = $entity->get('field_image_height')->value ?? 0;
      $pixel_count = $width * $height;
      
      if ($pixel_count >= 8000000) { // 8MP+
        $score += 0.3;
      }
      elseif ($pixel_count >= 2000000) { // 2MP+
        $score += 0.2;
      }
      elseif ($pixel_count >= 500000) { // 0.5MP+
        $score += 0.1;
      }
    }

    // File size consideration (larger isn't always better, but very small files might be thumbnails).
    if ($entity->hasField('field_file_size') && !$entity->get('field_file_size')->isEmpty()) {
      $file_size = $entity->get('field_file_size')->value;
      
      if ($file_size > 1024 * 1024 && $file_size < 50 * 1024 * 1024) { // 1MB - 50MB sweet spot
        $score += 0.1;
      }
      elseif ($file_size < 100 * 1024) { // Very small files
        $score -= 0.2;
      }
    }

    // Metadata richness bonus.
    $metadata_fields = [
      'field_camera_make',
      'field_camera_model', 
      'field_date_taken',
      'field_artist',
      'field_album',
      'field_created_by'
    ];
    
    $metadata_count = 0;
    foreach ($metadata_fields as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        $metadata_count++;
      }
    }
    
    $score += ($metadata_count / count($metadata_fields)) * 0.2;

    // Ensure score is between 0 and 1.
    return max(0, min(1, $score));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['enable_quality_scoring'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable quality scoring'),
      '#description' => $this->t('Calculate and index quality scores for media items based on resolution, file size, and metadata richness.'),
      '#default_value' => $this->configuration['enable_quality_scoring'] ?? TRUE,
    ];

    $form['extract_technical_specs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Extract technical specifications'),
      '#description' => $this->t('Extract and index technical specifications like resolution, bitrate, etc.'),
      '#default_value' => $this->configuration['extract_technical_specs'] ?? TRUE,
    ];

    $form['generate_keywords'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate searchable keywords'),
      '#description' => $this->t('Generate additional keywords from metadata for enhanced searchability.'),
      '#default_value' => $this->configuration['generate_keywords'] ?? TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    $this->setConfiguration($this->configuration + [
      'enable_quality_scoring' => $form_state->getValue('enable_quality_scoring'),
      'extract_technical_specs' => $form_state->getValue('extract_technical_specs'),
      'generate_keywords' => $form_state->getValue('generate_keywords'),
    ]);
  }
}