<?php
namespace Taskquare\HelloBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class HelloController extends Controller {
  public function indexAction($name, $last_name) {
    return $this->render(
      'TaskquareHelloBundle:Hello:test.html.twig',
      array('name' => $name, 'last_name' => $last_name)
    );
    //return new Response("test" . $name);
  }
}
