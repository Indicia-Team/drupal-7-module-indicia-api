<?php

function indicia_api_advancedreports($reportName) {
  drupal_add_http_header('Access-Control-Allow-Origin', "*");
  drupal_add_http_header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  drupal_add_http_header('Access-Control-Allow-Headers', 'authorization, x-api-key');
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      indicia_api_advancedreports_get($reportName);
      break;

    case 'OPTIONS':
      // No action for pre-flight OPTIONS request.
      break;

    default:
      error_print(405, 'Method Not Allowed', 'Invalid request method');
  }
}

/**
 * Handles the construction of report output on a GET request.
 */
function indicia_api_advancedreports_get($reportName) {
  try {
    if (!indicia_api_authorise_key()) {
      error_print(401, 'Unauthorized', 'Missing or incorrect API key.');
      return FALSE;
    }
    if (!indicia_api_authorise_user()) {
      error_print(401, 'Unauthorized', 'Could not find/authenticate user.');
      return FALSE;
    }
    switch ($reportName) {
      case 'user-stats':
        $user_wrapped = entity_metadata_wrapper('user', $GLOBALS['user']);
        $userId = $user_wrapped->field_indicia_user_id->value();
        $projectFilters = [];
        if (!$userId) {
          error_print(401, 'Unauthorized', 'User is not signed in to the warehouse.');
          return;
        }
        if (!empty($_GET['survey_id'])) {
          $projectFilters = ['metadata.survey.id' => $_GET['survey_id']];
        }
        elseif (!empty($_GET['group_id'])) {
          $projectFilters = ['metadata.group.id' => $_GET['group_id']];
        }
        else {
          // @todo Add support for website_id filter, but need to check error
          // handling if species list huge.
          error_print(400, 'Bad Request', 'Parameter for survey_id or group_id missing from query string.');
          return;
        }
        require_once 'RecorderMetrics.php';
        $rm = new RecorderMetrics($projectFilters, $userId);
        header('Content-Type: application/json');
        echo json_encode($rm->getUserMetrics());
        break;

      default:
        error_print(400, 'Bad Request', 'Unknown advanced report requested.');
        return;
    }
  }
  catch (ApiAbort $e) {
    // Do nothing.
  }
}
