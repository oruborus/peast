<?php
namespace Peast\Syntax\Node;

class ObjectExpression extends Node implements Expression
{
    protected $properties = array();
    
    public function getProperties()
    {
        return $this->properties;
    }
    
    public function setBody($properties)
    {
        $this->assertArrayOf($properties, "Property");
        $this->properties = $properties;
        return $this;
    }
    
    public function compile()
    {
        return "{" . $this->compileNodeList($this->getProperties(), ",") . "}";
    }
}