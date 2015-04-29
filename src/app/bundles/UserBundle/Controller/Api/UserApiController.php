<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\UserBundle\Controller\Api;

use FOS\RestBundle\Util\Codes;
use JMS\Serializer\SerializationContext;
use Mautic\ApiBundle\Controller\CommonApiController;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class UserApiController
 */
class UserApiController extends CommonApiController
{

    /**
     * {@inheritdoc}
     */
    public function initialize (FilterControllerEvent $event)
    {
        parent::initialize($event);
        $this->model            = $this->factory->getModel('user.user');
        $this->entityClass      = 'Mautic\UserBundle\Entity\User';
        $this->entityNameOne    = 'user';
        $this->entityNameMulti  = 'users';
        $this->permissionBase   = 'user:users';
        $this->serializerGroups = array('userDetails', 'roleList', 'publishDetails');
    }

    /**
     * Obtains a list of users
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getEntitiesAction ()
    {
        return parent::getEntitiesAction();
    }

    /**
     * Obtains a specific user
     *
     * @param int $id User ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function getEntityAction ($id)
    {
        return parent::getEntityAction($id);
    }


    /**
     * Obtains the logged in user's data
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function getSelfAction ()
    {
        $currentUser = $this->get('security.context')->getToken()->getUser();
        $view        = $this->view($currentUser, Codes::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * Deletes a user
     *
     * @param int $id User ID
     *
     * @return Response
     */
    public function deleteEntityAction ($id)
    {
        if (!$this->factory->getSecurity()->isGranted('user:users:delete')) {
            return $this->accessDenied();
        }

        return parent::deleteEntityAction($id);
    }

    /**
     * Creates a new user
     */
    public function newEntityAction ()
    {
        $entity = $this->model->getEntity();

        if (!$this->factory->getSecurity()->isGranted('user:users:create')) {
            return $this->accessDenied();
        }

        $parameters = $this->request->request->all();

        if (isset($parameters['plainPassword']['password'])) {
            $submittedPassword = $parameters['plainPassword']['password'];
            $encoder           = $this->get('security.encoder_factory')->getEncoder($entity);
            $entity->setPassword($this->model->checkNewPassword($entity, $encoder, $submittedPassword));
        }

        return $this->processForm($entity, $parameters, 'POST');
    }


    /**
     * Edits an existing user or creates a new one on PUT if not found
     *
     * @param int $id User ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws NotFoundHttpException
     */
    public function editEntityAction ($id)
    {
        $entity     = $this->model->getEntity($id);
        $parameters = $this->request->request->all();
        $method     = $this->request->getMethod();

        if (!$this->factory->getSecurity()->isGranted('user:users:edit')) {
            return $this->accessDenied();
        }

        if ($entity === null) {
            if ($method === "PATCH" ||
                ($method === "PUT" && !$this->factory->getSecurity()->isGranted('user:users:create'))
            ) {
                //PATCH requires that an entity exists or must have create access for PUT
                return $this->notFound();
            } else {
                $entity = $this->model->getEntity();
                if (isset($parameters['plainPassword']['password'])) {
                    $submittedPassword = $parameters['plainPassword']['password'];
                    $encoder           = $this->get('security.encoder_factory')->getEncoder($entity);
                    $entity->setPassword($this->model->checkNewPassword($entity, $encoder, $submittedPassword));
                }
            }
        } else {
            //Changing passwords via API is forbidden
            if (!empty($parameters['plainPassword'])) {
                unset($parameters['plainPassword']);
            }
            if ($method == "PATCH") {
                //PATCH will accept a diff so just remove the entities

                //Changing username via API is forbidden
                if (!empty($parameters['username'])) {
                    unset($parameters['username']);
                }

                //Changing the role via the API is forbidden
                if (!empty($parameters['role'])) {
                    unset($parameters['role']);
                }

            } else {
                //PUT requires the entire entity so overwrite the username with the original
                $parameters['username'] = $entity->getUsername();
                $parameters['role']     = $entity->getRole()->getId();
            }
        }

        return $this->processForm($entity, $parameters, $method);
    }

    /**
     * Verifies if a user has permission(s) to a action
     *
     * @param int $id User ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
     */
    public function isGrantedAction ($id)
    {
        $entity = $this->model->getEntity($id);
        if (!$entity instanceof $this->entityClass) {
            return $this->notFound();
        }

        $permissions = $this->request->request->get('permissions');

        if (empty($permissions)) {
            return $this->badRequest('mautic.api.call.permissionempty');
        } elseif (!is_array($permissions)) {
            $permissions = array($permissions);
        }

        $return = $this->factory->getSecurity()->isGranted($permissions, "RETURN_ARRAY", $entity);
        $view   = $this->view($return, Codes::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * Obtains a list of roles for user edits
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getRolesAction ()
    {
        if (!$this->factory->getSecurity()->isGranted(
            array('user:users:create', 'user:users:edit'),
            'MATCH_ONE'
        )
        ) {
            return $this->accessDenied();
        }

        $filter = $this->request->query->get('filter', null);
        $limit  = $this->request->query->get('limit', null);
        $roles  = $this->factory->getModel('user')->getLookupResults('role', $filter, $limit);

        $view    = $this->view($roles, Codes::HTTP_OK);
        $context = SerializationContext::create()->setGroups(array('roleList'));
        $view->setSerializationContext($context);

        return $this->handleView($view);
    }
}