<?php

function indicia_api_advancedreports($reportName) {
  try {
    /*if (!indicia_api_authorise_key()) {
      error_print(401, 'Unauthorized', 'Missing or incorrect API key.');
      return;
    }*/
    switch ($reportName) {
      case 'user-stats':
        $user_wrapped = entity_metadata_wrapper('user', $GLOBALS['user']);
        $userId = $user_wrapped->field_indicia_user_id->value();
        if (!$userId) {
          error_print(401, 'Unauthorized', 'User is not signed in to the warehouse.');
          return;
        }
        if (empty($_GET['survey_id'])) {
          error_print(400, 'Bad Request', 'Parameter for survey_id missing from query string.');
          return;
        }
        require_once 'RecorderMetrics.php';
        $rm = new RecorderMetrics('es-occurrences', $_GET['survey_id'], $userId);
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