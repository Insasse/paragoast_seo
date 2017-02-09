<?php

namespace Drupal\yoast_seo;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class YoastSeoFieldManager.
 *
 * @package Drupal\yoast_seo
 */
class YoastSeoFieldManager {

  public $fieldsConfiguration = [
    // Paths to access the fields inside the form array.
    'paths' => [
      'title' => 'title.widget.0.value',
      'focus_keyword' => 'field_yoast_seo.widget.0.yoast_seo.focus_keyword',
      'seo_status' => 'field_yoast_seo.widget.0.yoast_seo.status',
      'path' => 'path.widget.0.alias',
    ],

    // Fields to include in the field section of the configuration.
    'fields' => [
      'title',
      'summary',
      'focus_keyword',
      'seo_status',
      'path',
    ],

    // Tokens for the fields.
    'tokens' => [
      '[current-page:title]' => 'title',
      '[node:title]' => 'title',
      '[current-page:summary]' => 'summary',
      '[node:summary]' => 'summary',
    ],
  ];

  /**
   * Metatag logging channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor for YoastSeoFieldManager.
   */
  public function __construct(EntityFieldManager $field_manager, TextFieldProcessor $text_field_processor) {
    $this->entity_manager = \Drupal::entityManager();
    $this->field_manager = $field_manager;
    $this->text_field_processor = $text_field_processor;
  }

  /**
   * Our helper to insert values in a form from a given key.
   *
   * Example : formSet($form, 'myform.#value', 'valueToInsert');
   * TODO : move this helper somewhere else.
   *
   * @param array $form
   *   The form.
   * @param string $key
   *   The key.
   * @param mixed $value
   *   Value.
   *
   * @return mixed
   *   Form with the set value.
   */
  private function formSet(&$form, $key, $value) {
    return NestedArray::setValue(
      $form,
      explode('.', $key),
      $value
    );
  }

  /**
   * Our helper to retrieve values in a form from a given key.
   *
   * Example : formGet($form, 'myform.#value');
   * TODO : move this helper somewhere else.
   *
   * @param array $form
   *   The form.
   * @param string $key
   *   The key.
   *
   * @return mixed
   *   Value accessed by get.
   */
  private function formGet($form, $key) {
    return NestedArray::getValue(
      $form,
      explode('.', $key)
    );
  }

  /**
   * Attach a field to a target content type.
   *
   * @param string $entity_type
   *   Entity type. Example 'node'.
   * @param string $bundle
   *   Bundle type.
   * @param mixed $field
   *   Field.
   */
  public function attachField($entity_type, $bundle, $field) {
    // Retrieve the yoast seo field attached to the target entity.
    $field_storage_config = $this->entity_manager->getStorage('field_storage_config')
                                               ->load($entity_type . '.' . $field['field_name']);

    // If the field hasn't been attached yet to the target entity, attach it.
    if (is_null($field_storage_config)) {
      $this->entity_manager->getStorage('field_storage_config')
                           ->create([
                             'field_name' => $field['field_name'],
                             'entity_type' => $entity_type,
                             'type' => $field['storage_type'],
                             'translatable' => $field['translatable'],
                           ])
                           ->save();
    }

    // Retrieve the yoast seo field attached to the target content type.
    $fields_config = \Drupal::service('entity_field.manager')
                            ->getFieldDefinitions($entity_type, $bundle);

    // If the field hasn't been attached yet to the content type, attach it.
    if (!isset($fields_config[$field['field_name']])) {

      $field_values = [
        'field_name' => $field['field_name'],
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $field['field_label'],
        'translatable' => $field['translatable'],
      ];
      $this->entity_manager->getStorage('field_config')
                           ->create($field_values)
                           ->save();

      entity_get_form_display($entity_type, $bundle, 'default')
        ->setComponent($field['field_name'], array())
        ->save();
      entity_get_display($entity_type, $bundle, 'default')
        ->setComponent($field['field_name'], array())
        ->save();
    }
  }

  /**
   * Detach a field from a target content type.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string $field_name
   *   Field name.
   */
  public function detachField($entity_type, $bundle, $field_name) {
    $fields_config = \Drupal::service('entity_field.manager')
                            ->getFieldDefinitions($entity_type, $bundle);

    if (isset($fields_config[$field_name])) {
      $fields_config[$field_name]->delete();
    }
  }

