<?php
/**
 * Created by PhpStorm.
 * User: rwaltz
 * Date: 1/23/18
 * Time: 1:36 PM
 */
class IslandoraScanBatchDigitalCommonsBase extends IslandoraScanBatch
{

    // Change to FALSE if one wants to take control over hierarchical structures.
    // @todo Make zip scan respect this.
    public $recursiveScan = TRUE;
    protected $collection_item_namespace;
    private $collection_policy_xpath_str = '/islandora:collection_policy/islandora:content_models/islandora:content_model/@pid';
    private $MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS = 20;
    private $object_model_cache;

    /**
     * Constructor must be able to receive an associative array of parameters.
     *
     * @param array $parameters
     *   An associative array of parameters for the batch process. These will
     *   probably just be the result of a simple transformation from the
     *   command line, or something which could have been constructed from a
     *   form.
     *   Available parameters are from the particular concrete implementation.
     */
    public function __construct( $connection,  $object_model_cache, $parameters)
    {
        parent::__construct($connection, $parameters);
        // $this->root_pid = variable_get('islandora_repository_pid', 'islandora:root');
        $this->repository = $this->connection->repository;
        $this->object_model_cache = $object_model_cache;
        $this->collection_item_namespace = $parameters['namespace'];
    }

    /**
     * Get a listing of "file-object"-like entries.
     *
     * @return array
     *   An associative array of stdClass objects representing files. Array keys
     *   are URIs relative to the "target", and the objects properties include:
     *   - uri: A string containing the complete URI of the resource.
     *   - filename: The filename.
     *   - name: The filename without its extension.
     */
    protected function scan()
    {
        $method = "scan" . ucfirst($this->getTargetType());
        return $this->$method($this->getTarget() . "/" . $this->getDigitalCommonsSeriesName());
    }

