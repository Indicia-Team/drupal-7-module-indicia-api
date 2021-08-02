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
    header('Content-Type: application/json');
    if (!indicia_api_authorise_key()) {
      error_print(401, 'Unauthorized', 'Missing or incorrect API key.');
      return FALSE;
    }
    if (!indicia_api_authorise_user()) {
      error_print(401, 'Unauthorized', 'Could not find/authenticate user.');
      return FALSE;
    }

    $user_wrapped = entity_metadata_wrapper('user', $GLOBALS['user']);
    $userId = $user_wrapped->field_indicia_user_id->value();
    $filters = [];
    if (!$userId) {
      error_print(401, 'Unauthorized', 'User is not signed in to the warehouse.');
      return;
    }
    if (!empty($_GET['survey_id'])) {
      $filters = ['metadata.survey.id' => $_GET['survey_id']];
    }
    elseif (!empty($_GET['group_id'])) {
      $filters = ['metadata.group.id' => $_GET['group_id']];
    }
    else {
      // @todo Add support for website_id filter, but need to check error
      // handling if species list huge.
      error_print(400, 'Bad Request', 'Parameter for survey_id or group_id missing from query string.');
      return;
    }
    // Optional taxon group filter.
    if (!empty($_GET['taxon_group_id'])) {
      $filters = ['taxon.group_id' => $_GET['taxon_group_id']];
    }
    // Some end-points can return data for any chosen year.
    if (!empty($_GET['year']) && $reportName !== 'user-stats') {
      $filters['event.year'] = $_GET['year'];
    }
    // Some have optional user filter.
    if (!empty($_GET['user_id']) && $reportName !== 'user-stats') {
      // Can only request your own data.
      if ($_GET['user_id'] !== $userId) {
        error_print(401, 'Unauthorized', "Cannot request other user's data.");
        return;
      }
      $filters['metadata.created_by_id'] = $userId;
    }
    require_once 'RecorderMetrics.php';
    $rm = new RecorderMetrics($userId);
    switch ($reportName) {
      case 'user-stats':
        echo json_encode($rm->getUserMetrics($filters));
        break;

      case 'counts':
        // Count categories defaults to just records.
        if (!isset($_GET['categories'])) {
          $categories = ['records'];
        }
        else {
          $categories = explode(',', $_GET['categories']);
          foreach ($categories as $category) {
            if (!in_array($category, ['records', 'species', 'photos', 'recorders'])) {
              error_print(400, 'Bad Request', "Parameter for categories contains invalid value $category.");
              return;
            }
          }
        }
        echo json_encode($rm->getCounts($filters, $categories));
        break;

      case 'recorded-taxa-list':
        // @deprecated exclude_higher_taxa parameter - use species_only instead.
        $excludeHigherTaxa = isset($_GET['exclude_higher_taxa']) && $_GET['exclude_higher_taxa'] === 't' ? TRUE : FALSE;
        $speciesOnly = isset($_GET['species_only']) && $_GET['species_only'] === 't' ? TRUE : FALSE;
        echo json_encode($rm->getRecordedTaxaList($filters, $excludeHigherTaxa || $speciesOnly));
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
