<?php

namespace TMCms\Routing;

use TMCms\Templates\VisualEdit;

defined('INC') or exit;

/**
 * Class View
 */
class View
{
    /**
     * @var MVC $mvc_instance
     */
    protected $mvc_instance;
    protected $data = array();

    /**
     * @param MVC $mvc
     */
    public function __construct(MVC $mvc)
    {
        $this->mvc_instance = $mvc;
    }

    private function refreshData()
    {
        $this->data = $this->mvc_instance->getData();
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getValue($key)
    {
        $this->refreshData();

        $res = isset($this->data[$key]) ? $this->data[$key] : $this->getUnsetComponent($key);

        // Visual edit
        if ($res !== NULL && VisualEdit::getInstance()->isEnabled()) {
            $res = VisualEdit::getInstance()->wrapAroundComponents($this->mvc_instance->getController(), $key, $res);
        }

        return $res;
    }

    /**
     * @param string $key
     * @return string
     */
    private function getUnsetComponent($key)
    {
        $controller = $this->mvc_instance->getController();
        /**
         * @var Controller $controller
         */
        $controller = new $controller($this->mvc_instance);

        return $controller->getComponentValue($key);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getValue($key);
    }

    /**
     * This function is called first after View creation - use it to load shared data
     */
    public function setUp() {

    }
}