<?php

/**
 * Created by PhpStorm.
 * User: rwaltz
 * Date: 3/1/18
 * Time: 12:32 PM
 */
class DigitalCommonsTransformBaseX
{
    protected $transform_uri = null;

    protected $basex_bepress_mods_transform_name = null;

    // this needs to be set dynamically
    protected $java_fullpath = null;

    protected $basexBepressToModsDir = null;

    public function __construct( $basex_bepress_mods_transform_name, $transform_uri = null, $java_fullpath = null) {
        $this->setBasexBepressModsTransformName($basex_bepress_mods_transform_name);
        if (is_null($java_fullpath)) {
            $this->java_fullpath = variable_get('islandora_batch_java', '/usr/bin/java');
        } else {
            $this->java_fullpath = $java_fullpath;
        }
        if (is_null($transform_uri)) {
            $this->setTransformUri('https://github.com/utkdigitalinitiatives/basex-bepress-to-mods/archive/master.zip');
        } else {
            $this->setTransformUri($transform_uri);

        }
    }

    public function getJavaExec() {
        return $this->java_fullpath;
    }

    public function setJavaExec($java_exec_fullpath) {
        return $this->java_fullpath = $java_exec_fullpath;
    }

    public function installDigitalCommonsTransformationProject() {
        $uri = $this->getTransformUri();
        $uri_schema = parse_url($uri, PHP_URL_SCHEME);
        switch ($uri_schema)
        {
            case ('file'): {
                $directory_path = parse_url($uri, PHP_URL_PATH);
                if (! file_exists($directory_path ) ) {
                    throw new ErrorException("{$directory_path} does not exist");
                }
                $this->setBasexBepressToModsDir($directory_path);

                break;
            }
            case ('http' ) :
            case ('https') :
            case ('file' ) :
                $this->installDigitalCommonsTransformationZipfile();
                break;
            default:
                throw new Exception("{$uri} has an unrecognized schema {$uri_schema}");
        }

    }

    private function installDigitalCommonsTransformationZipfile() {
        $uri = $this->getTransformUri();
        $cHandle = curl_init();
        curl_setopt($cHandle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($cHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cHandle, CURLOPT_URL, $uri );
        $zip_output = curl_exec($cHandle);
        $httpCode = curl_getinfo($cHandle, CURLINFO_HTTP_CODE);
        $zipFile = curl_close($cHandle);
        if($httpCode >= 400)
        {
            throw new ErrorException("{$uri} returned code {$httpCode}");
        }

        $module_directory = dirname(__FILE__);
        $module_directory = dirname($module_directory);
        if ( ! file_exists($module_directory) || ! is_dir($module_directory) )
        {
            throw new ErrorException("{$module_directory} either does not exist or is not a directory");
        }

        $module_scripts_directory = "{$module_directory}/scripts";
        if ( ! file_exists($module_scripts_directory) || ! is_dir($module_scripts_directory) )
        {
            throw new ErrorException("{$module_scripts_directory} either does not exist or is not a directory");
        }

        $basex_bepress_mods_zipfile = "{$module_scripts_directory}/basex-bepress-to-mods.zip";
        file_put_contents($basex_bepress_mods_zipfile, $zip_output, FILE_APPEND);
        $zip = new ZipArchive();
        $zip_results = $zip->open($basex_bepress_mods_zipfile);
        if ($zip_results === TRUE) {
            $this->setBasexBepressToModsDir($zip->getNameIndex(0));

            $zip->extractTo( $module_scripts_directory);
            $zip->close();
        }
        unlink($basex_bepress_mods_zipfile);
        $bepress_mods_transform_dir = dirname(__FILE__);
        $bepress_mods_transform_dir = dirname($bepress_mods_transform_dir);
        $bepress_mods_transform_dir  .=  DIRECTORY_SEPARATOR . "scripts";
        $this->setBasexBepressToModsDir($bepress_mods_transform_dir);
    }

    private function getDigitalCommonsToModsTransform() {

        $bepress_mods_transform =  $this->getBasexBepressToModsDir() . $this->getBasexBepressModsTransformName();
        if (! file_exists($bepress_mods_transform) ) {
            throw new ErrorException("{$bepress_mods_transform} does not exist");
        }
        return $bepress_mods_transform;
    }

    /*
     * The code below is problematic
     * for this to work properly, basex needs to be installed in a known directory such that
     * it can be executed via java from this script
     *
     */
    public function executeBaseXTransform($master_catalog_doc) {
        # Path to this script
        $home = getenv('HOME');
        if ( ! $home ) {
            throw new ErrorException("HOME environmental variable is not set");
        }

        $basex_dir="${home}/Share/basex";
        # Core and library classes
        if ( ! file_exists("{$basex_dir}/BaseX.jar") )
        {
            throw new ErrorException("{$basex_dir}/BaseX.jar is not found");
        }

        if ( ! file_exists("{$basex_dir}/lib") || ! is_dir("{$basex_dir}/lib") )
        {
            throw new ErrorException("{$basex_dir}/lib is not found");
        }

        $classpath="${basex_dir}/BaseX.jar:${basex_dir}/lib/*:${basex_dir}/lib/custom/*";

        # Options for virtual machine (can be extended by global options)
        $basex_jvm="-Xmx12g";
        $bepress_mods_transform = $this->getDigitalCommonsToModsTransform();
        $tmp_log_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        # Run code
        $executable =  getJavaExec() . " -cp {$classpath} {$basex_jvm} org.basex.BaseX -bsource_filepath=\"{$master_catalog_doc}\" -o {$tmp_log_dir}/bepress_to_mods_basex.out {$bepress_mods_transform}";

        $results = null;
        $return = null;
        exec($executable, $results, $return);
        return $return;
    }


    /**
     * @return string
     */
    public function getDigitalCommonsTransformationProjectUri()
    {
        return $this->digital_commons_transformation_project_uri;
    }

    /**
     * @param string $digital_commons_transformation_project_uri
     */
    public function setDigitalCommonsTransformationProjectUri($digital_commons_transformation_project_uri)
    {
        $this->digital_commons_transformation_project_uri = $digital_commons_transformation_project_uri;
    }

    /**
     * @return string
     */
    public function getBasexBepressModsTransformName()
    {
        return $this->basex_bepress_mods_transform_name;
    }

    /**
     * @param string $basex_bepress_mods_transform_name
     */
    public function setBasexBepressModsTransformName($basex_bepress_mods_transform_name)
    {
        $this->basex_bepress_mods_transform_name = $basex_bepress_mods_transform_name;
    }

    /**
     * @return null
     */
    public function getBasexBepressToModsDir()
    {
        return $this->basexBepressToModsDir;
    }

    /**
     * @param null $basexBepressToModsDir
     */
    public function setBasexBepressToModsDir($basexBepressToModsDir)
    {
        $this->basexBepressToModsDir = $basexBepressToModsDir;
    }

    /**
     * @return string
     */
    public function getTransformUri()
    {
        return $this->transform_uri;
    }

    /**
     * @param string $transform_uri
     */
    public function setTransformUri($transform_uri)
    {
        $this->transform_uri = $transform_uri;
    }


}