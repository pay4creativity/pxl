<?php

namespace Drupal\conditional_fields;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Helper to alter the element info.
 */
class ConditionalFieldsElementAlterHelper {

  /**
   * ConditionalFieldsElementAlterHelper constructor.
   */
  public function __construct() {
  }

  /**
   * Processes form elements with dependencies.
   *
   * Just adds a #conditional_fields property to the form with the needed
   * data, which is used later in
   * \Drupal\conditional_fields\ConditionalFieldsFormHelper::afterBuild():
   * - The fields #parents property.
   * - Field dependencies data.
   */
  public function afterBuild(array $element, FormStateInterface &$form_state) {
    // A container with a field widget.
    // Element::children() is probably a better fit.
    if (isset($element['widget'])) {
      $field = $element['widget'];
    }
    else {
      $field = $element;
    }

    $first_parent = reset($field['#parents']);

    // No parents, so bail out.
    if (!isset($first_parent) || (isset($field['#type']) && $field['#type'] == 'value') ) {
      return $element;
    }

    $form = &$form_state->getCompleteForm();
    // Some fields do not have entity type and bundle properties.
    // In this case we try to use the properties from the form.
    // This is not an optimal solution, since in case of fields
    // in entities within entities they might not correspond,
    // and their dependencies will not be loaded.
    $build_info = $form_state->getBuildInfo();
    if (method_exists($build_info['callback_object'], 'getEntity')) {
      $entity = $build_info['callback_object']->getEntity();
      if ($entity instanceof EntityInterface) {
        $bundle = $entity->bundle();
        $entity_type = $entity->getEntityTypeId();

        /**
         * @deprecated Not actual from Drupal 8.7.0.
         * Media entity returns the actual bundle object, rather than id
         */
        if (is_object($bundle) && method_exists($bundle, 'getPluginId')) {
          $bundle = $bundle->getPluginId();
        }

        $paragraph_bundle = conditional_fields_get_paragraph_bundle($field, $form);
        $bundle = $paragraph_bundle ?: $bundle;
        $is_related_to_paragraph = (bool) $paragraph_bundle;
        $entity_type = $is_related_to_paragraph ? 'paragraph' : $entity_type;
        $dependencies = $this->loadDependencies($entity_type, $bundle);

        if (!$dependencies) {
          return $element;
        }
         // We only add requirement on the widget parent and not on child.
        if (
          count($field['#array_parents']) > 1 &&
          $field['#array_parents'][count($field['#array_parents']) - 2] === 'widget' &&
          is_int($field['#array_parents'][count($field['#array_parents']) - 1])
        ) {
          return $element;
        }
        $field_name = reset($field['#array_parents']);

        // We get the name of the field inside the the paragraph where the
        // conditions are being applied, instead of the field name where the
        // paragraph is.
        if ($is_related_to_paragraph) {
          foreach ($field['#array_parents'] as $parent) {
            if (isset($dependencies['dependents'][$parent])) {
              $field_name = $parent;
              break;
            }

            if (isset($dependencies['dependees'][$parent])) {
              $field_name = $parent;
              break;
            }
          }

          if ($parent != $field_name || $first_parent == $field_name || !isset($field['#type'])) {
            return $element;
          }
        }

        $paragraph_info = [];

        if ($is_related_to_paragraph) {
          $paragraph_info['entity_type'] = $entity_type;
          $paragraph_info['bundle'] = $bundle;
          $paragraph_info['paragraph_field'] = $first_parent;
          $paragraph_info['array_parents'] = $element['#array_parents'];
        }

        // Attach dependent.
        if (isset($dependencies['dependents'][$field_name])) {
          foreach ($dependencies['dependents'][$field_name] as $id => $dependency) {
            if (!isset($form['#conditional_fields'][$field_name]['dependees'][$id]) || $this->isPriorityField($field)) {
              if ($is_related_to_paragraph) {
                $paragraph_info['field'] = $field_name;
              }
              $this->attachDependency($form, $form_state, ['#field_name' => $dependency['dependee']], $field, $dependency['options'], $id, $paragraph_info);
            }
          }
        }

      // Attach dependee.
      if (isset($dependencies['dependees'][$field_name])) {
          foreach ($dependencies['dependees'][$field_name] as $id => $dependency) {
            if (!isset($form['#conditional_fields'][$field_name]['dependents'][$id]) || $this->isPriorityField($field)) {
              if ($is_related_to_paragraph) {
                $paragraph_info['field'] = $field_name;
              }
              $this->attachDependency($form, $form_state, $field, ['#field_name' => $dependency['dependent']], $dependency['options'], $id, $paragraph_info);
            }
          }
        }
      }
    }

    return $element;
  }

  /**
   * Loads all dependencies from the database for a given bundle.
   */
  public function loadDependencies($entity_type, $bundle) {
    static $dependency_helper;
    if (!isset($dependency_helper[$entity_type][$bundle])) {
      $dependency_helper[$entity_type][$bundle] = new DependencyHelper($entity_type, $bundle);
    }
    return $dependency_helper[$entity_type][$bundle]->getBundleDependencies();
  }

