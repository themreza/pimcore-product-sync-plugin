<?php

namespace SintraPimcoreBundle\EventListener\General;

use Pimcore\Logger;
use Pimcore\Model\DataObject\Product;
use SintraPimcoreBundle\EventListener\AbstractObjectListener;

/**
 * Implementation of ObjectListener
 * 
 * @author Marco Guiducci
 */
class ObjectListener extends AbstractObjectListener{
    
    protected $isPublishedBeforeSave;
    
    public function setIsPublishedBeforeSave($isPublishedBeforeSave){
        $this->isPublishedBeforeSave = $isPublishedBeforeSave;
    }
    
    /**
     * Dispatch the preAdd event to the specific class listener
     * If the object class is not managed for the preUpdate event, do nothing
     * 
     * @param Product $dataObject the object to update
     */
    public function preAddDispatcher($dataObject) {
        $className = strtolower($dataObject->o_className);

        switch ($className) {

            case "product":
                $productListener = new ProductListener();
                $productListener->preAddAction($dataObject);
                break;

            default:
                Logger::debug("ObjectListener - Class '".$className."' is not Managed for preAdd");
                break;
        }
    }

    /**
     * Dispatch the preUpdate event to the specific class listener
     * If the object class is not managed for the preUpdate event, do nothing
     * 
     * @param Product $dataObject the object to update
     */
    public function preUpdateDispatcher($dataObject) {
        $className = strtolower($dataObject->o_className);

        switch ($className) {

            case "product":
                $productListener = new ProductListener();
                $productListener->preUpdateAction($dataObject);
                break;

            default:
                Logger::debug("ObjectListener - Class '".$className."' is not Managed for preUpdate");
                break;
        }
    }
    

    /**
     * Dispatch the postUpdate event to the specific class listener
     * If the object class is not managed for the postUpdate event, do nothing
     * 
     * @param Product $dataObject the updated object
     */
    public function postUpdateDispatcher($dataObject, $saveVersionOnly) {
        $className = strtolower($dataObject->o_className);

        switch ($className) {

            case "product":
                $productListener = new ProductListener();
                $productListener->postUpdateAction($dataObject);
                break;

            default:
                Logger::debug("ObjectListener - Class '".$className."' is not Managed for preUpdate");
                break;
        }
    }

    
    /**
     * Dispatch the postDelete event to the specific class listener
     * If the object class is not managed for the postDelete event, do nothing
     * 
     * @param Product $dataObject the deleted object
     */
    public function postDeleteDispatcher($dataObject) {
        
    }
}