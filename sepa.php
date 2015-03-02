<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit                         |
| Copyright (C) 2013-2014 Project60                      |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

require_once 'sepa.civix.php';
require_once 'sepa_pp_sdd.php';


function sepa_civicrm_pageRun( &$page ) {
  if (get_class($page) == "CRM_Contact_Page_View_Summary") {
    // mods for summary view
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'CRM/Contact/Page/View/Summary.sepa.tpl'
    ));

  } elseif (get_class($page) == "CRM_Contribute_Page_Tab") {
    // single contribuion view

    if (!CRM_Sepa_Logic_Settings::isSDD(array('payment_instrument_id' => $page->getTemplate()->get_template_vars('payment_instrument_id'))))
      return;

    if ($page->getTemplate()->get_template_vars('contribution_recur_id')) {
      // This is an installment of a recurring contribution.
      $mandate = civicrm_api3('SepaMandate', 'getsingle', array('entity_table'=>'civicrm_contribution_recur', 'entity_id'=>$page->getTemplate()->get_template_vars('contribution_recur_id')));
    } 
    else {
      // this is a OOFF contribtion
      $mandate = civicrm_api3('SepaMandate', 'getsingle', array('entity_table'=>'civicrm_contribution', 'entity_id'=>$page->getTemplate()->get_template_vars('id')));
    }

    $page->assign('sepa', $mandate);

    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'Sepa/Contribute/Form/ContributionView.tpl'
    ));
  }
  
  elseif ( get_class($page) == "CRM_Contribute_Page_ContributionRecur") {
    // recurring contribuion view

    $recur = $page->getTemplate()->get_template_vars("recur");
    
    // This is a one-off contribution => try to show mandate data.
    $template_vars = $page->getTemplate()->get_template_vars('recur');
    $payment_instrument_id = $template_vars['payment_instrument_id'];
    if (!CRM_Sepa_Logic_Settings::isSDD(array('payment_instrument_id' => $payment_instrument_id)))
      return;

    $mandate = civicrm_api("SepaMandate","getsingle",array("version"=>3, "entity_table"=>"civicrm_contribution_recur", "entity_id"=>$recur["id"]));
    if (!array_key_exists("id",$mandate)) {
        CRM_Core_Error::fatal(ts("Can't find the sepa mandate"));
    }
    $page->assign("sepa",$mandate);
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'Sepa/Contribute/Page/ContributionRecur.tpl'
    ));
  }
}

function sepa_civicrm_buildForm ( $formName, &$form ) {
  // incorporate payment processor
  sepa_pp_buildForm($formName, $form);
}

function sepa_civicrm_postProcess( $formName, &$form ) {
  // incorporate payment processor
  sepa_pp_postProcess($formName, $form);
}

/**
 * Implementation of hook_civicrm_config
 */
