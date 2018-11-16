<?php
/*------------------------------------------------------------+
| ITAD API extension                                          |
| Copyright (C) 2018 SYSTOPIA                                 |
| Author: J. Schuppe (schuppe@systopia.de)                    |
+-------------------------------------------------------------+
| This program is released as free software under the         |
| Affero GPL license. You can redistribute it and/or          |
| modify it under the terms of this license which you         |
| can read by viewing the included agpl.txt or online         |
| at www.gnu.org/licenses/agpl.html. Removal of this          |
| copyright header is strictly prohibited without             |
| written permission from the original author(s).             |
+-------------------------------------------------------------*/

use CRM_Itadapi_ExtensionUtil as E;

/**
 * ITADPloneUser.Get API specification
 * This is used for documentation and validation.
 *
 * @param array $params
 *   Description of fields supported by this API call.
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_i_t_a_d_plone_user_Get_spec(&$params) {
  $params['permission_type'] = array(
    'name' => 'permission_type',
    'title' => 'Permission type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The type(s) of the permission to retrieve Plone user names for, either "PloneGroup" or "Facility".',
  );
  $params['permission_id'] = array(
    'name' => 'permission_id',
    'title' => 'Permission ID',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The permission ID to retrieve Plone user names for.',
  );
}

/**
 * ITADPloneUser.Get API
 *
 * @param array $params
 *   API action parameters as defined in
 *   @see _civicrm_api3_i_t_a_d_plone_user_Get_spec.
 *
 * @return array
 *   API result descriptor
 *   @see civicrm_api3_create_success
 *   @see civicrm_api3_create_error
 */
function civicrm_api3_i_t_a_d_plone_user_Get($params) {
  try {
    if (!empty($params['permission_type'])) {
      // Allow single string values for permission type.
      if (!is_array($params['permission_type'])) {
        $params['permission_type'] = array($params['permission_type']);
      }

      // Reject unknown permission types.
      if (!empty($unknown_types = array_diff($params['permission_type'], array(
        'PloneGroup',
        'Facility'
      )))) {
        throw new CiviCRM_API3_Exception(
          E::ts('Unknown permission type(s) %1.', array(
            1 => implode(', ', $unknown_types),
          )),
          'invalid_format'
        );
      }
    }

    // Require a single permission type when given a permission ID.
    if (!empty($params['permission_id'])) {
      if (count($params['permission_type']) != 1) {
        throw new CiviCRM_API3_Exception(
          E::ts('A single permission type is required when given a permission ID.'),
          'invalid_format'
        );
      }
    }

    // The results array.
    $users = array();

    $plone_username_custom_field_key = CRM_Itadapi_CustomData::getCustomFieldKey(
      'plone_individual',
      'plone_username'
    );

    // Retrieve "PloneGroup" results.
    if (empty($params['permission_type']) || in_array('PloneGroup', $params['permission_type'])) {
      $group_params = array(
        CRM_Itadapi_CustomData::getCustomFieldKey(
          'plone_group',
          'is_plone_group'
        ) => 1,
        'options' => array(
          'limit' => 0,
        ),
      );
      if (!empty($params['permission_id'])) {
        $group_params['title'] = $params['permission_id'];
      }
      $groups = civicrm_api3('Group', 'get', $group_params);
      $contacts = civicrm_api3('Contact', 'get', array(
        'group' => array('IN' => array_keys($groups['values'])),
        'return' => array(
          $plone_username_custom_field_key,
          'group',
        ),
        'options' => array(
          'limit' => 0,
        ),
      ));
      foreach ($contacts['values'] as $contact) {
        foreach (explode(',', $contact['groups']) as $contact_group_id) {
          if (array_key_exists($contact_group_id, $groups['values'])) {
            $users[$contact[$plone_username_custom_field_key]]['PloneGroup'][] = $groups['values'][$contact_group_id]['title'];
          }
        }
      }
    }

    // Retrieve "Facility" results.
    if (empty($params['permission_type']) || in_array('Facility', $params['permission_type'])) {
      $plone_facility_code_custom_field_key = CRM_Itadapi_CustomData::getCustomFieldKey(
        'Anlagendaten',
        'plone_facility_code'
      );
      $relationship_type = civicrm_api3('RelationshipType', 'getsingle', array(
        'name_a_b' => 'Bearbeitungsberechtigt für Anlage',
        'name_b_a' => 'Bearbeitungsberechtigter Benutzer',
      ));

      $facility_params = array(
        'contact_type' => 'Organization',
        'contact_sub_type' => 'Anlage',
        'return' => $plone_facility_code_custom_field_key,
        'options' => array(
          'limit' => 0,
        ),
      );
      if (!empty($params['permission_id'])) {
        $facility_params[$plone_facility_code_custom_field_key] = $params['permission_id'];
      }
      $facilities = civicrm_api3('Contact', 'get', $facility_params);

      $relationships = civicrm_api3('Relationship', 'get', array(
        'relationship_type_id' => $relationship_type['id'],
        'contact_id_b' => array('IN' => array_keys($facilities['values'])),
        'options' => array(
          'limit' => 0,
        ),
      ));
      foreach ($relationships['values'] as $relationship) {
        $contact = civicrm_api3('Contact', 'getsingle', array(
          'id' => $relationship['contact_id_a'],
          'return' => $plone_username_custom_field_key,
        ));

        $users[$contact[$plone_username_custom_field_key]]['Facility'][] = $facilities['values'][$relationship['contact_id_b']][$plone_facility_code_custom_field_key];
      }
    }

    $result = civicrm_api3_create_success($users);
  }
  catch (CiviCRM_API3_Exception $exception) {
    $result = civicrm_api3_create_error($exception->getMessage());
  }

  return $result;
}
