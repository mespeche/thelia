<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Controller\Admin;

use Symfony\Component\Config\Definition\Exception\Exception;
use Thelia\Core\Event\Module\ModuleDeleteEvent;
use Thelia\Core\Event\Module\ModuleEvent;
use Thelia\Core\Event\Module\ModuleInstallEvent;
use Thelia\Core\Event\Module\ModuleToggleActivationEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Exception\InvalidModuleException;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Form\ModuleInstallForm;
use Thelia\Form\ModuleModificationForm;
use Thelia\Log\Tlog;
use Thelia\Model\ModuleQuery;
use Thelia\Module\ModuleManagement;

/**
 * Class ModuleController
 * @package Thelia\Controller\Admin
 * @author  Manuel Raynaud <manu@thelia.net>
 */
class ModuleController extends AbstractCrudController
{
    protected $moduleErrors = [];

    public function __construct()
    {
        parent::__construct(
            'module',
            'manual',
            'module_order',
            AdminResources::MODULE,
            null,
            TheliaEvents::MODULE_UPDATE,
            null,
            null,
            TheliaEvents::MODULE_UPDATE_POSITION
        );
    }

    protected function getCreationForm()
    {
        return null;
    }

    protected function getUpdateForm()
    {
        return new ModuleModificationForm($this->getRequest());
    }

    protected function getCreationEvent($formData)
    {
        return null;
    }

    protected function getUpdateEvent($formData)
    {
        $event = new ModuleEvent();

        $event->setLocale($formData['locale']);
        $event->setId($formData['id']);
        $event->setTitle($formData['title']);
        $event->setChapo($formData['chapo']);
        $event->setDescription($formData['description']);
        $event->setPostscriptum($formData['postscriptum']);

        return $event;
    }

    protected function getDeleteEvent()
    {
        return null;
    }

    protected function createUpdatePositionEvent($positionChangeMode, $positionValue)
    {
        return new UpdatePositionEvent(
            $this->getRequest()->get('module_id', null),
            $positionChangeMode,
            $positionValue
        );
    }

    protected function eventContainsObject($event)
    {
        return $event->hasModule();
    }

    protected function hydrateObjectForm($object)
    {
        $object->setLocale($this->getCurrentEditionLocale());
        $data = array(
            'id'           => $object->getId(),
            'locale'       => $object->getLocale(),
            'title'        => $object->getTitle(),
            'chapo'        => $object->getChapo(),
            'description'  => $object->getDescription(),
            'postscriptum' => $object->getPostscriptum(),
        );

        // Setup the object form
        return new ModuleModificationForm($this->getRequest(), "form", $data);
    }

    protected function getObjectFromEvent($event)
    {
        return $event->hasModule() ? $event->getModule() : null;
    }

    protected function getExistingObject()
    {
        $module = ModuleQuery::create()
            ->findOneById($this->getRequest()->get('module_id', 0));

        if (null !== $module) {
            $module->setLocale($this->getCurrentEditionLocale());
        }

        return $module;
    }

    protected function getObjectLabel($object)
    {
        return $object->getTitle();
    }

    protected function getObjectId($object)
    {
        return $object->getId();
    }

    protected function getViewArguments()
    {
        return array();
    }

    protected function getRouteArguments($module_id = null)
    {
        return array(
            'module_id' => $module_id === null ? $this->getRequest()->get('module_id') : $module_id,
        );
    }

    protected function renderListTemplate($currentOrder)
    {
        // We always return to the feature edition form
        return $this->render(
            'modules',
            array(
                'module_order'  => $currentOrder,
                'module_errors' => $this->moduleErrors
            )
        );
    }

    protected function renderEditionTemplate()
    {
        // We always return to the feature edition form
        return $this->render('module-edit', array_merge($this->getViewArguments(), $this->getRouteArguments()));
    }

    protected function redirectToEditionTemplate($request = null, $country = null)
    {
        return $this->generateRedirectFromRoute(
            "admin.module.update",
            $this->getViewArguments(),
            $this->getRouteArguments()
        );
    }

    protected function redirectToListTemplate()
    {
        return $this->generateRedirectFromRoute("admin.module");
    }

