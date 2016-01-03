<?php

namespace AppBundle\Controller;

use AppBundle\Entity\EmailCopyJob;
use AppBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ManageController extends Controller
{
    private function getGoogleClient(User $user)
    {
        return $this->get('google_api_oauth_client')->getClient($user);
    }

    /**
     * @return EntityManagerInterface
     */
    private function getEntityManager()
    {
        return $this->get('doctrine.orm.default_entity_manager');
    }

    /**
     * @Route("/manage", name="manage-copyjobs")
     */
    public function manageCopyjobsAction(Request $request)
    {
        $gmail = new \Google_Service_Gmail($this->getGoogleClient($this->getUser()));

        /** @var \Google_Service_Gmail_ListLabelsResponse $labels */
        $labels = $gmail->users_labels->listUsersLabels($this->getUser()->getGoogleId());
        $formLabels = [];
        /** @var \Google_Service_Gmail_Label $label */
        foreach ($labels->getLabels() as $label) {
            if ('labelHide' === $label->getLabelListVisibility()) {
                continue;
            }

            $formLabels[$label->getId()] = $label->getName();
        }

        asort($formLabels);

        $copy = new EmailCopyJob();
        $form = $this->createFormBuilder($copy)
            ->add('name', 'Symfony\Component\Form\Extension\Core\Type\TextType', array(
                'help' => 'This will be also be the name (not the email address) of the Google Group.<br />Use something that makes sense out of context, like "Colin\'s Client A emails"'
            ))
            ->add('labels', 'Symfony\Component\Form\Extension\Core\Type\ChoiceType', array(
                'choices' => $formLabels,
                'required' => true,
                'multiple' => true,
                'choices_as_values' => true,
                'attr' => array('class' => 'chosen')
            ))
            ->add('ignored_labels', 'Symfony\Component\Form\Extension\Core\Type\ChoiceType', array(
                'choices' => $formLabels,
                'required' => true,
                'multiple' => true,
                'required' => false,
                'choices_as_values' => true,
                'attr' => array('class' => 'chosen')
            ))
            ->add('save', 'Symfony\Component\Form\Extension\Core\Type\SubmitType')
            ->getForm();

        $form->handleRequest($request);

        if (count(array_intersect($copy->getLabels(), $copy->getIgnoredLabels())) > 0) {
            $form->addError(new FormError('You can\'t have the same labels in the labels and ignored labels fields'));
        }

        if ($form->isValid()) {
            $copy->setUser($this->getUser());
            $copy->setUserId($this->getUser()->getId());

            $this->addGoogleGroup($copy);
            $this->getEntityManager()->persist($copy);
            $this->getEntityManager()->flush();

            $this->addGmailWatch($copy);

            //TODO:
            // - trigger initial import
            // - make sure regular imports happen
            //return $this->redirectToRoute('setup_success');
        }

        $viewCopies = [];

        /** @var EmailCopyJob $copy */
        foreach ($this->getUser()->getCopies() AS $copy) {
            $labelNames = [];
            foreach ($copy->getLabels() as $labelId) {
                $labelNames[] = $formLabels[$labelId];
            }
            $viewCopies[] = array(
                'id' => $copy->getId(),
                'name' => $copy->getName(),
                'labelNames' => $labelNames,
                'googleGroupUrl' => $copy->getGroupUrl()
            );
        }

        // replace this example code with whatever you need
        return $this->render('default/manage.html.twig', array(
            'copies' => $viewCopies,
            'form' => $form->createView()
        ));
    }

    private function addGoogleGroup(EmailCopyJob $copy)
    {
        $directoryClient = $this->get('google.directory');

        $userEmail = $copy->getUser()->getEmail();
        $group = new \Google_Service_Directory_Group();
        $groupId = uniqid('tomai-');
        $group->setEmail($groupId . '@' . $this->getParameter('google_apps_domain'));
        $group->setName($copy->getName());
        $group->setDescription('Tomai-generated group by ' . $userEmail);
        $groupResponse = $directoryClient->groups->insert($group);

        $copy->setGroupEmail($groupResponse->getEmail());
        $copy->setGroupUrl('https://groups.google.com/a/' . $this->getParameter('google_apps_domain') . '/forum/#!forum/' . $groupId);
        // Copy is saved after. not nice.

        // set settings
        $groupSettingsClient = $this->get('google.groups_settings');
        $groupSettings = new \Google_Service_Groupssettings_Groups();
        $groupSettings->setWhoCanPostMessage("ALL_MANAGERS_CAN_POST");
        $groupSettings->setWhoCanViewGroup("ALL_IN_DOMAIN_CAN_VIEW");
        $groupSettings->setIncludeInGlobalAddressList("false");
        $groupSettings->setAllowWebPosting("false");
        $groupSettings->setShowInGroupDirectory("true");
        $groupSettingsClient->groups->patch($groupResponse->getEmail(), $groupSettings);
    }

    //TODO: make this a POST
    /**
     * @Route("/deletecopy/{id}", name="delete-copy")
     */
    public function deleteCopyAction(Request $request, $id)
    {
        /** @var EmailCopyJob $copy */
        $copy = $this->getEntityManager()->getRepository('AppBundle:EmailCopyJob')->find($id);

        if ($copy->getUser() != $this->getUser()) {
            throw new HttpException('403', 'Invalid User');
        }

        $this->get('google.directory')->groups->delete($copy->getGroupEmail());

        $this->getEntityManager()->remove($copy);
        $this->getEntityManager()->flush();

        $this->redirectToRoute('manage-copyjobs');
    }

    private function addGmailWatch(EmailCopyJob $copy)
    {
        $topicName = 'projects/email-copier/topics/test1'; //TODO: make this come from config

        $gmail = new \Google_Service_Gmail($this->getGoogleClient($this->getUser()));
        $watchRequest = new \Google_Service_Gmail_WatchRequest();
        $watchRequest->setTopicName($topicName);

        // Let's just handle the filtering on our side, makes it a bit easier
        //$watchRequest->setLabelIds($copy->getLabels());
        $watchResponse = $gmail->users->watch($this->getUser()->getGoogleId(), $watchRequest);

        $copyUser = $copy->getUser();
        if (!$copyUser->getGmailHistoryId()) {
            $copyUser->setGmailHistoryId($watchResponse->getHistoryId());
            $this->getEntityManager()->persist($copyUser);
            $this->getEntityManager()->flush();
        }
    }
}
