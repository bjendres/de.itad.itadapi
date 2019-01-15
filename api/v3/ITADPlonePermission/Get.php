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
 * ITADPlonePermission.Get API specification
 * This is used for documentation and validation.
 *
 * @param array $params
 *   Description of fields supported by this API call.
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_i_t_a_d_plone_permission_Get_spec(&$params) {
  $params['permission_type'] = array(
    'name' => 'permission_type',
    'title' => 'Permission type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The type of the permission to retrieve, either "PloneGroup" or "Facility".',
  );
  $params['plone_username'] = array(
    'name' => 'plone_username',
    'title' => 'Plone User name',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The Plone user name of the user to retrieve permissions for.',
  );
  $params['contact_id'] = array(
    'name' => 'contact_id',
    'title' => 'CiviCRM Contact ID',
    'type' => CRM_Utils_Type::T_INT,
    'api-required' => 0,
    'description' => 'The CiviCRM Contact ID of the user to retrieve permissions for.',
  );
}

/**
 * ITADPlonePermission.Get API
 *
 * @param array $params
 *   API action parameters as defined in
 *   @see _civicrm_api3_i_t_a_d_permission_Get_spec.
 *
 * @return array
 *   API result descriptor.
 *   @see civicrm_api3_create_success
 *   @see civicrm_api3_create_error
 */
function civicrm_api3_i_t_a_d_plone_permission_Get($params) {
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

    // Retrieve contact for given Plone user name.
    if (!empty($params['plone_username'])) {
      $contact = civicrm_api3('Contact', 'getsingle', array(
        CRM_Itadapi_CustomData::getCustomFieldKey(
          'plone_individual',
          'plone_username'
        ) => $params['plone_username'],
        'return' => 'group',
      ));
    }

    // Retrieve contact for given contact ID.
    if (!empty($params['contact_id'])) {
      $contact = civicrm_api3('Contact', 'getsingle', array(
        'id' => $params['contact_id'],
        'return' => 'group',
      ));
    }

    // The results array.
    $permissions = array();

    // Retrieve "PloneGroup" results.
    if (empty($params['permission_type']) || in_array('PloneGroup', $params['permission_type'])) {
      $plone_group_results = array();

      $plone_groups = civicrm_api3('Group', 'get', array(
        CRM_Itadapi_CustomData::getCustomFieldKey(
          'plone_group',
          'is_plone_group'
        ) => 1,
        'options' => array(
          'limit' => 0,
        ),
      ));

      // If no Plone user name is given, retrieve all Plone groups.
      if (!isset($contact)) {
        foreach ($plone_groups['values'] as $plone_group_id => $plone_group) {
          $plone_group_results[] = $plone_group_id;
        }
      }

      // If a Plone user name or CiviCRM contact ID is given, retrieve their
      // Plone group memberships.
      else {
        foreach (explode(',', $contact['groups']) as $contact_group_id) {
          if (array_key_exists($contact_group_id, $plone_groups['values'])) {
            $plone_group_results[] = $contact_group_id;
          }
        }
      }

      // Add Plone group results to permissions array.
      foreach ($plone_group_results as $plone_group_id) {
        $permissions[] = $plone_groups['values'][$plone_group_id]['title'];
      }
    }

    // Retrieve "Facility" results.
    if (empty($params['permission_type']) || in_array('Facility', $params['permission_type'])) {
      $plone_facility_code_custom_field_key = CRM_Itadapi_CustomData::getCustomFieldKey(
        'Anlagendaten',
        'plone_facility_code'
      );

      $facilities = civicrm_api3('Contact', 'get', array(
        'contact_type' => 'Organization',
        'contact_sub_type' => 'Anlage',
        'return' => $plone_facility_code_custom_field_key,
        'options' => array(
          'limit' => 0,
        ),
      ));

      // If no Plone user name or CiviCRM contact ID is given, retrieve all
      // facilities.
      if (!isset($contact)) {
        foreach ($facilities['values'] as $facility_id => $facility) {
          $facility_results[] = $facility_id;
        }
      }
      else {
        // Retrieve facilities the contact has a relationship of type
        // "Bearbeitungsberechtigung" to.
        $relationship_type = civicrm_api3('RelationshipType', 'getsingle', array(
          'name_a_b' => 'Bearbeitungsberechtigt für Anlage',
          'name_b_a' => 'Bearbeitungsberechtigter Benutzer',
        ));

        $relationships = civicrm_api3('Relationship', 'get', array(
          'relationship_type_id' => $relationship_type['id'],
          'contact_id_a' => $contact['id'],
          'options' => array(
            'limit' => 0,
          ),
        ));

        foreach ($relationships['values'] as $relationship) {
          $facility_results[] = $relationship['contact_id_b'];
        }
      }

      // Add facility results to permissions array.
      foreach ($facility_results as $facility_id) {
        $permissions[] = $facilities['values'][$facility_id][$plone_facility_code_custom_field_key];
      }
    }

    $result = civicrm_api3_create_success($permissions);
  }
  catch (CiviCRM_API3_Exception $exception) {
    $result = civicrm_api3_create_error($exception->getMessage());
  }

  return $result;
}