function sepa_civicrm_config(&$config) {
/*
when civi 4.4, not sure how to make it compatible with both
CRM_Core_DAO_AllCoreTables::$daoToClass["SepaMandate"] = "CRM_Sepa_DAO_SEPAMandate";
CRM_Core_DAO_AllCoreTables::$daoToClass["SepaCreditor"] = "CRM_Sepa_DAO_SEPACreditor";
*/ 
  _sepa_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function sepa_civicrm_xmlMenu(&$files) {
  _sepa_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function sepa_civicrm_install() {
  $config = CRM_Core_Config::singleton();
  //create the tables
  $sql = file_get_contents(dirname( __FILE__ ) .'/sql/sepa.sql', true);
  CRM_Utils_File::sourceSQLFile($config->dsn, $sql, NULL, true);

  return _sepa_civix_civicrm_install();
}


function sepa_civicrm_install_options($data) {
  foreach ($data as $groupName => $group) {
    // check group existence
    $result = civicrm_api('option_group', 'getsingle', array('version' => 3, 'name' => $groupName));
    if (isset($result['is_error']) && $result['is_error']) {
      $params = array(
          'version' => 3,
          'sequential' => 1,
          'name' => $groupName,
          'is_reserved' => 1,
          'is_active' => 1,
          'title' => $group['title'],
          'description' => $group['description'],
      );
      $result = civicrm_api('option_group', 'create', $params);
      $group_id = $result['values'][0]['id'];
    } else {
      $group_id = $result['id'];
    }

    if (is_array($group['values'])) {
      $groupValues = $group['values'];
      $weight = 1;
      foreach ($groupValues as $valueName => $value) {
        $result = civicrm_api('option_value', 'getsingle', array('version' => 3, 'name' => $valueName));
        if (isset($result['is_error']) && $result['is_error']) {
          $params = array(
              'version' => 3,
              'sequential' => 1,
              'option_group_id' => $group_id,
              'name' => $valueName,
              'label' => $value['label'],
              'weight' => isset($value['weight']) ? $value['weight'] : $weight,
              'is_default' => $value['is_default'],
              'is_active' => 1,
          );
          if (isset($value['value'])) {
            $params['value'] = $value['value'];
          }
          $result = civicrm_api('option_value', 'create', $params);
        } else {
          $weight = $result['weight'] + 1;
        }
      }
    }
  }
}

function sepa_civicrm_options() {
  $result = civicrm_api('option_group', 'getsingle', array('version' => 3, 'name' => 'payment_instrument'));
  if (!isset($result['id'])) {
    die($result["error_message"]);    
  }
  $gid= $result['id'];

  //find the value to give to the payment instruments
  $query = "SELECT max( `weight` ) as weight FROM `civicrm_option_value` where option_group_id=" . $gid;
  $dao = new CRM_Core_DAO();
  $dao->query($query);
  $dao->fetch();
  $weight = $dao->weight + 1;

  // start with the lowest weight value
  return array(
      'msg_tpl_workflow_contribution' => array(
          'values' => array(
              'sepa_mandate_pdf' => array(
                  'label' => 'PDF Mandate',
                  'value' => 1,
                  'is_default' => 0,
              ),
              'sepa_mandate' => array(
                  'label' => 'Mail Sepa Mandate',
                  'value' => 1,
                  'is_default' => 0,
              ),
          ),
       ),
      
      // These will be used to mark a contribution with the correct type and will
      // greatly facilitate batching later on
      
      'payment_instrument' => array(
          'values' => array(
              'FRST' => array(
                  'label' => 'SEPA DD First Transaction',
                  'weight' => $weight,
                  'is_default' => 0,
              ),
              'RCUR' => array(
                  'label' => 'SEPA DD Recurring Transaction',
                  'weight' => $weight+1,
                  'is_default' => 0,
              ),
              'OOFF' => array(
                  'label' => 'SEPA DD One-off Transaction',
                  'weight' => $weight+2,
                  'is_default' => 0,
              ),
          ),
      ),

      'sepa_file_format' => array(
          'title' => 'SEPA XML File Format Variants',
          'description' => '',
          'is_reserved' => 1,
          'is_active' => 1,
          'values' => array(
            'pain.008.001.02' => array(
              'label' => ts('pain.008.001.02 (ISO 20022/official SEPA guidelines)'),
              'is_default' => 1,
              'is_reserved' => 1,
              'value' => 1,
            ),
            'pain.008.003.02' => array(
              'label' => ts('pain.008.003.02 container core direct debit (CDC EBICS-2.7)'),
              'is_default' => 0,
              'is_reserved' => 1,
              'value' => 2,
            ),
            'pain.008.003.02 COR1' => array(
              'label' => ts('pain.008.003.02 COR1 direct debit (CD1 EBICS-2.7)'),
              'is_default' => 0,
              'is_reserved' => 1,
              'value' => 3,
            ),
          ),
        ),

      'batch_status' => array(
        'values' => array(
        'Received' => array(
            'label' => 'Received',
            'is_default' => 0,
            'weight' => 6,
          ),
         ),
        ),
    );
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function sepa_civicrm_uninstall() {
  //should we delete the tables?
  return _sepa_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function sepa_civicrm_enable() {
  //add/check the required option groups
  sepa_civicrm_install_options(sepa_civicrm_options());
  
  // add all required message templates
  require_once 'CRM/Sepa/Page/SepaMandatePdf.php';
  CRM_Sepa_Page_SepaMandatePdf::installMessageTemplate();

  // install/activate SEPA payment processor
  sepa_pp_install();

  // create a dummy creditor if no creditor exists
  $creditorCount = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM `civicrm_sdd_creditor`;');    
  if (empty($creditorCount)) {
    error_log("org.project60.sepa_dd: Trying to install dummy creditor.");
    // to create, we need to first find a default contact
    $default_contact = 0;
    $domains = civicrm_api('Domain', 'get', array('version'=>3));
    foreach ($domains['values'] as $domain) {
      if (!empty($domain['contact_id'])) {
        $default_contact = $domain['contact_id'];
        break;
      }
    }

    if (empty($default_contact)) {
      error_log("org.project60.sepa_dd: Cannot install dummy creditor - no default contact found.");
    } else {
      error_log("org.project60.sepa_dd: Inserting dummy creditor into database.");
      // remark: we're within the enable hook, so we cannot use our own API/BAOs...
      $create_creditor_sql = "
      INSERT INTO civicrm_sdd_creditor 
      (`creditor_id`,    `identifier`,      `name`,           `address`,                   `country_id`, `iban`,                   `bic`,      `mandate_prefix`, `mandate_active`, `sepa_file_format_id`, `category`)
      VALUES
      ($default_contact, 'TESTCREDITORDE', 'TEST CREDITOR', '221B Baker Street\nLondon', '1226',       'DE12500105170648489890', 'SEPATEST', 'TEST',           1,                1, 'TEST');";
      CRM_Core_DAO::executeQuery($create_creditor_sql);
    }
  }
  
  return _sepa_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function sepa_civicrm_disable() {
  sepa_pp_disable();
  return _sepa_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function sepa_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sepa_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function sepa_civicrm_managed(&$entities) {
  return _sepa_civix_civicrm_managed($entities);
}


function sepa_civicrm_summaryActions( &$actions, $contactID ) {
  // add "create SEPA mandate action"
  $actions['sepa_contribution'] = array(
      'title'           => ts("Record SEPA Contribution"),
      'weight'          => 5,
      'ref'             => 'new-sepa-contribution',
      'key'             => 'sepa_contribution',
      'component'       => 'CiviContribute',
      'href'            => CRM_Utils_System::url('civicrm/sepa/cmandate', "cid=$contactID"),
      'permissions'     => array('access CiviContribute', 'edit contributions')
    );
}


/**
 *  Support SEPA mandates in merge operations
 */
function sepa_civicrm_merge ( $type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL ) {
   switch ($type) {
    case 'relTables':
      // Offer user to merge SEPA Mandates
      $data['rel_table_sepamandate'] = array(
          'title'  => ts('SEPA Mandates'),
          'tables' => array('civicrm_sdd_mandate'),
          'url'    => CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=$cid&selectedChild=contribute'),  // '$cid' will be automatically replaced
      );
    break;

    case 'cidRefs':
      // this is the only field that needs to be modified
        $data['civicrm_sdd_mandate'] = array('contact_id');
    break;
  }
}

/** 
 * PREVENT the user to delete a (recurring) contribution when there's a mandate attached.
 */
function sepa_civicrm_pre($op, $objectName, $id, &$params) {
  // FIXME: move this into validation?
  // disallow the deletion of a (recurring) contribution if it is attached to mandates
  if ($op=='delete' && ($objectName=='Contribution' || $objectName=='ContributionRecur')) {
    if ($objectName=='Contribution') {
      $table = 'civicrm_contribution';
    } else {
      $table = 'civicrm_contribution_recur';
    }

    $query = "SELECT id FROM civicrm_sdd_mandate WHERE entity_id=$id AND entity_table='$table';";
    $result = CRM_Core_DAO::executeQuery($query);
    if ($result->fetch()) {
      die(sprintf(ts("You cannot delete this contribution because it is connected to SEPA mandate [%s]. Delete the mandate instead!"), $result->id));
    }
  }
}


// totten's addition
function sepa_civicrm_entityTypes(&$entityTypes) {
  // add my DAO's
  $entityTypes[] = array(
      'name' => 'SepaMandate',
      'class' => 'CRM_Sepa_DAO_SEPAMandate',
      'table' => 'civicrm_sepa_mandate',
  );
  $entityTypes[] = array(
      'name' => 'SepaCreditor',
      'class' => 'CRM_Sepa_DAO_SEPACreditor',
      'table' => 'civicrm_sepa_creditor',
  );
  $entityTypes[] = array(
      'name' => 'SepaTransactionGroup',
      'class' => 'CRM_Sepa_BAO_SEPATransactionGroup',
      'table' => 'civicrm_sepa_txgroup',
  );
  $entityTypes[] = array(
      'name' => 'SepaSddFile',
      'class' => 'CRM_Sepa_DAO_SEPASddFile',
      'table' => 'civicrm_sepa_file',
  );
  $entityTypes[] = array(
      'name' => 'SepaContributionGroup',
      'class' => 'CRM_Sepa_DAO_SEPAContributionGroup',
      'table' => 'civicrm_sepa_contribution_txgroup',
  );
}

/**
* Implementation of hook_civicrm_config
*/
function sepa_civicrm_alterSettingsFolders(&$metaDataFolders = NULL){
  _sepa_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
* Implementation of hook_civicrm_navigationMenu
*/
function sepa_civicrm_navigationMenu(&$params) {
  $sepa_dashboard_url = 'civicrm/sepa';
  // see if it is already in the menu...
  $menu_item_search = array('url' => $sepa_dashboard_url);
  $menu_items = array();
  CRM_Core_BAO_Navigation::retrieve($menu_item_search, $menu_items);

  if (empty($menu_items)) {
    // it's not already contained, so we want to add it to the menu
    
    // now, by default we want to add it to the Contributions menu -> find it
    $contributions_menu_id = 0;
    foreach ($params as $key => $value) {
      if ($value['attributes']['name'] == 'Contributions') {
        $contributions_menu_id = $key;
        break;
      }
    }

    if (empty($contributions_menu_id)) {
      error_log("org.project60.sepa_dd: Connot find 'Contributions' menu item.");
    } else {
      // insert at the bottom
      $params[$contributions_menu_id]['child'][] = array(
          'attributes' => array (
          'label' => ts('CiviSEPA Dashboard',array('domain' => 'org.project60.sepa')),
          'name' => 'Dashboard',
          'url' => 'civicrm/sepa',
          //@cray146: modify permission to access CiviSEPA Dashboard
          //'permission' => 'administer CiviCRM',
          'permission' => 'access CiviContribute, edit contributions',
          'operator' => NULL,
          'separator' => 2,
          'parentID' => $contributions_menu_id,
          'navID' => CRM_Utils_SepaMenuTools::createUniqueNavID($params),
          'active' => 1
        ));
    }
  }
}

/**
 * Set permission to the API calls
 */
function sepa_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // TODO: add more
  $permissions['sepa_alternative_batching']['received'] = array('edit contributions');
  $permissions['sepa_logic']['received'] = array('edit contributions');
  $permissions['sepa_transaction_group']['toaccgroup'] = array('edit contributions');
}


/**
 * CiviCRM validateForm hook
 *
 * make sure, people don't create (broken) payment with SDD payment instrument, but w/o mandates
 */
function sepa_civicrm_validateForm( $formName, &$fields, &$files, &$form, &$errors ) {
  if ($formName == 'CRM_Contribute_Form_Contribution') {
    // we'll just focus on the payment_instrument_id 
    if (empty($fields['payment_instrument_id'])) return;

    // find the contribution id
    $contribution_id = $form->getVar('_id');
    if (empty($contribution_id)) return;

    // find the attached mandate, if exists
    $mandates = CRM_Sepa_Logic_Settings::getMandateFor($contribution_id);
    if (empty($mandates)) {
      // the contribution has no mandate, 
      //   so we should not allow the payment_instrument be set to an SDD one
      if (CRM_Sepa_Logic_Settings::isSDD(array('payment_instrument_id' => $fields['payment_instrument_id']))) {
        $errors['payment_instrument_id'] = ts("This contribution has no mandate and cannot simply be changed to a SEPA payment instrument.");
      }

    } else {
      // the contribution has a mandate which determines the payment instrument

      // ..but first some sanity checks...
      if (count($mandates) != 1) {
        error_log("org.project60.sepa_dd: contribution [$contribution_id] has more than one mandate.");
      }

      // now compare requested with expected payment instrument
      $mandate_id = key($mandates);
      $mandate_pi = $mandates[$mandate_id];
      $requested_pi = CRM_Core_OptionGroup::getValue('payment_instrument', $fields['payment_instrument_id'], 'value', 'String', 'name');

      if ($requested_pi != $mandate_pi) {
        $errors['payment_instrument_id'] = sprintf(ts("This contribution has a mandate, its payment instrument has to be '%s'"), $mandate_pi);
      }
    }
  }
}

/**
 * @cray146
 *
 * Implements hook_civicrm_links
 *
 * Add link Record SDD Payment to membership links to allow recording Membership
 * Payments via SDD. If there is already a recurring contribution record
 * connected to the membership add link View SDD Payment instead.
 */
function sepa_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  if ($op == "membership.tab.row" and $objectName == "Membership") {
    $membership_params = array(
      'version' => 3,
      'id' => $objectId
    );
    $membership = civicrm_api('Membership', 'get', $membership_params);
    if ($membership['is_error'] == 0) {
      $contactId = $membership['values'][$objectId]['contact_id'];
      $actionLink = 'record';
      if ($membership['values'][$objectId]['contribution_recur_id'] > 0) {
        $contributionRecurId = $membership['values'][$objectId]['contribution_recur_id'];
        // Check if the recurring contribution is not completed or cancelled
        $contribution_recur_params = array(
          'version' => 3,
          'id' => $contributionRecurId
        );
        $contributionRecur = civicrm_api('ContributionRecur', 'get', $contribution_recur_params);
        if ($contributionRecur['is_error'] == 0) {
          // Contribution Status options
          // 1  Completed
          // 2  Pending
          // 3  Cancelled
          // 4  Failed
          // 5  In Progress
          // 6  Overdue
          // 7  Refunded
          // 8  Partially paid
          // 9  Pending refund
          if ($contributionRecur['values'][$contributionRecurId]['contribution_status_id'] != 1 and $contributionRecur['values'][$contributionRecurId]['contribution_status_id'] != 3) {
            $actionLink = 'view';
          } else {
            // If the mandate is cancelled or deleted, we must remove the
            // reference to the recurring contribution in the membership record
            // (contribution_recur_id) in order to reset the autorenew flag. The
            // membership status cannot be overridden as long as the autorenew
            // flag is set. This prevents the administrator to cancel the
            // membership.
            $sql = "UPDATE civicrm_membership SET contribution_recur_id = NULL where id = {$objectId}";
            CRM_Core_DAO::singleValueQuery($sql);
          }
        }
      }
      if ($actionLink == 'view') {
        array_unshift(
          $links,
          array(
            'name' => ts("View SDD Payment"),
            'url' => "/civicrm/contact/view/contributionrecur",
            'qs' => "reset=1&id=%%contributionRecurId%%&cid=%%contactId%%",
            'title' => ts("View SDD Membership Payment")
          )
        );
        $values['contactId'] = $contactId;
        $values['contributionRecurId'] = $contributionRecurId;
      } else {
        array_unshift(
          $links,
          array(
            'name' => ts("Record SDD Payment"),
            'url' => '/civicrm/sepa/cmandate',
            'qs' => 'cid=%%contactId%%&mid=%%membershipId%%&financial_type_id=2',
            'title' => ts("Record SDD Membership Payment")
          )
        );
        $values['contactId'] = $contactId;
        $values['membershipId'] = $objectId;
      }
    }
  }
}