  /**
   * Check if a field has been already attached to a bundle.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string $field_name
   *   Field name.
   *
   * @return bool
   *   Whether it is attached or not.
   */
  public function isAttached($entity_type, $bundle, $field_name) {
    $fields_config = \Drupal::service('entity_field.manager')
                            ->getFieldDefinitions($entity_type, $bundle);

    return isset($fields_config[$field_name]);
  }

  /**
   * Set fields configuration from a form.
   *
   * Explores the field present in the form and build a setting array
   * that will be used by yoast_seo javascript.
   *
   * @param array $form_after_build
   *   Node form after build.
   *
   * @return mixed
   *   Transformed form.
   */
  public function setFieldsConfiguration($form_after_build, FormStateInterface $form_state) {
    $yoast_settings = $form_after_build['#yoast_settings'];
    $body_field = isset($yoast_settings['body']) ? $yoast_settings['body'] : '';
    $body_element = FALSE;
    $build_info = $form_state->getBuildInfo();
    $form_entity = $build_info['callback_object'];
    $entity = $form_entity->getEntity();
    //$values = $this->arrayToString($this->getTextFieldValues($entity));
    if ($body_field && isset($form_after_build[$body_field]['widget'][0])) {
      $body_element = $form_after_build[$body_field]['widget'][0];
    }
    else {
      return $form_after_build;
    }

    // Provide preview for [node:summary] even if there is no summary.
    $summary_path = isset($body_element[0]['summary']) ? 'summary' : 'value';
    $this->fieldsConfiguration['paths']['summary'] = $body_field . '.widget.0.' . $summary_path;

    // Update field configurations.
    $this->fieldsConfiguration['paths'][$body_field] = $body_field . '.widget.0.value';
    $this->fieldsConfiguration['fields'][] = $body_field;
    $this->fieldsConfiguration['tokens']['[node:' . $body_field . ']'] = $body_field;
    $this->fieldsConfiguration['tokens']['[current-page:' . $body_field . ']'] = $body_field;
    $body_format = isset($body_element['#format']) ? $body_element['#format'] : '';

    // Fields requested.
    // Attach settings in drupalSettings for each required field.
    foreach ($this->fieldsConfiguration['fields'] as $field_name) {
      $field_id = $this->formGet($form_after_build, $this->fieldsConfiguration['paths'][$field_name] . '.#id');
      if ($field_name == $body_field) {
        $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields']['body'] = $field_id;
      }
      else {
        $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields'][$field_name] = $field_id;
      }
    }
    // Attach settings for the tokens.
    foreach ($this->fieldsConfiguration['tokens'] as $token => $field_name) {
      $form_after_build['#attached']['drupalSettings']['yoast_seo']['tokens'][$token] = $field_name;
    }
    // Other tokens commonly used.
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['tokens']['[site:name]'] = \Drupal::config('system.site')->get('name');
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['tokens']['[site:slogan]'] = \Drupal::config('system.site')->get('slogan');

    $is_default_meta_title = !empty($form_after_build['field_meta_tags']['widget'][0]['basic']['title']['#default_value']) ? TRUE : FALSE;
    $is_default_keyword = !empty($form_after_build['field_yoast_seo']['widget'][0]['yoast_seo']['focus_keyword']['#default_value']) ? TRUE : FALSE;
    $is_default_meta_description = !empty($form_after_build['field_meta_tags']['widget'][0]['basic']['description']['#default_value']) ? TRUE : FALSE;
    $body_exists = !empty($body_element['#default_value']) ? TRUE : FALSE;

    // The path default value.
    // @todo Should be completed once pathauto has been released for Drupal 8.
    $path = '';
    if (!empty($form_after_build['path']['widget'][0]['source']['#value'])) {
      $path = $form_after_build['path']['widget'][0]['source']['#value'];
    }

    $form_after_build['#attached']['drupalSettings']['yoast_seo']['default_text'] = [
      'meta_title' => $is_default_meta_title ? $form_after_build['field_meta_tags']['widget'][0]['basic']['title']['#default_value'] : '',
      'keyword' => $is_default_keyword ? $form_after_build['field_yoast_seo']['widget'][0]['yoast_seo']['focus_keyword']['#default_value'] : '',
      'meta_description' => $is_default_meta_description ? $form_after_build['field_meta_tags']['widget'][0]['basic']['description']['#default_value'] : '',
      $body_field => $body_exists ? $body_element['#default_value'] : '',
      'path' => $path,
    ];

    // FIELDS
    // Add Metatag fields.
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields']['meta_title'] = $form_after_build['field_meta_tags']['widget'][0]['basic']['title']['#id'];
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields']['meta_description'] = $form_after_build['field_meta_tags']['widget'][0]['basic']['description']['#id'];

    // Placeholders.
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['placeholder_text'] = [
      'snippetTitle' => t('Please click here to alter your page meta title'),
      'snippetMeta' => t('Please click here and alter your page meta description.'),
      'snippetCite' => t('/example-post'),
    ];

    $form_after_build['#attached']['drupalSettings']['yoast_seo']['seo_title_overwritten'] = $is_default_meta_title;
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['text_format'] = $body_format;

    // Form config.
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['form_id'] = $form_after_build['#id'];

    $text_fields = $this->text_field_processor->process($entity, $this->getAllFieldNames());
    $paragraph_text_fields = $this->filterTextFields();
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields']['paragraph_text_fields'] = $paragraph_text_fields;
    $form_after_build['#attached']['drupalSettings']['yoast_seo']['fields']['text_fields'] = $text_fields;
    return $form_after_build;
  }

