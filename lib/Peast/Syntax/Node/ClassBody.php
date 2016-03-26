<?php
namespace Peast\Syntax\Node;

class ClassBody extends Node
{
    protected $body = array();
    
    public function getBody()
    {
        return $this->body;
    }
    
    public function setBody($body)
    {
        $this->assertArrayOf($body, "MethodDefinition");
        $this->body = $body;
        return $this;
    }
    
    public function getSource()
    {
        return $this->nodeListToSource($this->getBody());
    }
}