<?php

namespace Drupal\sharepoint_media\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for SharePoint media search results.
 */
class SearchResultsController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructs a new SearchResultsController.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, PagerManagerInterface $pager_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->pagerManager = $pager_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('pager.manager')
    );
  }

  /**
   * Display search results.
   */
  public function results(Request $request) {
    $index = Index::load('sharepoint_media');
    
    if (!$index) {
      $this->messenger()->addError($this->t('SharePoint media search index is not configured.'));
      return [
        '#markup' => $this->t('Search is currently unavailable.'),
      ];
    }

    // Get search parameters.
    $keywords = trim($request->query->get('keywords', ''));
    $filters = $this->parseFilters($request);
    $page = $request->query->get('page', 0);
    $items_per_page = 20;

    // Build search query.
    $query = $index->query();
    $results = $this->executeSearch($query, $keywords, $filters, $page, $items_per_page);

    // Format results for display.
    $formatted_results = $this->formatResults($results);

    // Get facets for filtering.
    $facets = $this->buildFacets($results, $filters);

    // Build render array.
    $build = [
      '#theme' => 'sharepoint_media_search_results',
      '#results' => $formatted_results,
      '#facets' => $facets,
      '#total' => $results->getResultCount(),
      '#keywords' => $keywords,
      '#current_page' => $page,
      '#items_per_page' => $items_per_page,
      '#filters' => $filters,
      '#attached' => [
        'library' => ['sharepoint_media/search_results'],
      ],
    ];

    // Add pager.
    $this->pagerManager->createPager($results->getResultCount(), $items_per_page);
    $build['pager'] = [
      '#type' => 'pager',
      '#weight' => 100,
    ];

    // Add search form.
    $search_form = $this->formBuilder()->getForm('Drupal\sharepoint_media\Form\SharePointMediaSearchForm');
    $search_form['#weight'] = -100;
    
    return [
      'search_form' => $search_form,
      'results' => $build,
    ];
  }

  /**
   * Execute the search query.
   */
  protected function executeSearch(QueryInterface $query, $keywords, array $filters, $page, $items_per_page) {
    // Add keywords search.
    if (!empty($keywords)) {
      $query->keys($keywords);
      $query->setFulltextFields([
        'name',
        'field_sharepoint_path',
        'sharepoint_keywords',
        'sharepoint_path_components',
        'sharepoint_technical_specs',
      ]);
    }

    // Add filters.
    $this->applyFilters($query, $filters);

    // Add facets.
    $query->setOption('search_api_facets', [
      'sharepoint_file_type' => [
        'field' => 'sharepoint_file_type',
        'limit' => 10,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
      'sharepoint_extension' => [
        'field' => 'sharepoint_extension',
        'limit' => 15,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
      'sharepoint_size_category' => [
        'field' => 'sharepoint_size_category',
        'limit' => 10,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
      'sharepoint_resolution' => [
        'field' => 'sharepoint_resolution',
        'limit' => 10,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
      'sharepoint_creation_year' => [
        'field' => 'sharepoint_creation_year',
        'limit' => 20,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
      'field_created_by' => [
        'field' => 'field_created_by',
        'limit' => 10,
        'operator' => 'or',
        'min_count' => 1,
        'missing' => FALSE,
      ],
    ]);

    // Add sorting.
    $sort = $this->request->query->get('sort', 'relevance');
    $this->applySorting($query, $sort, !empty($keywords));

    // Set pagination.
    $query->range($page * $items_per_page, $items_per_page);

    // Execute query.
    return $query->execute();
  }

  /**
   * Parse filters from request.
   */
  protected function parseFilters(Request $request) {
    $filters = [];

    // File type filter.
    $file_types = $request->query->all('file_type');
    if (!empty($file_types)) {
      $filters['file_type'] = array_values($file_types);
    }

    // Extension filter.
    $extensions = $request->query->all('extension');
    if (!empty($extensions)) {
      $filters['extension'] = array_values($extensions);
    }

    // Size filter.
    $sizes = $request->query->all('size');
    if (!empty($sizes)) {
      $filters['size'] = array_values($sizes);
    }

    // Resolution filter.
    $resolutions = $request->query->all('resolution');
    if (!empty($resolutions)) {
      $filters['resolution'] = array_values($resolutions);
    }

    // Year filter.
    $years = $request->query->all('year');
    if (!empty($years)) {
      $filters['year'] = array_map('intval', $years);
    }

    // Creator filter.
    $creators = $request->query->all('creator');
    if (!empty($creators)) {
      $filters['creator'] = array_values($creators);
    }

    // Date range filter.
    $date_from = $request->query->get('date_from');
    $date_to = $request->query->get('date_to');
    if ($date_from || $date_to) {
      $filters['date_range'] = [
        'from' => $date_from,
        'to' => $date_to,
      ];
    }

    // File size range filter.
    $size_min = $request->query->get('size_min');
    $size_max = $request->query->get('size_max');
    if ($size_min !== NULL || $size_max !== NULL) {
      $filters['size_range'] = [
        'min' => $size_min ? (int) $size_min * 1024 * 1024 : NULL, // Convert MB to bytes
        'max' => $size_max ? (int) $size_max * 1024 * 1024 : NULL,
      ];
    }

    return $filters;
  }

  /**
   * Apply filters to the search query.
   */
  protected function applyFilters(QueryInterface $query, array $filters) {
    // File type filter.
    if (!empty($filters['file_type'])) {
      $query->addCondition('sharepoint_file_type', $filters['file_type'], 'IN');
    }

    // Extension filter.
    if (!empty($filters['extension'])) {
      $query->addCondition('sharepoint_extension', $filters['extension'], 'IN');
    }

    // Size category filter.
    if (!empty($filters['size'])) {
      $query->addCondition('sharepoint_size_category', $filters['size'], 'IN');
    }

    // Resolution filter.
    if (!empty($filters['resolution'])) {
      $query->addCondition('sharepoint_resolution', $filters['resolution'], 'IN');
    }

    // Year filter.
    if (!empty($filters['year'])) {
      $query->addCondition('sharepoint_creation_year', $filters['year'], 'IN');
    }

    // Creator filter.
    if (!empty($filters['creator'])) {
      $query->addCondition('field_created_by', $filters['creator'], 'IN');
    }

    // Date range filter.
    if (!empty($filters['date_range'])) {
      $date_range = $filters['date_range'];
      if (!empty($date_range['from'])) {
        $query->addCondition('field_created_date', $date_range['from'], '>=');
      }
      if (!empty($date_range['to'])) {
        $query->addCondition('field_created_date', $date_range['to'], '<=');
      }
    }

    // File size range filter.
    if (!empty($filters['size_range'])) {
      $size_range = $filters['size_range'];
      if ($size_range['min'] !== NULL) {
        $query->addCondition('field_file_size', $size_range['min'], '>=');
      }
      if ($size_range['max'] !== NULL) {
        $query->addCondition('field_file_size', $size_range['max'], '<=');
      }
    }
  }

  /**
   * Apply sorting to the search query.
   */
  protected function applySorting(QueryInterface $query, $sort, $has_keywords) {
    switch ($sort) {
      case 'name_asc':
        $query->sort('name', QueryInterface::SORT_ASC);
        break;

      case 'name_desc':
        $query->sort('name', QueryInterface::SORT_DESC);
        break;

      case 'date_asc':
        $query->sort('field_created_date', QueryInterface::SORT_ASC);
        break;

      case 'date_desc':
        $query->sort('field_created_date', QueryInterface::SORT_DESC);
        break;

      case 'size_asc':
        $query->sort('field_file_size', QueryInterface::SORT_ASC);
        break;

      case 'size_desc':
        $query->sort('field_file_size', QueryInterface::SORT_DESC);
        break;

      case 'quality_desc':
        $query->sort('sharepoint_quality_score', QueryInterface::SORT_DESC);
        break;

      case 'relevance':
      default:
        if ($has_keywords) {
          $query->sort('search_api_relevance', QueryInterface::SORT_DESC);
        }
        else {
          // Default to date desc when no keywords.
          $query->sort('field_created_date', QueryInterface::SORT_DESC);
        }
        break;
    }
  }

  /**
   * Format search results for display.
   */
  protected function formatResults($results) {
    $formatted_results = [];

    foreach ($results as $result) {
      $entity = $result->getOriginalObject()->getValue();
      
      if (!$entity) {
        continue;
      }

      $formatted_result = [
        'entity' => $entity,
        'media_id' => $entity->id(),
        'title' => $entity->getName(),
        'score' => $result->getScore(),
        'relevance' => $this->formatRelevance($result->getScore()),
      ];

      // Basic information.
      $formatted_result['mime_type'] = $entity->get('field_mime_type')->value ?? '';
      $formatted_result['file_size'] = $entity->get('field_file_size')->value ?? 0;
      $formatted_result['formatted_file_size'] = $this->formatFileSize($formatted_result['file_size']);
      $formatted_result['created_by'] = $entity->get('field_created_by')->value ?? '';
      
      // URLs.
      $formatted_result['download_url'] = $entity->get('field_download_url')->value ?? '';
      $formatted_result['sharepoint_url'] = $entity->get('field_web_url')->value ?? '';
      $formatted_result['thumbnail_url'] = $entity->get('field_thumbnail_url')->value ?? '';
      
      // Stream/proxy URLs.
      $formatted_result['stream_url'] = Url::fromRoute('sharepoint_media.stream', [
        'media_id' => $entity->id(),
      ])->toString();
      
      $formatted_result['download_proxy_url'] = Url::fromRoute('sharepoint_media.download', [
        'media_id' => $entity->id(),
      ])->toString();

      // Metadata.
      $formatted_result['metadata'] = $this->extractDisplayMetadata($entity);
      
      // File type classification.
      $formatted_result['file_type'] = $this->getFileTypeFromMime($formatted_result['mime_type']);
      $formatted_result['file_type_icon'] = $this->getFileTypeIcon($formatted_result['file_type']);

      // Dates.
      $created_date = $entity->get('field_created_date')->value;
      $formatted_result['created_date'] = $created_date ? date('Y-m-d H:i', strtotime($created_date)) : '';
      $formatted_result['created_date_formatted'] = $created_date ? $this->formatDate(strtotime($created_date)) : '';

      $formatted_results[] = $formatted_result;
    }

    return $formatted_results;
  }

  /**
   * Build facets from search results.
   */
  protected function buildFacets($results, array $current_filters) {
    $facets = [];
    $facet_data = $results->getExtraData('search_api_facets', []);

    foreach ($facet_data as $facet_id => $facet_info) {
      $facet = [
        'id' => $facet_id,
        'label' => $this->getFacetLabel($facet_id),
        'values' => [],
      ];

      foreach ($facet_info as $value => $count) {
        $is_active = $this->isFacetValueActive($facet_id, $value, $current_filters);
        
        $facet['values'][] = [
          'value' => $value,
          'label' => $this->getFacetValueLabel($facet_id, $value),
          'count' => $count,
          'active' => $is_active,
          'url' => $this->buildFacetUrl($facet_id, $value, $current_filters, !$is_active),
        ];
      }

      // Sort facet values.
      $this->sortFacetValues($facet);
      
      $facets[$facet_id] = $facet;
    }

    return $facets;
  }

  /**
   * Extract metadata for display.
   */
  protected function extractDisplayMetadata($entity) {
    $metadata = [];

    // Image metadata.
    if ($entity->hasField('field_image_width') && !$entity->get('field_image_width')->isEmpty()) {
      $width = $entity->get('field_image_width')->value;
      $height = $entity->get('field_image_height')->value ?? 0;
      $metadata['resolution'] = $width . ' × ' . $height;
    }

    // Video metadata.
    if ($entity->hasField('field_video_width') && !$entity->get('field_video_width')->isEmpty()) {
      $width = $entity->get('field_video_width')->value;
      $height = $entity->get('field_video_height')->value ?? 0;
      $metadata['resolution'] = $width . ' × ' . $height;
    }

    if ($entity->hasField('field_duration') && !$entity->get('field_duration')->isEmpty()) {
      $duration = $entity->get('field_duration')->value;
      $metadata['duration'] = $this->formatDuration($duration);
    }

    // Camera metadata.
    if ($entity->hasField('field_camera_make') && !$entity->get('field_camera_make')->isEmpty()) {
      $make = $entity->get('field_camera_make')->value;
      $model = $entity->get('field_camera_model')->value ?? '';
      $metadata['camera'] = trim($make . ' ' . $model);
    }

    // Audio metadata.
    if ($entity->hasField('field_artist') && !$entity->get('field_artist')->isEmpty()) {
      $metadata['artist'] = $entity->get('field_artist')->value;
    }

    if ($entity->hasField('field_album') && !$entity->get('field_album')->isEmpty()) {
      $metadata['album'] = $entity->get('field_album')->value;
    }

    return $metadata;
  }

  /**
   * Helper methods for formatting and labels.
   */
  protected function formatFileSize($bytes) {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    
    return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
  }

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

  protected function formatDate($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 86400) { // Less than 24 hours
      return $this->t('@time ago', ['@time' => format_interval($diff)]);
    }
    elseif ($diff < 2592000) { // Less than 30 days
      return date('M j', $timestamp);
    }
    else {
      return date('M j, Y', $timestamp);
    }
  }

  protected function formatRelevance($score) {
    return number_format($score * 100, 1) . '%';
  }

  protected function getFileTypeFromMime($mime_type) {
    if (strpos($mime_type, 'image/') === 0) return 'image';
    if (strpos($mime_type, 'video/') === 0) return 'video';
    if (strpos($mime_type, 'audio/') === 0) return 'audio';
    if (strpos($mime_type, 'application/pdf') === 0) return 'pdf';
    return 'document';
  }

  protected function getFileTypeIcon($file_type) {
    $icons = [
      'image' => 'image',
      'video' => 'video',
      'audio' => 'music',
      'pdf' => 'file-pdf',
      'document' => 'file-text',
    ];
    
    return $icons[$file_type] ?? 'file';
  }

  protected function getFacetLabel($facet_id) {
    $labels = [
      'sharepoint_file_type' => $this->t('File Type'),
      'sharepoint_extension' => $this->t('Extension'),
      'sharepoint_size_category' => $this->t('File Size'),
      'sharepoint_resolution' => $this->t('Resolution'),
      'sharepoint_creation_year' => $this->t('Year'),
      'field_created_by' => $this->t('Created By'),
    ];
    
    return $labels[$facet_id] ?? $facet_id;
  }

  protected function getFacetValueLabel($facet_id, $value) {
    // Special handling for certain facets.
    if ($facet_id === 'sharepoint_extension') {
      return strtoupper($value);
    }
    
    return $value;
  }

  protected function isFacetValueActive($facet_id, $value, array $current_filters) {
    $filter_map = [
      'sharepoint_file_type' => 'file_type',
      'sharepoint_extension' => 'extension',
      'sharepoint_size_category' => 'size',
      'sharepoint_resolution' => 'resolution',
      'sharepoint_creation_year' => 'year',
      'field_created_by' => 'creator',
    ];
    
    $filter_key = $filter_map[$facet_id] ?? NULL;
    
    if ($filter_key && isset($current_filters[$filter_key])) {
      return in_array($value, (array) $current_filters[$filter_key]);
    }
    
    return FALSE;
  }

  protected function buildFacetUrl($facet_id, $value, array $current_filters, $add) {
    $filter_map = [
      'sharepoint_file_type' => 'file_type',
      'sharepoint_extension' => 'extension',
      'sharepoint_size_category' => 'size',
      'sharepoint_resolution' => 'resolution',
      'sharepoint_creation_year' => 'year',
      'field_created_by' => 'creator',
    ];
    
    $filter_key = $filter_map[$facet_id] ?? NULL;
    
    if (!$filter_key) {
      return '';
    }
    
    $query_params = $this->request->query->all();
    
    if ($add) {
      $query_params[$filter_key][] = $value;
    }
    else {
      if (isset($query_params[$filter_key])) {
        $query_params[$filter_key] = array_diff((array) $query_params[$filter_key], [$value]);
        if (empty($query_params[$filter_key])) {
          unset($query_params[$filter_key]);
        }
      }
    }
    
    return Url::fromRoute('sharepoint_media.search_results', [], [
      'query' => $query_params,
    ])->toString();
  }

  protected function sortFacetValues(array &$facet) {
    // Sort by count (descending) then by label.
    usort($facet['values'], function ($a, $b) {
      if ($a['count'] === $b['count']) {
        return strcmp($a['label'], $b['label']);
      }
      return $b['count'] - $a['count'];
    });
  }
}