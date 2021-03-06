<?php

namespace SintraPimcoreBundle\Controller\Sync;

use Pimcore\Cache;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\TargetServer;
use SintraPimcoreBundle\Services\InterfaceService;
use Pimcore\Logger;
use Pimcore\Db;
use SintraPimcoreBundle\Utils\SynchronizationUtils;

/**
 * Manage synchronization of objects
 *
 * @author Sintra Consulting
 */
class BaseSyncController {

    /**
     * Get the list of enabled TargetServer
     *
     * @return TargetServer\Listing
     */
    public function getEnabledServers() {
        $servers = new TargetServer\Listing();
        $servers->addConditionParam('enabled', true);

        return $servers;
    }

    /**
     * Dispatch syncronization invoking the server related syncronization service.
     * Firstly get a batch of objects that need to be synchronized
     * and perform the synchronization for each of them.
     *
     * @param TargetServer $server the server in which the objects must me synchronized
     * @param String $class the class of the objects
     * @param int $limit number of objects to synchronize
     * @param array $customFilters timing informations for execution. it override limit if present
     * @throws \ReflectionException
     * @return mixed
     */
    public function syncServerObjects($server, $class, $limit = 10, $customFilters = []) {

        $dataObjectService = SynchronizationUtils::getSynchronizationService($server, $class);

        $syncController = SynchronizationUtils::getServerSynchronizationController($server);
        # No custom module enabled in SintraPimcoreBundleConfiguration.
        if ($syncController === null) {
            # Default this controller
            $syncController = $this;
        }

        /** @var array $dataObjects */
        $dataObjects = $syncController->getServerToSyncObjects($server, $class, $limit, $customFilters);

        if ($dataObjects != null && !empty($dataObjects)) {
            return $syncController->exportDataObjects($dataObjectService, $dataObjects, $server, $class, $customFilters);
        }

        Logger::info("BaseSyncController - There are no $class to sync for '" . $server->getServer_name() . "' server");
        return "BaseSyncController - There are no $class to sync for '" . $server->getServer_name() . "' server";
    }

    /**
     * Get a batch of ids of objects that need to be syncronized in a specific server
     * take in consideration all published objects of the requested class
     * that must be exported and that are completed but not synchronized in the requested server.
     *
     * @param TargetServer $server the server in which the objects must me synchronized
     * @param String $class the class of the objects
     * @param int $limit number of objects to synchronize
     * @param array $customFilters timing informations for execution. it override limit if present
     * @return array the ids of the objects to synchronize
     */
    public function getServerToSyncObjects($server, $class, $limit, $customFilters = []) {
        /**
         * dynamically get syncronization info tablename starting from class definition.
         * take the field collection type from the exportServers field allowed types.
         */
        $classDef = ClassDefinition::getByName($class);
        $fieldCollName = $classDef->getFieldDefinition('exportServers')->getAllowedTypes()[0];
        $classId = $classDef->getId();
        $fieldCollectionTable = 'object_collection_' . $fieldCollName . '_' . $classId;

        if ($customFilters['execTime'] && $customFilters['maxSyncTime'] && $customFilters['typicalSyncTime']) {
            $limit = floor(($customFilters['execTime'] - $customFilters['maxSyncTime']) / $customFilters['typicalSyncTime']);
        }

        $db = Db::get();
        $objIds = $db->fetchAll(
                "SELECT dependencies.sourceid FROM dependencies"
                . " INNER JOIN $fieldCollectionTable as srv ON (dependencies.sourceid = srv.o_id AND srv.name=? AND srv.export = 1 AND srv.complete = 1 AND (srv.sync = 0 OR srv.sync IS NULL))"
                . " INNER JOIN objects as obj ON (obj.o_id = dependencies.sourceid AND obj.o_className = ? AND obj.o_type = 'object' AND obj.o_published = 1)"
                . " WHERE dependencies.targetid = ? AND dependencies.targettype LIKE 'object' AND dependencies.sourcetype LIKE 'object'"
                . " ORDER BY dependencies.sourceid ASC"
                . " LIMIT $limit", [$server->getKey(), $class, $server->getId()]);

        $ids = [];
        foreach ($objIds as $id) {
            $ids[] = $id['sourceid'];
        }

        return $ids;
    }

