<?php
/*------------------------------------------------------------+
| Animal Equality API extension                               |
| Copyright (C) 2019 SYSTOPIA                                 |
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

use CRM_Aeapi_ExtensionUtil as E;

/**
 * AEContact.Submit API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec
 *   Description of fields supported by this API call.
 *
 * @return void
 */
function _civicrm_api3_a_e_contact_Submit_spec(&$spec) {
  $spec['contact'] = array(
    'name' => 'contact',
    'title' => E::ts('Contact data'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
    'description' => E::ts('An array of contact data of which the contact_type key is mandatory.'),
  );
  $spec['groups'] = array(
    'name' => 'groups',
    'title' => E::ts('Group data'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => E::ts('An array of group data.'),
  );
  $spec['want_newsletter'] = array(
    'name' => 'want_newsletter',
    'title' => E::ts('Wants newsletter'),
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.required' => 0,
    'api.default' => 1,
    'description' => E::ts('Whether the contact subscribed to the newsletter.'),
  );
}

/**
 * AEContact.Submit API
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_a_e_contact_Submit($params) {
  try {
    // Parse JSON from "contact" and "groups" parameters.
    if (($params['contact'] = json_decode($params['contact'], JSON_OBJECT_AS_ARRAY)) === NULL) {
      throw new Exception(E::ts('Could not parse parameter "contact".'));
    }
    if (!empty($params['groups'])) {
      if (($params['groups'] = json_decode($params['groups'], JSON_OBJECT_AS_ARRAY)) === NULL) {
        throw new Exception(E::ts('Could not parse parameter "groups".'));
      }
    }

    // Check if contact ID already exists
    $contact_is_new = CRM_Aeapi_Submission::isNew($params['contact']['contact_type'], $params['contact']);

    // Retrieve contact ID for given contact data.
    $contact_id = CRM_Aeapi_Submission::getContact($params['contact']['contact_type'], $params['contact']);
    // Load contact.
    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $contact_id));

    // Add to group with given status.
    if (!empty($params['groups'])) {
      foreach($params['groups'] as $group_info) {
        list($group_name, $group_status) = explode(':', $group_info) + [NULL, CRM_Aeapi_Submission::GROUP_STATUS_ADDED];
        // Specific case of to petition for which the "Newsletter" group is enabled.
        if (strcasecmp($group_status, CRM_Aeapi_Submission::NEWSLETTER_GROUP) === 0) {
          if ($params['want_newsletter']) {
            // Set group-subscription status to pending
            $mailing_event_subscribe = civicrm_api3('MailingEventSubscribe', 'create', array(
              'contact_id' => $contact_id,
              'email' => $contact['email'],
              'group_id' => CRM_Aeapi_Submission::getGroupIdByName($group_name)
            ));
            $activity = civicrm_api3('Activity', 'create', array(
              'source_contact_id' => $contact_id,
              'activity_type_id' => 'Mailinglist Event',
              'subject' => 'Requested: '.$group_name.' (DoubleOptIn sent)',
              'status_id' => 'Completed'
            ));
          }
          continue;
        }
        if (strcasecmp($group_status, CRM_Aeapi_Submission::GROUP_STATUS_PENDING) === 0) {
          $mailing_event_subscribe = civicrm_api3('MailingEventSubscribe', 'create', array(
            'contact_id' => $contact_id,
            'email' => $contact['email'],
            'group_id' => CRM_Aeapi_Submission::getGroupIdByName($group_name),
          ));
          $activity = civicrm_api3('Activity', 'create', array(
            'source_contact_id' => $contact_id,
            'activity_type_id' => 'Mailinglist Event',
            'subject' => 'Requested: '.$group_name.' (DoubleOptIn sent)',
            'status_id' => 'Completed'
          ));
        }
        if (strcasecmp($group_status, CRM_Aeapi_Submission::DOI_NEW_GROUP) === 0) {
          if ($params['want_newsletter'] && $contact_is_new) {
            $group_contact = civicrm_api3('GroupContact', 'create', array(
              'contact_id' => $contact_id,
              'group_id' => CRM_Aeapi_Submission::getGroupIdByName($group_name),
              'status' => 'Added'
            ));
          }
        }
        else {
          $group_contact = civicrm_api3('GroupContact', 'create', array(
            'contact_id' => $contact_id,
            'group_id' => CRM_Aeapi_Submission::getGroupIdByName($group_name),
            'status' => ucfirst($group_status)
          ));
        }
      }
    }

    return civicrm_api3_create_success(
      array(
        'Contact' => $contact,
        'MailingEventSubscribe' => isset($mailing_event_subscribe) ? $mailing_event_subscribe : NULL,
        'Activity' => isset($activity) ? $activity : NULL,
        'GroupContact' => isset($group_contact) ? $group_contact : NULL,
      ),
      $params
    );
  }
  catch (Exception $exception) {
    return civicrm_api3_create_error($exception->getMessage());
  }
}
