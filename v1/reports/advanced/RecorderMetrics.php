<?php

/**
 * @file
 * A helper class for obtaining recorder metrics data.
 *
 * Indicia, the OPAL Online Recording Toolkit.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://www.gnu.org/licenses/gpl.html.
 *
 * @author Indicia Team
 * @license http://www.gnu.org/licenses/gpl.html GPL 3.0
 * @link https://github.com/indicia-team/client_helpers
 */

/**
 * Exception class for aborting if error response already sent.
 */
class ApiAbort extends Exception {}

/**
 * Class to support retrieving recorder metrics data.
 *
 * Retrieves key/value pairs for info required for the /user-stats advanced
 * report.
 */
class RecorderMetrics {

  /**
   * Count of records in the filtered project.
   *
   * @var int
   */
  private $projectRecordsCount;

  /**
   * Count of species in the filtered project.
   *
   * @var int
   */
  private $projectSpeciesCount;

  /**
   * List of recorded taxon IDs for the project, with rarity value.
   *
   * @var array
   */
  private $speciesRarityData = [];

  /**
   * Median rarity value across all the project species dataset.
   *
   * @var float
   */
  private $medianOverallRarity = NULL;

  /**
   * Key/value pairs for the filter applied to define this project.
   *
   * Used for defining cache keys.
   *
   * @var array
   */
  private $projectFilter;

  /**
   * Prebuilt Elasticsearch query code for the project filter.
   *
   * @var string
   */
  private $projectQuery;

  /**
   * Constructor, stores settings.
   *
   * @param array $projectFilter
   *   Key/value pairs for the project filter to apply to the ES data, e.g. a
   *   survey ID, website ID or group ID filter.
   * @param int $userId
   *   Warehouse ID of the user to report on.
   */
  public function __construct(array $projectFilter, $userId) {
    iform_load_helpers(['helper_base', 'ElasticsearchProxyHelper']);
    $this->projectFilter = $projectFilter;
    $this->userId = $userId;
    $projectFilterTermFilterArray = [];
    foreach ($projectFilter as $field => $value) {
      $projectFilterTermFilterArray[] = <<<JSON
      {
        "term": {
          "$field": $value
        }
      }
JSON;
    }
    $projectFilterTermFilters = implode(',', $projectFilterTermFilterArray);
    $this->projectQuery = <<<JSON
    {
      "bool": {
        "must": [$projectFilterTermFilters]
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
    $speciesRatio = round(100 * $userInfo->species_count->value / $this->projectSpeciesCount, 1);
    // Activity ratio requires number of days in the recording season during
    // the period in which they'd contributed to the project.
    $firstInSeasonRecordDateArray = $this->getFirstInSeasonDateArray($userInfo->first_record_date->value_as_string);
    $lastInSeasonRecordDateArray = $this->getLastInSeasonDateArray($userInfo->last_record_date->value_as_string);
    $inSeasonRecordingDaysTotal = $this->countInSeasonDaysBetween($firstInSeasonRecordDateArray, $lastInSeasonRecordDateArray);
    // Now a simple ratio calculation, unless there were no in-season days yet.
    if ($inSeasonRecordingDaysTotal === 0) {
      $activityRatio = NULL;
    }
    else {
      $inSeasonRecordingDaysActive = $userInfo->summer_filter->summer_recording_days->value;
      $activityRatio = round(100 * $inSeasonRecordingDaysActive / $inSeasonRecordingDaysTotal, 1);
    }

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
      'projectRecordsCount' => $this->projectRecordsCount,
      'projectSpeciesCount' => $this->projectSpeciesCount,
      'myProjectRecords' => $userInfo->doc_count ?? 0,
      'myProjectSpecies' => $userInfo->species_count->value ?? 0,
      'myProjectRecordsThisYear' => $userInfo->this_year_filter->doc_count ?? 0,
      'myProjectSpeciesThisYear' => $userInfo->this_year_filter->species_count->value ?? 0,
      'myProjectSpeciesRatio' => $speciesRatio,
      'myProjectActivityRatio' => $activityRatio ?? 0,
      'myProjectRarityMetric' => $rarityMetric,
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
    $cacheKey = array_merge([
      'report' => 'RecorderMetricsSpeciesList',

    ], $this->projectFilter);
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
    $this->projectRecordsCount = $taxaResponse->hits->total;
    $this->projectSpeciesCount = count($taxaResponse->aggregations->species_list->buckets);
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
      if ($this->projectSpeciesCount === 1) {
        $thisSpeciesRarity = 50;
      }
      else {
        $thisSpeciesRarity = 1 + 99 * $i / ($this->projectSpeciesCount - 1);
      }
      $this->speciesRarityData[$speciesInfo->key] = $thisSpeciesRarity;
      // Keep a track of the records for the taxa processed so far. Once we get
      // to half of the total, we have found the median.
      $recordsFoundSoFar += $speciesInfo->doc_count;
      if ($recordsFoundSoFar > $this->projectRecordsCount / 2 && !$this->medianOverallRarity) {
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

  /**
   * Retrieve the total records for this user in the ES alias.
   *
   * E.g. the total across the website and it's shared datasets, not just the
   * project.
   *
   * @return int
   *   Record count.
   */
  private function getMyTotalRecordsCount() {
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