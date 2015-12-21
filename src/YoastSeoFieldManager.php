<?php

/**
 * @file
 * Contains the \Drupal\yoast_seo\YoastSeoFieldManager class.
 */

namespace Drupal\yoast_seo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use \Drupal\field\Entity\FieldConfig;
use \Drupal\Component\Utility\NestedArray;
use \Drupal\Component\Utility\Html;

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
      'body' => 'body.widget.0.value',
      'focus_keyword' => 'field_yoast_seo.widget.0.yoast_seo.focus_keyword',
      'seo_status' => 'field_yoast_seo.widget.0.yoast_seo.status',
    ],

    // Tokens for the fields.
    'tokens' => [
      'title' => '[current-page:title]',
      'body' => '[current-page:body]',
    ],

    'targets' => [

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
  public function __construct() {
    $this->entity_manager = \Drupal::entityManager();

    // Get Output generated ids.
    $this->fieldsConfiguration['targets'] = [
      'wrapper_target_id' => Html::getUniqueId('yoast-wrapper'),
      'snippet_target_id' => Html::getUniqueId('yoast-snippet'),
      'output_target_id' => Html::getUniqueId('yoast-output'),
    ];
  }

  /**
   * Attach a field to a target content type.
   *
   * @param $entity_type
   * @param $bundle
   * @param $field
   */
  public function attachField($entity_type, $bundle, $field) {
    // Retrieve the yoast seo field attached to the target entity.
    $fieldStorageConfig = $this->entity_manager->getStorage('field_storage_config')
                                               ->load($entity_type . '.' . $field['field_name']);

    // If the field hasn't been attached yet to the target entity, attach it.
    if (is_null($fieldStorageConfig)) {
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
   * @param $entity_type
   * @param $bundle
   * @param $field_name
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
   * @param $entity_type
   * @param $bundle
   * @param $field_name
   *
   * @return boolean
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
   * @param $formAfterBuild
   *   node form after build
   *
   * @return mixed
   *   transformed form
   */
  public function setFieldsConfiguration($formAfterBuild) {
    // Fields requested.

    // Attach settings in drupalSettings for each required field.
    foreach ($this->fieldsConfiguration['paths'] as $fieldName => $fieldPath) {
      $fieldId = NestedArray::getValue($formAfterBuild, explode('.', $fieldPath . '.#id'));
      $formAfterBuild['#attached']['drupalSettings']['yoast_seo']['fields'][$fieldName] = $fieldId;
    }

    // Attach settings for the tokens.
    foreach($this->fieldsConfiguration['tokens'] as $fieldName => $token) {
      $formAfterBuild['#attached']['drupalSettings']['yoast_seo']['tokens'][$fieldName] = $token;
    }

    // Attach settings for the targets.
    foreach($this->fieldsConfiguration['targets'] as $targetName => $targetId) {
      $formAfterBuild['#attached']['drupalSettings']['yoast_seo']['targets'][$targetName] = $targetId;
    }

    return $formAfterBuild;
  }

  /**
   * Add code for snippet.
   * @param $form
   */
  public function addSnippet($form) {

    // Get template for the snippet.
    $snippetTpl = [
      '#theme' => 'snippet',
      '#wrapper_target_id' => $this->fieldsConfiguration['targets']['wrapper_target_id'],
      '#snippet_target_id' => $this->fieldsConfiguration['targets']['snippet_target_id'],
      '#output_target_id' => $this->fieldsConfiguration['targets']['output_target_id'],
    ];
    $output = drupal_render($snippetTpl);

    // Add rendered template to the form, where we want the snippet.
    NestedArray::setValue(
      $form,
      explode('.', 'field_yoast_seo.widget.0.yoast_seo.snippet_analysis'),
      [
        '#weight' => $form['body']['#weight'] + 1,
        '#markup' => $output,

      ]
    );
    return $form;
  }

  //public function save(array $form, FormStateInterface $form_state) {
  //  $metatag_defaults = $this->entity;
  //
  //  // Set the label on new defaults.
  //  if ($metatag_defaults->isNew()) {
  //    $metatag_defaults_id = $form_state->getValue('id');
  //    list($entity_type, $entity_bundle) = explode('__', $metatag_defaults_id);
  //    // Get the entity label.
  //    $entity_manager = \Drupal::service('entity.manager');
  //    $entity_info    = $entity_manager->getDefinitions();
  //    $entity_label   = (string) $entity_info[$entity_type]->get('label');
  //    // Get the bundle label.
  //    $bundle_info  = $entity_manager->getBundleInfo($entity_type);
  //    $bundle_label = $bundle_info[$entity_bundle]['label'];
  //    // Set the label to the config entity.
  //    $this->entity->set('label', $entity_label . ': ' . $bundle_label);
  //  }
  //
  //  // Set tags within the Metatag entity.
  //  $tag_manager = \Drupal::service('plugin.manager.metatag.tag');
  //  $tags        = $tag_manager->getDefinitions();
  //  $tag_values  = array();
  //  foreach ($tags as $tag_id => $tag_definition) {
  //    if ($form_state->hasValue($tag_id)) {
  //      // Some plugins need to process form input before storing it.
  //      // Hence, we set it and then get it.
  //      $tag = $tag_manager->createInstance($tag_id);
  //      $tag->setValue($form_state->getValue($tag_id));
  //      if (!empty($tag->value())) {
  //        $tag_values[$tag_id] = $tag->value();
  //      }
  //    }
  //  }
  //  $metatag_defaults->set('tags', $tag_values);
  //  $status = $metatag_defaults->save();
  //
  //  switch ($status) {
  //    case SAVED_NEW:
  //      drupal_set_message($this->t('Created the %label Metatag defaults.', [
  //        '%label' => $metatag_defaults->label(),
  //      ]));
  //      break;
  //    default:
  //      drupal_set_message($this->t('Saved the %label Metatag defaults.', [
  //        '%label' => $metatag_defaults->label(),
  //      ]));
  //  }
  //
  //  $form_state->setRedirectUrl($metatag_defaults->urlInfo('collection'));
  //}

}
