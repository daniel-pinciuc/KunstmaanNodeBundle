<?php

namespace Kunstmaan\NodeBundle\Helper\NodeAdmin;

use Doctrine\ORM\EntityManager;
use Kunstmaan\AdminBundle\Entity\BaseUser;
use Kunstmaan\AdminBundle\Helper\CloneHelper;
use Kunstmaan\AdminBundle\Helper\Security\Acl\Permission\PermissionMap;
use Kunstmaan\NodeBundle\Entity\HasNodeInterface;
use Kunstmaan\NodeBundle\Entity\Node;
use Kunstmaan\NodeBundle\Entity\NodeTranslation;
use Kunstmaan\NodeBundle\Entity\NodeVersion;
use Kunstmaan\NodeBundle\Entity\QueuedNodeTranslationAction;
use Kunstmaan\NodeBundle\Event\Events;
use Kunstmaan\NodeBundle\Event\NodeEvent;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * NodeAdminPublisher
 */
class NodeAdminPublisher
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var SecurityContextInterface
     */
    private $securityContext;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /** @var CloneHelper */
    private $cloneHelper;

    /**
     * @param EntityManager            $em              The entity manager
     * @param SecurityContextInterface $securityContext The security context
     * @param EventDispatcherInterface $eventDispatcher The Event dispatcher
     */
    public function __construct(EntityManager $em, SecurityContextInterface $securityContext, EventDispatcherInterface $eventDispatcher, $cloneHelper)
    {
        $this->em = $em;
        $this->securityContext = $securityContext;
        $this->eventDispatcher = $eventDispatcher;
        $this->cloneHelper = $cloneHelper;
    }

    /**
     * If there is a draft version it'll try to publish the draft first. Makse snese because if you want to publish the public version you don't publish but you save.
     *
     * @param NodeTranslation $nodeTranslation
     *
     * @throws AccessDeniedException
     */
    public function publish(NodeTranslation $nodeTranslation, $user = null)
    {
        if (false === $this->securityContext->isGranted(PermissionMap::PERMISSION_PUBLISH, $nodeTranslation->getNode())) {
            throw new AccessDeniedException();
        }

        if (is_null($user)) {
            $user = $this->securityContext->getToken()->getUser();
        }

        $node = $nodeTranslation->getNode();

        $nodeVersion = $nodeTranslation->getNodeVersion('draft');
        if (!is_null($nodeVersion) && $nodeTranslation->isOnline()) {
            $page = $nodeVersion->getRef($this->em);
            /** @var $nodeVersion NodeVersion */
            $nodeVersion = $this->createPublicVersion($page, $nodeTranslation, $nodeVersion, $user);
            $nodeTranslation = $nodeVersion->getNodeTranslation();
        }else {
            $nodeVersion = $nodeTranslation->getPublicNodeVersion();
        }

        $page = $nodeVersion->getRef($this->em);

        $this->eventDispatcher->dispatch(Events::PRE_PUBLISH, new NodeEvent($node, $nodeTranslation, $nodeVersion, $page));

        $nodeTranslation->setOnline(true);
        $nodeTranslation->setPublicNodeVersion($nodeVersion);

        $this->em->persist($nodeTranslation);
        $this->em->flush();

        // Remove scheduled task
        $this->unSchedulePublish($nodeTranslation);

        $this->eventDispatcher->dispatch(Events::POST_PUBLISH, new NodeEvent($node, $nodeTranslation, $nodeVersion, $page));
    }

    /**
     * @param NodeTranslation $nodeTranslation The NodeTranslation
     * @param \DateTime       $date            The date to publish
     *
     * @throws AccessDeniedException
     */
    public function publishLater(NodeTranslation $nodeTranslation, \DateTime $date)
    {
        $node = $nodeTranslation->getNode();
        if (false === $this->securityContext->isGranted(PermissionMap::PERMISSION_PUBLISH, $node)) {
            throw new AccessDeniedException();
        }

        //remove existing first
        $this->unSchedulePublish($nodeTranslation);

        $user = $this->securityContext->getToken()->getUser();
        $queuedNodeTranslationAction = new QueuedNodeTranslationAction();
        $queuedNodeTranslationAction
           ->setNodeTranslation($nodeTranslation)
           ->setAction(QueuedNodeTranslationAction::ACTION_PUBLISH)
           ->setUser($user)
           ->setDate($date);
        $this->em->persist($queuedNodeTranslationAction);
        $this->em->flush();
    }

    /**
     * @param NodeTranslation $nodeTranslation
     *
     * @throws AccessDeniedException
     */
    public function unPublish(NodeTranslation $nodeTranslation)
    {
        if (false === $this->securityContext->isGranted(PermissionMap::PERMISSION_UNPUBLISH, $nodeTranslation->getNode())) {
            throw new AccessDeniedException();
        }

        $node = $nodeTranslation->getNode();
        $nodeVersion = $nodeTranslation->getPublicNodeVersion();
        $page = $nodeVersion->getRef($this->em);

        $this->eventDispatcher->dispatch(Events::PRE_UNPUBLISH, new NodeEvent($node, $nodeTranslation, $nodeVersion, $page));

        $nodeTranslation->setOnline(false);

        $this->em->persist($nodeTranslation);
        $this->em->flush();

        // Remove scheduled task
        $this->unSchedulePublish($nodeTranslation);

        $this->eventDispatcher->dispatch(Events::POST_UNPUBLISH, new NodeEvent($node, $nodeTranslation, $nodeVersion, $page));
    }

    /**
     * @param NodeTranslation $nodeTranslation The NodeTranslation
     * @param \DateTime       $date            The date to unpublish
     *
     * @throws AccessDeniedException
     */
    public function unPublishLater(NodeTranslation $nodeTranslation, \DateTime $date)
    {
        $node = $nodeTranslation->getNode();
        if (false === $this->securityContext->isGranted(PermissionMap::PERMISSION_UNPUBLISH, $node)) {
            throw new AccessDeniedException();
        }

        //remove existing first
        $this->unSchedulePublish($nodeTranslation);

        $user = $this->securityContext->getToken()->getUser();
        $queuedNodeTranslationAction = new QueuedNodeTranslationAction();
        $queuedNodeTranslationAction
        ->setNodeTranslation($nodeTranslation)
        ->setAction(QueuedNodeTranslationAction::ACTION_UNPUBLISH)
        ->setUser($user)
        ->setDate($date);
        $this->em->persist($queuedNodeTranslationAction);
        $this->em->flush();
    }

    /**
     * @param NodeTranslation $nodeTranslation
     */
    public function unSchedulePublish(NodeTranslation $nodeTranslation)
    {
        /* @var Node $node */
        $queuedNodeTranslationAction = $this->em->getRepository('KunstmaanNodeBundle:QueuedNodeTranslationAction')->findOneBy(array('nodeTranslation' => $nodeTranslation));

        if (!is_null($queuedNodeTranslationAction)) {
            $this->em->remove($queuedNodeTranslationAction);
            $this->em->flush();
        }
    }

    /**
     * This shouldn't be here either but it's an improvement.
     *
     * @param HasNodeInterface $page            The page
     * @param NodeTranslation  $nodeTranslation The node translation
     * @param NodeVersion      $nodeVersion     The node version
     * @param BaseUser         $user            The user
     *
     * @return mixed
     */
    public function createPublicVersion(HasNodeInterface $page, NodeTranslation $nodeTranslation, NodeVersion $nodeVersion, BaseUser $user)
    {
        $newPublicPage = $this->cloneHelper->deepCloneAndSave($page);
        $newNodeVersion = $this->em->getRepository('KunstmaanNodeBundle:NodeVersion')->createNodeVersionFor($newPublicPage, $nodeTranslation, $user, $nodeVersion);

        $newNodeVersion->setOwner($nodeVersion->getOwner());
        $newNodeVersion->setUpdated($nodeVersion->getUpdated());
        $newNodeVersion->setCreated($nodeVersion->getCreated());
        $nodeVersion->setOwner($user);
        $nodeVersion->setCreated(new \DateTime());
        $nodeVersion->setOrigin($newNodeVersion);

        $this->em->persist($newNodeVersion);
        $this->em->persist($nodeVersion);
        $this->em->persist($nodeTranslation);
        $this->em->flush();
        $this->eventDispatcher->dispatch(Events::CREATE_PUBLIC_VERSION, new NodeEvent($nodeTranslation->getNode(), $nodeTranslation, $nodeVersion, $newPublicPage));

        return $newNodeVersion;
    }
}