    /**
     * Get the fullpath to the target directory in which a collection resides.
     */
    protected function getTarget()
    {
        return $this->parameters['target'];
    }

    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getCollectionPID()
    {
        return $this->parameters['collection_pid'];
    }


    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getCollectionName()
    {
        return $this->parameters['collection_name'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getDigitalCommonsSeriesName()
    {
        return $this->parameters['digital_commons_series_name'];
    }
    /**
     * Get the target collection namespace.
     *
     */
    protected function getCollectionNamespace()
    {
        return $this->parameters['collection_namespace'];
    }

    /**
     * Get the type of the target resource.
     *
     * Prefixed with "scan_" to determine which method gets called to generate
     * the list of resource.
     */
    protected function getTargetType()
    {
        return $this->parameters['type'];
    }

    /**
     * Allow the pattern to be set differently.
     */
    protected static function getPattern()
    {
        return '/.*/';
    }

    /**
     * Scan the directory with file_scan_directory().
     */
    protected function scanDirectory($target)
    {

        $fileStorage = new SplObjectStorage();
        $target_path = drupal_realpath($target);
        $directory_contents = file_scan_directory(
            $target_path, $this->getPattern(), array('recurse' => $this->recursiveScan)
        );
        foreach ($directory_contents as $uri => $value) {
            $fileStorage->attach($value);
        }
        return $fileStorage;
    }

    /**
     * Generate output analogous to file_scan_directory().
     */
    protected function scanZip($target)
    {
        throw Exception("scanZip Does no work with islandora_scan_batch_digital_commons");
    }

    /**
     * Group file entries logically, to pass off to the import object.
     *
     * The data structure will organize objects into collections.
     * [collection_name_key][object_name_key] = object_info
     * The object_info is an object with the following properties:
     * objectId (the key and potential object identifier for the object)
     * namespace (the namespace to be assigned for the pid of the object)
     * collection (the pid of the collection object)
     * collection_relationship_pred
     * collection_relationship_uri
     * fileArray The files that will eventually turn into datastreams.
     *
     *
     * @param array $files
     *   An array, as returned by file_scan_directory().
     *
     */
    function groupFiles(SplObjectStorage $fileStorage)
    {
        $grouped = array();
        $digitalCommonsMetadataFileObject = null;
        $digitalCommonsMetadataObjectInfo = null;

        $previous_count = $fileStorage->count() + 1;
        $iterations = 0;
        $iteration_limit = 10;
        // turns out we need a file we know will be at the same level as an object id, metadata.xml
        // should reside in the object directory
        // with all parent directories becoming the DigitalCommonsObjectId
        while ($fileStorage->count() > 0) {
            $fileStorage->rewind();
            // incase something gets stuck and we are not able to detach all the files, this is the release valve
            $digitalCommonsMetadata = null;
            if ($iterations > $iteration_limit) {
                break;
            }

            foreach ($fileStorage as $storedFile) {

                if ($storedFile->filename == "metadata.xml") {
                    $digitalCommonsMetadata = $storedFile;

                    break;
                }
            }

            if (isset($digitalCommonsMetadata)) {
                $digitalCommonsMetadataFileObject = $this->buildFileObject($digitalCommonsMetadata);
                if (isset($digitalCommonsMetadataFileObject)) {
                    $digitalCommonsMetadataObjectInfo = $this->buildObjectInfo($digitalCommonsMetadataFileObject);
                }
                $grouped[$digitalCommonsMetadataFileObject->getDigitalCommonsObjectId()] = $digitalCommonsMetadataObjectInfo;
                $fileStorage->detach($digitalCommonsMetadata);
            } else {
                $debug = var_export($fileStorage, true);

                throw new Exception("File metadata.xml could not be found\n{$debug}\n"  );
            }

            $fileStorage->rewind();

            $file_storage_count =  $fileStorage->count();
            for ($i = 0; $i < $file_storage_count; ++$i){
                // foreach ($fileStorage as $storedFile) {
                $storedFile = $fileStorage->current();

                $file_object = $this->buildFileObject($storedFile, $digitalCommonsMetadataObjectInfo);
                if (isset($file_object) && isset($grouped[$file_object->getDigitalCommonsObjectId()])) {
                    $grouped[$file_object->getDigitalCommonsObjectId()]->addFileArray($file_object);

                    $fileStorage->detach($storedFile);
                } else {
                    $fileStorage->next();
                }

            }
            if ($fileStorage->count() < $previous_count) {
                $previous_count = $fileStorage->count();
                $iterations = 0;
            } else {
                ++$iterations;
            }
        }
        return $grouped;
    }

    protected function buildObjectInfo($file_object)
    {
        $object_info = new DigitalCommonsObjectInfo();
        // The collection PID to which the object belongs, is
        // passed in as a parameter to IslandoraScanBatchDigitalCommons
        $object_info->setCollection($this->getCollectionPID());
        $object_info->setDigitalCommonsSeries($this->getDigitalCommonsSeriesName());
        $object_info->setArchiveTopLevelDirectoryFullPath($this->getTarget());
        if (isset($this->parameters['collection_relationship_pred'])) {
            $object_info->setCollectionRelationshipPred($this->parameters['collection_relationship_pred']);
        }
        if (isset($this->parameters['collection_relationship_uri'])) {
            $object_info->setCollectionRelationshipUri($this->parameters['collection_relationship_uri']);
        }
        $object_info->setNamespace($this->collection_item_namespace);


        $object_info->setDigitalCommonsObjectId($file_object->getDigitalCommonsObjectId());

        $fileObjectFullPath = pathinfo($file_object->getUri(), PATHINFO_DIRNAME);
        $object_info->setDigitalCommonsObjectFullPath($fileObjectFullPath);

        $object_info->addFileArray($file_object);

        return $object_info;
    }

    protected function buildFileObject($file, DigitalCommonsObjectInfo $digitalCommonsMetadataObjectInfo = null)
    {
        // counter for ascending the path towards the collection level directory
        $i = 0;
        $file_object = null;
        if (isset($digitalCommonsMetadataObjectInfo)) {
            $count = 0;
            $object_path_id = $digitalCommonsMetadataObjectInfo->getDigitalCommonsObjectFullPath();
            $regex = str_replace('/','\/', $object_path_id, $count);
            $regex .= '\/';
            $file_path = $file->uri ;

            if ($count > 0 && preg_match("/{$regex}/", $file_path)) {
                $file_object = $this->buildDigitalCommonsFileInfo($file);
                $file_object->setDigitalCommonsObjectId($digitalCommonsMetadataObjectInfo->getDigitalCommonsObjectId());

            }
        } else  {
            // Each file_object represents a Fedora DataStream
            // The collection directory of each file(DS) indicates an ObjectID in Digital Commons
            $file_object =  $this->buildDigitalCommonsFileInfo($file);
            $file_object_directory = pathinfo($file_object->getUri(), PATHINFO_DIRNAME);
            $file_object->setDigitalCommonsObjectId(pathinfo($file_object_directory, PATHINFO_FILENAME));

            //Many times the Digital Commons Series directory is the parent of the DigitalCommonsObjectId directory
            //However, on occassion the Series directory is several levels up.
            $digitalCommonsSeriesDirectory = pathinfo($file_object_directory, PATHINFO_DIRNAME);
            $digitalCommonsSeriesName = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_FILENAME);


            // The collection name may be several directories above the directory
            // representing the object id
            // The ObjectID grouping all the datastreams needs to be unique
            //  for the collection.
            //
            // Concatentate all the subdirectories together along with the
            // object id will make the object id unique.
            // For example, the name of the collection may be journalx
            // but underneath the directory journalx may be
            // vol1/iss1/1 where 1 is an object id, but the object id
            // is not yet unique for the collection journalx
            // by creating an object id of vol1.iss1.1, then we are
            // able to maintain a unique key for the identity of the
            // object located in vol1/iss1/1
            while ($digitalCommonsSeriesName !== $this->getDigitalCommonsSeriesName()) {
                $file_object->setDigitalCommonsObjectId($digitalCommonsSeriesName . "." . $file_object->getDigitalCommonsObjectId());
                // do not want to have an infinite loop attempting to build a unique object id
                if ($i >= DIGITAL_COMMONS_MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS || $digitalCommonsSeriesDirectory === "/") {
                    throw new Exception("target collection directory " . $this->getTarget() . " invalid for Digital Commons Dump");
                }
                $digitalCommonsSeriesDirectory = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_DIRNAME);
                $digitalCommonsSeriesName = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_FILENAME);
                ++$i;
            }
        }
        return $file_object;
    }

    private function buildDigitalCommonsFileInfo($file) {
        $file_object = new DigitalCommonsFileInfo();
        $file_object->setUri($file->uri);
        $file_object->setFilename($file->filename);
        $file_object->setName($file->name);
        $file_object->setExt(pathinfo($file->filename, PATHINFO_EXTENSION));
        return $file_object;
    }
    /**
     * Get the name of the class to instantiate for the batch operations.
     */
    protected static function getObjectClass()
    {
        return "IslandoraScanBatchObjectDigitalCommons";
    }

    /**
     * Perform preprocessing of the scanned resources.
     */
    public function preprocess()
    {
        $fileStorage = $this->scan();

        $added = array();

        if ($fileStorage->count() > 0) {
            $grouped = $this->groupFiles($fileStorage);

            $added = array_merge($added, $this->preprocessCollectionLevel($grouped));
        }

        return $added;
    }

    protected function preprocessCollectionLevel($collection_array)
    {
        try {
            $added = array();
            // get the collecton's content_models and add them to the parameters.
            $fedora_object = $this->connection->repository->getObject($this->getCollectionPID());
            if (!$fedora_object) {
                throw Exception($this->getCollectionPID() . " is not found. Can not proceed to ingest collection!");
            }

            $content_models = $this->getContentModelArray($this->getCollectionPID());

            foreach ($collection_array as $object_id => $object_info) {
                $object_info->setContentModels($content_models);
                $added = array_merge($added, $this->preprocessItemLevel($object_id, $object_info));
            }
        } catch (Exception $e) {
            \drupal_set_message(t('Error ingesting Islandora collection %t : %e.', array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage())), 'error');
            \watchdog('islandora_scan_batch_ditigal_commons', 'Error ingesting Islandora collection %t : %e.', array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage()), WATCHDOG_ERROR);
        }
        return $added;
    }

    protected function preprocessItemLevel($object_id, $object_info)
    {

        $object_class = static::getObjectClass();
        $added = array();
        // second level is grouped by digital commons object_name (typically 001, 002, etc)
        $ingest_object = new $object_class($this->connection, $object_id, $object_info);
        // XXX: Might be better to have this actually handled as another
        // "preprocessor", so arbitrary "tree" structures might be built?
        $added = array_merge($added, $this->preprocessChildren($ingest_object));
        return $added;
    }

    /*
     * retrieve all the content models that may be applied to this object
     * as specified by the collection_policy of the containing collection
     *
     * Each content model describes the valid Datastream IDs that may be
     * added to an object conforming to the content model.
     *
     * The associative array returned will have each content Model pid
     * as the key, and the Datastreams id as an array of values
     */
    protected function getContentModelArray($fedora_object_id)
    {
        $content_models = array();

        //$this->connection->repository->api->a->getDatastreamDissemination($this->parent->id, $this->id,null, null)
        $collectionPolicyXml = $this->connection->repository->api->a->getDatastreamDissemination($fedora_object_id, 'COLLECTION_POLICY', null, null);
        $collection_policy_dom = new DOMDocument();
        $collection_policy_dom->loadXml($collectionPolicyXml);
        $collection_policy_xpath = new DOMXPath($collection_policy_dom);

        $collection_policy_xpath->registerNamespace('islandora', "http://www.islandora.ca");

        $content_model_pid_nodes = $collection_policy_xpath->evaluate($this->collection_policy_xpath_str);

        foreach ($content_model_pid_nodes as $content_model_pid_node) {
            $content_model_pid = $content_model_pid_node->nodeValue;

            $dsids = $this->object_model_cache->getObjectModelDSIDS($content_model_pid);

            // The AUDIT Datastream can not really be added, so it can't really be
            // missing.
            unset($dsids['AUDIT']);
            $content_models[$content_model_pid] = $dsids;
        }
        return $content_models;
    }
    /**
     * Recursively attempt to preprocess children.
     */
    protected function preprocessChildren($object, $parent = NULL) {
        $to_return = array();

        // XXX: Squash exceptions and log 'em.
        try {
            $this->addToDatabase($object, $object->getResources(), $parent);
            $to_return[] = $object;

            foreach ($object->getChildren($this->connection) as $child) {
                $to_return = array_merge($to_return, $this->preprocessChildren($child, $object->id));
            }
        }
        catch (Exception $e) {
            watchdog_exception('islandora_scan_batch_digital_commons', $e);
        }

        return $to_return;
    }
}