  /**
   * Checking if field is priority for rewrite the conditions.
   *
   * If the field widget is datelist this function help to return correct object for this field.
   *
   * @param array $field
   *   The field form element.
   *
   * @return bool
   *   Check the fields is priority and return the boolean result
   */
  public function isPriorityField(array $field) {
    $priority_fields = [
      'datelist',
    ];
    // For modules supports.
    \Drupal::moduleHandler()->alter(['conditional_fields_priority_field'], $priority_fields);

    if (isset($field['#type']) && in_array($field['#type'], $priority_fields)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Attaches a single dependency to a form.
   *
   * Call this function when defining or altering a form to create dependencies
   * dynamically.
   *
   * @param array $form
   *   The form where the dependency is attached.
   * @param string $dependee
   *   The dependee field form element. Either a string identifying the element
   *   key in the form, or a fully built field array. Actually used properties of
   *   the array are #field_name and #parents.
   * @param string $dependent
   *   The dependent field form element. Either a string identifying the element
   *   key in the form, or a fully built field array. Actually used properties of
   *   the array are #field_name and #field_parents.
   * @param array $options
   *   An array of dependency options with the following key/value pairs:
   *   - state: The state applied to the dependent when the dependency is
   *     triggered. See conditionalFieldsStates() for available states.
   *   - condition: The condition for the dependency to be triggered. See
   *     conditionalFieldsConditions() for available conditions.
   *   - values_set: One of the following constants:
   *     - ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_WIDGET: Dependency is
   *       triggered if the dependee has a certain value defined in 'value'.
   *     - ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_AND: Dependency is triggered if
   *       the dependee has all the values defined in 'values'.
   *     - ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_OR: Dependency is triggered if the
   *       dependee has any of the values defined in 'values'.
   *     - ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_XOR: Dependency is triggered if
   *       the dependee has only one of the values defined in 'values'.
   *     - ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_NOT: Dependency is triggered if
   *       the dependee does not have any of the values defined in 'values'.
   *   - value: The value to be tested when 'values_set' is
   *     ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_WIDGET. An associative array with
   *     the same structure of the dependee field values as found in
   *     $form_states['values] when the form is submitted. You can use
   *     field_default_extract_form_values() to extract this array.
   *   - values: The array of values to be tested when 'values_set' is not
   *     ConditionalFieldsInterface::CONDITIONAL_FIELDS_DEPENDENCY_VALUES_WIDGET.
   *   - value_form: An associative array with the same structure of the dependee
   *     field values as found in $form_state['input']['value']['field'] when the
   *     form is submitted.
   *   - effect: The jQuery effect associated to the state change. See
   *     conditionalFieldsEffects() for available effects and options.
   *   - effect_options: The options for the active effect.
   *   - selector: (optional) Custom jQuery selector for the dependee.
   * @param int $id
   *   (internal use) The identifier for the dependency. Omit this parameter when
   *   attaching a custom dependency.
   *
   *   Note that you don't need to manually set all these options, since default
   *   settings are always provided.
   */
  public function attachDependency(&$form, &$form_state, $dependee, $dependent, $options, $id = 0, $paragraph_info = []) {
    // The absence of the $id parameter identifies a custom dependency.
    if (!$id) {
      // String values are accepted to simplify usage of this function with custom
      // forms.
      if (is_string($dependee) && is_string($dependent)) {
        $dependee = [
          '#field_name' => $dependee,
          '#parents' => [$dependee],
        ];
        $dependent = [
          '#field_name' => $dependent,
          '#field_parents' => [$dependent],
        ];

        // Custom dependencies have automatically assigned a progressive id.
        static $current_id;
        if (!$current_id) {
          $current_id = 1;
        }
        $id = $current_id;
        $current_id++;
      }
    }

    // Attach dependee.
    // Use the #array_parents property of the dependee instead of #field_parents
    // since we will need access to the full structure of the widget.
    if (isset($dependee['#array_parents'])) {

      $dependee_index = $dependee['#parents'][0];
      if ($paragraph_info) {
        $dependee_index = conditional_fields_get_simpler_id($dependee['#id']);
      }

      $form['#conditional_fields'][$dependee_index]['index'] = $dependee_index;
      $form['#conditional_fields'][$dependee_index]['base'] =  $dependee_index;

      // $form['#conditional_fields'][$dependee_index]['id'] = $dependee['#id'];
      $form['#conditional_fields'][$dependee_index]['is_from_paragraph'] = (bool) $paragraph_info;
      $form['#conditional_fields'][$dependee_index]['parents'] = $dependee['#array_parents'];
      $form['#conditional_fields'][$dependee_index]['dependents'][$id] = [
        'dependent' => $dependent['#field_name'],
        'options' => $options,
      ];
    }

    // Attach dependent.
    if (!empty($dependent['#parents'])) {
      $dependent_parents = $dependent['#parents'];
      // If the field type is Date, we need to remove the last "date" parent key,
      // since it is not part of the $form_state value when we validate it.
      if (isset($dependent['#type']) && $dependent['#type'] === 'date') {
        array_pop($dependent_parents);
      }
    }
    elseif (isset($dependent['#field_parents'])) {
      $dependent_parents = $dependent['#field_parents'];
    }

    if (isset($dependent_parents)) {
      $dependent_index = $dependent['#parents'][0];

      if ($paragraph_info) {
        $dependent_index = conditional_fields_get_simpler_id($dependent['#id']);
      }

      // This worked to get the value_form when the value_form was an array.
      if (isset($options['value_form'][0]['value']) && $paragraph_info) {
        $options['value_form'] = $options['value_form'][0]['value'];
      }

      // $form['#conditional_fields'][$dependent_index]['id'] = $dependent['#id'];
      $form['#conditional_fields'][$dependent_index]['is_from_paragraph'] = (bool) $paragraph_info;

      $form['#conditional_fields'][$dependent_index]['index'] = $dependent_index;;

      $form['#conditional_fields'][$dependent_index]['field_parents'] = $dependent_parents;
      $form['#conditional_fields'][$dependent_index]['array_parents'] = $paragraph_info['array_parents'] ?? [];
      $form['#conditional_fields'][$dependent_index]['dependees'][$id] = [
        'dependee' => $dependee['#field_name'],
        'options' => $options,
      ];
    }
  }

}
