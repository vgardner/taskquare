<?php

/* TaskquareHelloBundle:Hello:test.html.twig */
class __TwigTemplate_e8062645f3d62fca0b486cfa53fde6b1 extends Twig_Template
{
    public function __construct(Twig_Environment $env)
    {
        parent::__construct($env);

        $this->parent = false;

        $this->blocks = array(
        );
    }

    protected function doDisplay(array $context, array $blocks = array())
    {
        // line 1
        echo "Hey ";
        echo twig_escape_filter($this->env, (isset($context["name"]) ? $context["name"] : $this->getContext($context, "name")), "html", null, true);
        echo " ";
        echo twig_escape_filter($this->env, (isset($context["last_name"]) ? $context["last_name"] : $this->getContext($context, "last_name")), "html", null, true);
        echo "
";
    }

    public function getTemplateName()
    {
        return "TaskquareHelloBundle:Hello:test.html.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  19 => 1,);
    }
}