    public function indexAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, array(), AccessManager::VIEW)) {
            return $response;
        }

        try {
            $moduleManagement = new ModuleManagement();
            $moduleManagement->updateModules();
        } catch (InvalidModuleException $ex) {
            $this->moduleErrors = $ex->getErrors();
        } catch (Exception $ex) {
            Tlog::getInstance()->addError("Failed to get modules list:", $ex);
        }

        return $this->renderList();
    }

    public function configureAction($module_code)
    {
        $module = ModuleQuery::create()->findOneByCode($module_code);

        if (null === $module) {
            throw new \InvalidArgumentException(sprintf("Module `%s` does not exists", $module_code));
        }

        if (null !== $response = $this->checkAuth(array(), $module_code, AccessManager::VIEW)) {
            return $response;
        }
        return $this->render(
            "module-configure",
            array(
                "module_code" => $module_code,
            )
        );
    }

    public function toggleActivationAction($module_id)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, array(), AccessManager::UPDATE)) {
            return $response;
        }
        $message = null;
        try {
            $event = new ModuleToggleActivationEvent($module_id);
            $this->dispatch(TheliaEvents::MODULE_TOGGLE_ACTIVATION, $event);

            if (null === $event->getModule()) {
                throw new \LogicException(
                    $this->getTranslator()->trans("No %obj was updated.", array('%obj' => 'Module'))
                );
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();

            Tlog::getInstance()->addError("Failed to activate/deactivate module:", $e);
        }

        if ($this->getRequest()->isXmlHttpRequest()) {
            if ($message) {
                $response = $this->jsonResponse(json_encode(array(
                    "error" => $message
                )), 500);
            } else {
                $response = $this->nullResponse();
            }
        } else {
            $response = $this->generateRedirectFromRoute('admin.module');
        }

        return $response;
    }

    public function deleteAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, array(), AccessManager::DELETE)) {
            return $response;
        }

        $message = false;

        try {
            $this->getTokenProvider()->checkToken(
                $this->getRequest()->query->get("_token")
            );

            $module_id = $this->getRequest()->get('module_id');

            $deleteEvent = new ModuleDeleteEvent($module_id);

            $deleteEvent->setDeleteData('1' == $this->getRequest('delete-module-data', '0'));

            $this->dispatch(TheliaEvents::MODULE_DELETE, $deleteEvent);

            if ($deleteEvent->hasModule() === false) {
                throw new \LogicException(
                    $this->getTranslator()->trans("No %obj was updated.", array('%obj' => 'Module'))
                );
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();

            Tlog::getInstance()->addError("Error during module removal", $e);
        }

        if (false !== $message) {
            $response = $this->render("modules", array(
                "error_message" => $message
            ));
        } else {
            $response = $this->generateRedirectFromRoute('admin.module');
        }

        return $response;
    }

    public function installAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, array(), AccessManager::CREATE)) {
            return $response;
        }

        $newModule        = null;
        $moduleDefinition = null;

        $moduleInstall = new ModuleInstallForm($this->getRequest());

        try {
            $form = $this->validateForm($moduleInstall, "post");

            $moduleDefinition = $moduleInstall->getModuleDefinition();
            $modulePath       = $moduleInstall->getModulePath();

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $form->get("module")->getData();

            $moduleInstallEvent = new ModuleInstallEvent();
            $moduleInstallEvent
                ->setModulePath($modulePath)
                ->setModuleDefinition($moduleDefinition);

            $this->dispatch(TheliaEvents::MODULE_INSTALL, $moduleInstallEvent);

            $newModule = $moduleInstallEvent->getModule();

            if (null !== $newModule) {
                $this->getSession()->getFlashBag()->add(
                    'module-installed',
                    $this->getTranslator()->trans(
                        'The module %module has been installed successfully.',
                        ['%module' => $moduleDefinition->getCode()]
                    )
                );

                return $this->generateRedirectFromRoute('admin.module');
            } else {
                $message = $this->getTranslator()->trans(
                    "Sorry, an error occured."
                );
            }
        } catch (FormValidationException $e) {
            $message = $e->getMessage();
        } catch (\Exception $e) {
            $message = $this->getTranslator()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()]);
        }

        if ($message !== false) {
            Tlog::getInstance()->error(sprintf("Error during module installation process. Exception was %s", $message));

            $moduleInstall->setErrorMessage($message);

            $this->getParserContext()
                ->addForm($moduleInstall)
                ->setGeneralError($message);

            return $this->render("modules");
        }
    }
}