  /**
   * Add code for snippet.
   *
   * @param array $form
   *   Form.
   *
   * @return array
   *   Form.
   */
  public function addSnippetEditorMarkup($form) {
    $yoast_settings = $form['#yoast_settings'];
    $body_field = isset($yoast_settings['body']) ? $yoast_settings['body'] : '';
    $yoast_seo_manager = \Drupal::service('yoast_seo.manager');
    $output = $yoast_seo_manager->getSnippetEditorMarkup();
    $body_weight = isset($form[$body_field]['#weight']) ? $form[$body_field]['#weight'] : 20;

    // Add rendered template to the form, where we want the snippet.
    $this->formSet($form, 'field_yoast_seo.widget.0.yoast_seo.snippet_analysis', [
      '#weight' => $body_weight + 1,
      '#markup' => $output,
    ]);

    return $form;
  }

  /**
   * Add Overall score markup to the form.
   *
   * @param array $form
   *   The form.
   *
   * @return mixed
   *   Modified form.
   */
  public function addOverallScoreMarkup($form, &$form_state) {
    $yoast_seo_manager = \Drupal::service('yoast_seo.manager');

    // Retrieve the entity stored score.
    $field_value = $form_state->getFormObject()
      ->getEntity()
      ->get('field_yoast_seo')
      ->getValue();
    $score = isset($field_value[0]['status']) ? $field_value[0]['status'] : 0;

    // Render the score markup.
    $output = $yoast_seo_manager->getOverallScoreMarkup($score);

    $this->formSet($form, 'field_yoast_seo.widget.0.yoast_seo.focus_keyword.#suffix', $output);

    return $form;
  }

  /**
   * Filter Paragraph Text Fields.
   *
   * @return array
   *   Array of text field-name => field-name.
   */
  public function filterTextFields() {
    $text_field_types = ['text_with_summary', 'text_long', 'text'];
    $paragraph_text_types = [];
    $paragraph_text_fields = [];

    foreach ($text_field_types as $text_field_type) {
      $text_fields[$text_field_type] = $this->field_manager->getFieldMapByFieldType($text_field_type);
      foreach ($text_fields as $entity_type_id) {
        // Filter paragraph text fields.
        if ($entity_type_id['paragraph']) {
          $paragraph_text_types[$text_field_type] = $entity_type_id['paragraph'];
        }
      }
    }

    foreach ($paragraph_text_types as $type => $fields) {
      foreach ($fields as $field_name => $bundles) {
        $paragraph_text_fields[$field_name] = $field_name;
      }
    }

    return $paragraph_text_fields;

  }

  public function getEntityReferenceRevisionsFieldNames() {
    $fields = $this->field_manager->getFieldMapByFieldType('entity_reference_revisions');
    $entity_refernce_revision_field_names = [];
    foreach ($fields as $entity_id => $field) {
      foreach ($field as $field_name => $value) {
        $entity_refernce_revision_field_names[$field_name] = $field_name;
      }
    }

    return $entity_refernce_revision_field_names;
  }

  public function getAllFieldNames() {
    $field_names = [];
    $text_field_names = $this->filterTextFields();
    $entity_reference_revision_field_names = $this->getEntityReferenceRevisionsFieldNames();
    foreach ($text_field_names as $text_field_name) {
      $field_names[$text_field_name] = TRUE;
    }
    foreach ($entity_reference_revision_field_names as $entity_reference_revision_field_name) {
      $field_names[$entity_reference_revision_field_name] = FALSE;
    }
    return $field_names;
  }

}

