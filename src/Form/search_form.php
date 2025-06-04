<?php

namespace Drupal\sharepoint_media\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Form for searching SharePoint media.
 */
class SharePointMediaSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sharepoint_media_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $request = $request ?: $this->getRequest();
    
    // Get current search parameters.
    $current_keywords = $request->query->get('keywords', '');
    $current_filters = $this->getCurrentFilters($request);

    $form['#attributes']['class'][] = 'sharepoint-media-search-form';

    // Search input.
    $form['search_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-input-container']],
    ];

    $form['search_container']['keywords'] = [
      '#type' => 'search',
      '#title' => $this->t('Search Media'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Search by filename, creator, tags, or metadata...'),
      '#default_value' => $current_keywords,
      '#size' => 60,
      '#attributes' => [
        'class' => ['search-input'],
        'autocomplete' => 'off',
      ],
    ];

    $form['search_container']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#attributes' => ['class' => ['btn', 'btn-primary', 'search-submit']],
    ];

    // Advanced filters (collapsible).
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Filters'),
      '#open' => !empty(array_filter($current_filters)),
      '#attributes' => ['class' => ['search-filters']],
    ];

    // File type filter.
    $form['filters']['file_type'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('File Type'),
      '#options' => [
        'image' => $this->t('Images'),
        'video' => $this->t('Videos'),
        'audio' => $this->t('Audio'),
        'document' => $this->t('Documents'),
        'text' => $this->t('Text Files'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $current_filters['file_type'] ?? [],
      '#attributes' => ['class' => ['filter-checkboxes']],
    ];

    // File extension filter.
    $form['filters']['extension'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File Extensions'),
      '#description' => $this->t('Enter file extensions separated by commas (e.g., jpg, png, mp4)'),
      '#default_value' => !empty($current_filters['extension']) ? implode(', ', $current_filters['extension']) : '',
      '#placeholder' => 'jpg, png, mp4, pdf',
    ];

    // Date range filters.
    $form['filters']['date_range'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Date Range'),
      '#attributes' => ['class' => ['date-range-filter']],
    ];

    $form['filters']['date_range']['date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('From'),
      '#default_value' => $current_filters['date_range']['from'] ?? '',
    ];

    $form['filters']['date_range']['date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('To'),
      '#default_value' => $current_filters['date_range']['to'] ?? '',
    ];

    // File size range.
    $form['filters']['size_range'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('File Size (MB)'),
      '#attributes' => ['class' => ['size-range-filter']],
    ];

    $form['filters']['size_range']['size_min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum'),
      '#min' => 0,
      '#step' => 0.1,
      '#default_value' => $current_filters['size_range']['min'] ?? '',
      '#field_suffix' => 'MB',
    ];

    $form['filters']['size_range']['size_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum'),
      '#min' => 0,
      '#step' => 0.1,
      '#default_value' => $current_filters['size_range']['max'] ?? '',
      '#field_suffix' => 'MB',
    ];

    // Resolution filters for images/videos.
    $form['filters']['resolution'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Resolution'),
      '#options' => [
        '4K' => $this->t('4K (3840×2160)'),
        '1080p' => $this->t('1080p (1920×1080)'),
        '720p' => $this->t('720p (1280×720)'),
        'high' => $this->t('High (2MP+)'),
        'medium' => $this->t('Medium (0.5-2MP)'),
        'low' => $this->t('Low (<0.5MP)'),
      ],
      '#default_value' => $current_filters['resolution'] ?? [],
      '#attributes' => ['class' => ['filter-checkboxes']],
    ];

    // Duration filters for audio/video.
    $form['filters']['duration'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Duration'),
      '#options' => [
        'very-short' => $this->t('Very Short (<30s)'),
        'short' => $this->t('Short (30s-5m)'),
        'medium' => $this->t('Medium (5m-30m)'),
        'long' => $this->t('Long (30m-2h)'),
        'very-long' => $this->t('Very Long (2h+)'),
      ],
      '#default_value' => $current_filters['duration'] ?? [],
      '#attributes' => ['class' => ['filter-checkboxes']],
    ];

    // Creator filter.
    $form['filters']['creator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Created By'),
      '#description' => $this->t('Filter by creator name'),
      '#default_value' => !empty($current_filters['creator']) ? implode(', ', $current_filters['creator']) : '',
      '#autocomplete_route_name' => 'sharepoint_media.creator_autocomplete',
    ];

    // Sorting options.
    $form['filters']['sort'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort By'),
      '#options' => [
        'relevance' => $this->t('Relevance'),
        'name_asc' => $this->t('Name (A-Z)'),
        'name_desc' => $this->t('Name (Z-A)'),
        'date_desc' => $this->t('Newest First'),
        'date_asc' => $this->t('Oldest First'),
        'size_desc' => $this->t('Largest First'),
        'size_asc' => $this->t('Smallest First'),
        'quality_desc' => $this->t('Highest Quality'),
      ],
      '#default_value' => $request->query->get('sort', 'relevance'),
    ];

    // Action buttons.
    $form['filters']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions', 'search-form-actions']],
    ];

    $form['filters']['actions']['apply_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Filters'),
      '#attributes' => ['class' => ['btn', 'btn-secondary']],
    ];

    $form['filters']['actions']['clear_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Filters'),
      '#attributes' => ['class' => ['btn', 'btn-outline']],
      '#submit' => ['::clearFilters'],
    ];

    // Add quick search suggestions.
    $form['suggestions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-suggestions']],
      '#weight' => 100,
    ];

    $form['suggestions']['title'] = [
      '#markup' => '<h4>' . $this->t('Quick Searches') . '</h4>',
    ];

    $quick_searches = [
      'images today' => $this->t('Images from today'),
      'large videos' => $this->t('Large video files'),
      'high resolution' => $this->t('High resolution images'),
      'presentations' => $this->t('Presentation files'),
      'photos 2024' => $this->t('Photos from 2024'),
    ];

    $suggestion_links = [];
    foreach ($quick_searches as $query => $label) {
      $suggestion_links[] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('sharepoint_media.search_results', [], [
          'query' => ['keywords' => $query],
        ]),
        '#attributes' => ['class' => ['suggestion-link']],
      ];
    }

    $form['suggestions']['links'] = [
      '#theme' => 'item_list',
      '#items' => $suggestion_links,
      '#attributes' => ['class' => ['suggestion-list']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Build query parameters.
    $query_params = [];

    // Add keywords.
    if (!empty($values['keywords'])) {
      $query_params['keywords'] = trim($values['keywords']);
    }

    // Add file type filters.
    $file_types = array_filter($values['file_type'] ?? []);
    if (!empty($file_types)) {
      $query_params['file_type'] = array_keys($file_types);
    }

    // Add extension filters.
    if (!empty($values['extension'])) {
      $extensions = array_map('trim', explode(',', $values['extension']));
      $extensions = array_filter($extensions);
      if (!empty($extensions)) {
        $query_params['extension'] = $extensions;
      }
    }

    // Add date range.
    if (!empty($values['date_from']) || !empty($values['date_to'])) {
      if (!empty($values['date_from'])) {
        $query_params['date_from'] = $values['date_from'];
      }
      if (!empty($values['date_to'])) {
        $query_params['date_to'] = $values['date_to'];
      }
    }

    // Add size range.
    if (!empty($values['size_min']) || !empty($values['size_max'])) {
      if (!empty($values['size_min'])) {
        $query_params['size_min'] = $values['size_min'];
      }
      if (!empty($values['size_max'])) {
        $query_params['size_max'] = $values['size_max'];
      }
    }

    // Add resolution filters.
    $resolutions = array_filter($values['resolution'] ?? []);
    if (!empty($resolutions)) {
      $query_params['resolution'] = array_keys($resolutions);
    }

    // Add duration filters.
    $durations = array_filter($values['duration'] ?? []);
    if (!empty($durations)) {
      $query_params['duration'] = array_keys($durations);
    }

    // Add creator filter.
    if (!empty($values['creator'])) {
      $creators = array_map('trim', explode(',', $values['creator']));
      $creators = array_filter($creators);
      if (!empty($creators)) {
        $query_params['creator'] = $creators;
      }
    }

    // Add sorting.
    if (!empty($values['sort']) && $values['sort'] !== 'relevance') {
      $query_params['sort'] = $values['sort'];
    }

    // Redirect to results page.
    $form_state->setRedirect('sharepoint_media.search_results', [], [
      'query' => $query_params,
    ]);
  }

  /**
   * Clear filters submit handler.
   */
  public function clearFilters(array &$form, FormStateInterface $form_state) {
    // Preserve only the keywords.
    $keywords = $form_state->getValue('keywords');
    $query_params = [];
    
    if (!empty($keywords)) {
      $query_params['keywords'] = $keywords;
    }

    $form_state->setRedirect('sharepoint_media.search_results', [], [
      'query' => $query_params,
    ]);
  }

  /**
   * Get current filters from request.
   */
  protected function getCurrentFilters(Request $request) {
    $filters = [];

    // File type.
    $file_types = $request->query->all('file_type');
    if (!empty($file_types)) {
      $filters['file_type'] = array_values($file_types);
    }

    // Extensions.
    $extensions = $request->query->all('extension');
    if (!empty($extensions)) {
      $filters['extension'] = array_values($extensions);
    }

    // Date range.
    $date_from = $request->query->get('date_from');
    $date_to = $request->query->get('date_to');
    if ($date_from || $date_to) {
      $filters['date_range'] = [
        'from' => $date_from,
        'to' => $date_to,
      ];
    }

    // Size range.
    $size_min = $request->query->get('size_min');
    $size_max = $request->query->get('size_max');
    if ($size_min !== NULL || $size_max !== NULL) {
      $filters['size_range'] = [
        'min' => $size_min,
        'max' => $size_max,
      ];
    }

    // Resolution.
    $resolutions = $request->query->all('resolution');
    if (!empty($resolutions)) {
      $filters['resolution'] = array_values($resolutions);
    }

    // Duration.
    $durations = $request->query->all('duration');
    if (!empty($durations)) {
      $filters['duration'] = array_values($durations);
    }

    // Creator.
    $creators = $request->query->all('creator');
    if (!empty($creators)) {
      $filters['creator'] = array_values($creators);
    }

    return $filters;
  }

  /**
   * AJAX callback for search suggestions.
   */
  public function searchSuggestions(array &$form, FormStateInterface $form_state) {
    // This could be enhanced to provide live search suggestions.
    return $form['suggestions'];
  }
}