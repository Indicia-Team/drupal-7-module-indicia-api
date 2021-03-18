<?php

class ApiAbort extends Exception {}

class RecorderMetrics {

  private $totalRecordsCount;

  private $totalSpeciesCount;

  private $speciesRarityData = [];

  private $medianOverallRarity = NULL;

  private $surveyId;

  /**
   * Name of the warehouse REST API endpoint for Elasticsearch access.
   *
   * @var string
   */
  private $esEndpoint;

  private $projectQuery;

  /**
   * Constructor, stores settings.
   *
   * @param string $esEndpoint
   *   Name of the warehouse REST API endpoint for Elasticsearch access.
   * @param int $surveyId
   *   ID of the survey to report on.
   * @param int $userId
   *   Warehouse ID of the user to report on.
   */
  public function __construct($esEndpoint, $surveyId, $userId) {
    iform_load_helpers(['helper_base', 'ElasticsearchProxyHelper']);
    $this->esEndpoint = $esEndpoint;
    $this->surveyId = $surveyId;
    $this->userId = $userId;
    $this->projectQuery = <<<JSON
    {
      "bool": {
        "must": [{
          "term": {
            "metadata.survey.id": $surveyId
          }
        }]
      }
    }
JSON;
  }

  /**
   * Retrieves the recording metrics for the current user.
   *
   * @return array
   *   Array keyed by user ID, where the values are objects holding the
   *   recording metrics.
   */
  public function getUserMetrics() {
    $this->getSpeciesWithRarity();
    $userRecordingData = $this->getUserRecordingData();
    $userInfo = $userRecordingData->aggregations->user_limit->by_user->buckets[0];
    // Species ratio is a simple calculation.
    $speciesRatio = round(100 * $userInfo->species_count->value / $this->totalSpeciesCount, 1);
    // Activity ratio requires number of days in the recording season during
    // the period in which they'd contributed to the project.
    $firstInSeasonRecordDateArray = $this->getFirstInSeasonDateArray($userInfo->first_record_date->value_as_string);
    $lastInSeasonRecordDateArray = $this->getLastInSeasonDateArray($userInfo->last_record_date->value_as_string);
    $inSeasonRecordingDaysTotal = $this->countInSeasonDaysBetween($firstInSeasonRecordDateArray, $lastInSeasonRecordDateArray);
    // Now a simple ratio calculation.
    $inSeasonRecordingDaysActive = $userInfo->summer_filter->summer_recording_days->value;
    $activityRatio = round(100 * $inSeasonRecordingDaysActive / $inSeasonRecordingDaysTotal, 1);

    // Now look through the user's species list to work out median rarity.
    $recordsFoundSoFar = 0;
    $medianUserRarity = NULL;
    $userSpeciesCountData = [];
    // Get a simple list of the user's taxa counts.
    foreach ($userInfo->species_list->buckets as $i => $speciesInfo) {
      $userSpeciesCountData[$speciesInfo->key] = $speciesInfo->doc_count;
    }
    // Work through the list of the user's taxa but in overall rarity order,
    // so we can find the median.
    foreach ($this->speciesRarityData as $taxonID => $speciesRarityValue) {
      if (isset($userSpeciesCountData[$taxonID])) {
        $recordsFoundSoFar += $userSpeciesCountData[$taxonID];
      }
      if ($recordsFoundSoFar > $userInfo->doc_count / 2) {
        $medianUserRarity = $speciesRarityValue;
        break;
      }
    }
    $rarityMetric = round($medianUserRarity - $this->medianOverallRarity, 1);
    return [
      'myTotalRecords' => $this->getMyTotalRecordsCount(),
      'surveyRecords' => $this->totalRecordsCount,
      'surveySpecies' => $this->totalSpeciesCount,
      'mySurveyRecords' => $userInfo->doc_count,
      'mySurveySpecies' => $userInfo->species_count->value,
      'mySurveyRecordsThisYear' => $userInfo->this_year_filter->doc_count,
      'mySurveySpeciesThisYear' => $userInfo->this_year_filter->species_count->value,
      'speciesRatio' => $speciesRatio,
      'activityRatio' => $activityRatio,
      'rarityMetric' => $rarityMetric,
    ];
  }

