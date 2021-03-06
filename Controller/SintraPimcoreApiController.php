<?php

namespace SintraPimcoreBundle\Controller;

use Pimcore\Bundle\AdminBundle\Controller\AdminControllerInterface;
use Pimcore\Controller\Controller;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\SintraPimcoreBundleConfiguration;
use Pimcore\Logger;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use SintraPimcoreBundle\Utils\ExportUtils;

/**
 * Controller for SintraPimcoreApiController
 * Provide the method to export objects definition
 *
 * @author Sintra Consulting
 *
 * @Route("/sintra_pimcore/api")
 */
class SintraPimcoreApiController extends Controller implements AdminControllerInterface {

    /**
     * @inheritDoc
     */
    public function needsSessionDoubleAuthenticationCheck() {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function needsStorageDoubleAuthenticationCheck() {
        return false;
    }

    /**
     * Export objects definition
     * 
     * @return Response The objects definition
     * @throws \Exception
     *
     * @Route("/export")
     */
    public function export(Request $request) {
        $export = array();
        $response = array();
        
        $configurationListing = new SintraPimcoreBundleConfiguration\Listing();
        
        if($configurationListing->getTotalCount() === 0){
            throw new \Exception("There is no object instance of SintraPimcoreBundleConfiguration class. "
                    . "Please create that object and fill the required informations");
        }
        
        $config = $configurationListing->current();
        
        $baseurl = $config->getPimcoreBaseUrl();
        
        if($baseurl == null || empty($baseurl)){
            throw new \Exception("Pimcore base URL not configured in SintraPimcoreBundleConfiguration object instance");
        }
        
        $timestamp = $request->get("timestamp");
        $exportAll = $request->get("exportAll");
        $offset = $request->get("offset");
        $limit = $request->get("limit");
        $writeInFile = $request->get("writeInFile");
        
        $exportFolder = $config->getExportFolder();
        
        if($writeInFile == 1 && ($exportFolder == null || empty($exportFolder))){
            throw new \Exception("Pimcore Export Folder not configured in SintraPimcoreBundleConfiguration object instance");
        }
        
        $products = new Product\Listing();
        $products->setObjectTypes(array(AbstractObject::OBJECT_TYPE_OBJECT));
        $products->setUnpublished(true);
        
        if($timestamp != null && !empty($timestamp) && (is_numeric($timestamp) && (int)$timestamp == $timestamp)){
            $products->setCondition("o_modificationDate >= ?",$timestamp);
            $response["timestamp"] = $timestamp;
        }
        
        if($exportAll != 1){
            if(!($offset != null && !empty($offset) && (is_numeric($offset) && (int)$offset == $offset))){
                $offset = 0;
            }
            
            $products->setOffset($offset);
            $response["offset"] = $offset;

            if(!($limit != null && !empty($limit) && (is_numeric($limit) && (int)$limit == $limit))){
                $limit = 100;
            }
            
            $products->setLimit($limit);
            $response["limit"] = $limit;
            
            $nextPage = clone($products);
            $nextPage->setOffset($offset+$limit);
            $response["nextPageExists"] = ($nextPage->getCount() > 0);
        }else{
            $response["nextPageExists"] = false;
        }

        foreach ($products->getObjects() as $product) {
            ExportUtils::exportProduct($export["products"], $product, $baseurl);
        }
        
        $response["productsNumber"] = count($export["products"]);
        
        $exportJson = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if($writeInFile != 1){
            $response["data"] = $export;
        }else{
            if(!is_dir($exportFolder)){
                mkdir($exportFolder, 0777, true);
            }
        
            $filename = "products_".date("YmdHis").".json";
            $response["filename"] = $filename;
            
            $fh = fopen($exportFolder.DIRECTORY_SEPARATOR.$filename, 'w') or die("can't open file '".$exportFolder.DIRECTORY_SEPARATOR.$filename."'");
            fwrite($fh, $exportJson);
            fclose($fh);
        }
        
        return new Response(json_encode($response));
    }

}