    /**
     * Synchronize objects in the server.
     * At each iteration, if time limitation is active, check if there is enough
     * time to perform the synchronization of another object.
     * If not, it breaks the execution.
     *
     * Execution errors are catched and logged, so that the execution will not be break
     * if there is a problem with a single object.
     * At the end of the synchronization flow, errors are reported.
     *
     * @param InterfaceService $dataObjectService the service that will perform the synchronization.
     * @param array $dataObjects the ids of the objects to synchronize.
     * @param TargetServer $server the server in which the objects must me synchronized.
     * @param String $class the class of the objects.
     * @param array $customFilters timing informations for execution.
     * @return String the synchronization result.
     */
    protected function exportDataObjects(InterfaceService $dataObjectService, $dataObjects, $server, $class, $customFilters = []) {
        $response = array(
            "started" => date("Y-m-d H:i:s"),
            "finished" => "",
            "total elements" => 0,
            "syncronized elements" => 0,
            "elements with errors" => 0,
            "errors" => array()
        );

        $totalElements = 0;
        $syncronizedElements = 0;
        $elementsWithError = 0;
        $startTime = $this->millitime();

        $initialTime = $this->millitime();
        $hasTimeLimitation = false;
        if (isset($customFilters['execTime']) && isset($customFilters['maxSyncTime']) && isset($customFilters['typicalSyncTime'])) {
            $hasTimeLimitation = true;
        }

        foreach ($dataObjects as $productId) {

            $currTime = $this->millitime();
            $actualDuration = $currTime - $initialTime;

            # If the next product's export estimated time exceeds the maximum time
            # stop exporting products
            if ($hasTimeLimitation && ($actualDuration >= $customFilters['execTime'] * 1000 - $customFilters['maxSyncTime'] * 1000)) {
                break;
            }

            try {
                # Export the targeted product
                # In case of not sync, an error will be thrown and cached underneath
                $dataObjectService->export($productId, $server);
                $syncronizedElements++;
            } catch (\Exception $e) {
                $response["errors"][] = "OBJECT ID " . $productId . ": " . $e->getMessage();

                $this->logSynchronizationError($server->getServer_name(), $productId, $e->getMessage());
                Logger::err($e->getTraceAsString());

                $elementsWithError++;
            }

            $totalElements++;
        }
        $endTime = $this->millitime();
        try {
            Cache::clearAll();
        } catch (\Exception $e) {
            Logger::err($e->getMessage());
        }

        $response["total elements"] = $totalElements;
        $response["syncronized elements"] = $syncronizedElements;
        $response["elements with errors"] = $elementsWithError;

        return $this->logSyncedProducts($response, $server->getServer_name(), $class, $endTime - $startTime, null);
    }

    protected function logSyncedProducts($response, $servername, $class, $duration, $finished = null) {
        if (!$finished) {
            $finished = date("Y-m-d H:i:s");
        }
        $response["finished"] = $finished;

        $syncLogFile = fopen(PIMCORE_LOG_DIRECTORY . "/syncObjects.log", "a") or die("Unable to open file!");
        $syncLog = "[" . Date("Y-m-d H:i:s") . "] - " . strtoupper($class) . " $servername SYNCRONIZATION RESULT: " . print_r(['success' => $response['elements with errors'] == 0, 'responsedata' => $response, 'duration' => $duration . ' ms'], true);
        fwrite($syncLogFile, $syncLog . PHP_EOL);
        fclose($syncLogFile);

        return ("[$finished] - " . strtoupper($class) . " $servername SYNCRONIZATION RESULT: " . print_r(['success' => $response['elements with errors'] == 0, 'responsedata' => $response, 'duration' => $duration . ' ms'], true) . PHP_EOL);
    }

    protected function millitime() {
        $microtime = microtime();
        $comps = explode(' ', $microtime);

        // Note: Using a string here to prevent loss of precision
        // in case of "overflow" (PHP converts it to a double)
        return sprintf('%d%03d', $comps[1], $comps[0] * 1000);
    }

    protected function logSynchronizationError($servername, $productId, $message) {
        $db = Db::get();
                $db->insert("custom_log", array(
                    "gravity" => "LOW",
                    "class" => "BaseSyncController",
                    "action" => "exportDataObjects",
                    "flow" => "Sync to " . $servername,
                    "description" => "ERROR in exporting object with Id '$productId': " . $message,
                    "timestamp" => time()
                ));

                Logger::err($message);

    }

}