  /**
   * Calculates the date array for a first record date string.
   *
   * If not in summer, then winds the date forward to the start of the next
   * summer.
   *
   * @param string $dateString
   *   Date as an ISO string.
   */
  private function getFirstInSeasonDateArray($dateString) {
    $firstRecordDateArray = getdate(strtotime($dateString));
    // Align out of season date to the recording season.
    if ($firstRecordDateArray['mon'] < 6 || $firstRecordDateArray['mon'] > 8) {
      // If after summer, move to next year.
      if ($firstRecordDateArray['mon'] > 8) {
        $firstRecordDateArray['year']++;
      }
      // Move out of season date to start of summer.
      $firstRecordDateArray['mday'] = 1;
      $firstRecordDateArray['mon'] = 6;
    }
    return $firstRecordDateArray;
  }

  /**
   * Calculates the date array for a last record date string.
   *
   * If not in summer, then winds the date back to the end of the last summer.
   *
   * @param string $dateString
   *   Date as an ISO string.
   */
  private function getLastInSeasonDateArray($dateString) {
    $lastRecordDateArray = getdate(strtotime($dateString));
    // Align out of season date to the recording season.
    if ($lastRecordDateArray['mon'] < 6 || $lastRecordDateArray['mon'] > 8) {
      // If before summer, move to previous year.
      if ($lastRecordDateArray['mon'] < 6) {
        $lastRecordDateArray['year']--;
      }
      // Move out of season date to end of summer.
      $lastRecordDateArray['mday'] = 31;
      $lastRecordDateArray['mon'] = 8;
    }
    return $lastRecordDateArray;
  }

  /**
   * Find the number of in-season days between 2 date arrays.
   *
   * @param array $firstInSeasonRecordDateArray
   *   Date array containing the start of the date range.
   * @param array $lastInSeasonRecordDateArray
   *   Date array containing the end of the date range.
   *
   * @return int
   *   Count of in-season days.
   */
  private function countInSeasonDaysBetween(array $firstInSeasonRecordDateArray, array $lastInSeasonRecordDateArray) {
    // Total number of season days in the years the user has been recording.
    $inSeasonRecordingDaysTotal = 92 * ($lastInSeasonRecordDateArray['year'] - $firstInSeasonRecordDateArray['year'] + 1);
    // If start date not 1st June, subtract the days the recorder missed.
    $startOfSummer = new DateTime("$firstInSeasonRecordDateArray[year]-06-01");
    $firstRecordDate = new DateTime("$firstInSeasonRecordDateArray[year]-$firstInSeasonRecordDateArray[mon]-$firstInSeasonRecordDateArray[mday]");
    $inSeasonRecordingDaysTotal -= $startOfSummer->diff($firstRecordDate, TRUE)->days;
    // If end date not 31st August, subtract the days the recorder missed.
    $endOfSummer = new DateTime("$lastInSeasonRecordDateArray[year]-08-31");
    $lastRecordDate = new DateTime("$lastInSeasonRecordDateArray[year]-$lastInSeasonRecordDateArray[mon]-$lastInSeasonRecordDateArray[mday]");
    $inSeasonRecordingDaysTotal -= $endOfSummer->diff($lastRecordDate, TRUE)->days;
    return $inSeasonRecordingDaysTotal;
  }

