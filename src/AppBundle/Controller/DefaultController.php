<?php

namespace AppBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    /**
     * @return EntityManagerInterface
     */
    private function getEntityManager()
    {
        return $this->get('doctrine.orm.default_entity_manager');
    }

    /**
     * @Route("/", name="index")
     * @Method({"GET"})
     */
    public function indexAction()
    {
        return $this->render('default/index.html.twig');
    }

    /**
     * @Route("/list", name="public-list")
     */
    public function publicListAction(Request $request)
    {
        $copyJobs = $this->getEntityManager()->getRepository('AppBundle:EmailCopyJob')->findMessagesWithUserForList();

        return $this->render('default/list.html.twig', array('copyJobs' => $copyJobs));
    }
}