  /**
   * Gets the Elasticsearch response for a request string.
   *
   * @param string $request
   *   Request string (JSON) for the _search endpoint.
   *
   * @return object
   *   Response object (decoded from JSON).
   */
  private function getEsResponse($request) {
    $config = hostsite_get_es_config(NULL);
    $warehouseUrl = $config['indicia']['base_url'];
    $esEndpoint = $config['es']['endpoint'];
    $url = "{$warehouseUrl}index.php/services/rest/$esEndpoint/_search";
    $session = curl_init();
    // Set the POST options.
    curl_setopt($session, CURLOPT_URL, $url);
    curl_setopt($session, CURLOPT_HEADER, FALSE);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($session, CURLOPT_HTTPHEADER, ElasticsearchProxyHelper::getHttpRequestHeaders($config));
    curl_setopt($session, CURLOPT_POST, 1);
    curl_setopt($session, CURLOPT_POSTFIELDS, $request);
    // Do the request.
    $response = curl_exec($session);
    $httpCode = curl_getinfo($session, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($session);
    // Check for an error, or check if the http response was not OK.
    if ($curlErrno || $httpCode != 200) {
      $errorInfo = json_decode($response);
      if ($errorInfo && $errorInfo->status) {
        // If a handled server error, we can set a proper response error.
        error_print($httpCode, $errorInfo->status, $errorInfo->message);
      }
      else {
        // If we can't do it properly, still best not to swallow it.
        error_print(500, 'Internal Server Error', $response);
      }
      throw new ApiAbort();
    }
    return json_decode($response);
  }

  /**
   * Request list of taxa from Elasticsearch, ordered by document count.
   *
   * @return array
   *   List of ES bucket objects, containing a key (taxonID) and doc_count.
   */
  private function getSpeciesList() {
    // This data can be cached as rate of change will be slow.
    $cacheKey = [
      'report' => 'RecorderMetricsSpeciesList',
      'surveyId' => $this->surveyId,
    ];
    $taxaResponse = helper_base::cache_get($cacheKey);
    if ($taxaResponse === FALSE) {
      // Get a list of all taxa recorded in project, ordered by document count.
      $request = <<<JSON
        {
          "size": "0",
          "query": $this->projectQuery,
          "aggs": {
            "species_list": {
              "terms": {
                "field": "taxon.species_taxon_id",
                "size": 10000
              }
            }
          }
        }
 JSON;
      $taxaResponse = $this->getEsResponse($request);
      helper_base::cache_set($cacheKey, json_encode($taxaResponse));
    }
    else {
      $taxaResponse = json_decode($taxaResponse);
    }
    $this->totalRecordsCount = $taxaResponse->hits->total;
    $this->totalSpeciesCount = count($taxaResponse->aggregations->species_list->buckets);
    return $taxaResponse->aggregations->species_list->buckets;
  }

  /**
   * Builds a list of all taxa recorded in the project with their rarity score.
   *
   * Also calculates the medianOverallRarity.
   */
  private function getSpeciesWithRarity() {
    $speciesList = $this->getSpeciesList();
    $recordsFoundSoFar = 0;
    // Work through the list of taxa from commonest to rarest, assigning a
    // rarity value between 1 and 100.
    foreach ($speciesList as $i => $speciesInfo) {
      $thisSpeciesRarity = 1 + 99 * $i / ($this->totalSpeciesCount - 1);
      $this->speciesRarityData[$speciesInfo->key] = $thisSpeciesRarity;
      // Keep a track of the records for the taxa processed so far. Once we get
      // to half of the total, we have found the median.
      $recordsFoundSoFar += $speciesInfo->doc_count;
      if ($recordsFoundSoFar > $this->totalRecordsCount / 2 && !$this->medianOverallRarity) {
        $this->medianOverallRarity = $thisSpeciesRarity;
      }
    }
  }

  /**
   * Uses an ES aggregation to find data required to build a user's metrics.
   */
  private function getUserRecordingData() {
    $year = date("Y");
    // Collect data about the user's records.
    $request = <<<JSON
      {
        "size": "0",
        "query": $this->projectQuery,
        "aggs": {
          "total_species": {
            "cardinality": {
              "field": "taxon.species_taxon_id"
            }
          },
          "user_limit": {
            "filter": {
              "term": {
                "metadata.created_by_id": $this->userId
              }
            },
            "aggs": {
              "by_user": {
                "terms": {
                  "field": "metadata.created_by_id"
                },
                "aggs": {
                  "species_list": {
                    "terms": {
                      "field": "taxon.species_taxon_id",
                      "size": 10000
                    }
                  },
                  "species_count": {
                    "cardinality": {
                      "field": "taxon.species_taxon_id"
                    }
                  },
                  "first_record_date": {
                    "min": {
                      "field": "event.date_start"
                    }
                  },
                  "last_record_date": {
                    "max": {
                      "field": "event.date_start"
                    }
                  },
                  "this_year_filter": {
                    "filter": {
                      "term": {
                        "event.year": $year
                      }
                    },
                    "aggs": {
                      "species_count": {
                        "cardinality": {
                          "field": "taxon.species_taxon_id"
                        }
                      }
                    }
                  },
                  "summer_filter": {
                    "filter": {
                      "range": {
                        "event.month": {
                          "gte": 6,
                          "lt": 9
                        }
                      }
                    },
                    "aggs": {
                      "summer_recording_days": {
                        "cardinality": {
                          "field": "event.date_start"
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
JSON;
    return $this->getEsResponse($request);
  }

  function getMyTotalRecordsCount() {
    $request = <<<JSON
      {
        "size": "0",
        "query": {
          "bool": {
            "must": [{
              "term": {
                "metadata.created_by_id": $this->userId
              }
            }]
          }
        }
      }
JSON;
    $response = $this->getEsResponse($request);
    return $response->hits->total;

  }

}
