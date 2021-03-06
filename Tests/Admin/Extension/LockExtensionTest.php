<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Tests\Admin\Extension;

use PHPUnit\Framework\TestCase;
use Sonata\AdminBundle\Admin\Extension\LockExtension;
use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\Request;

class LockExtensionTest extends TestCase
{
    /**
     * @var LockExtension
     */
    private $lockExtension;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var AdminInterface
     */
    private $admin;

    /**
     * @var LockInterface
     */
    private $modelManager;

    /**
     * @var stdClass
     */
    private $object;

    /**
     * @var Request
     */
    private $request;

    protected function setUp()
    {
        $this->modelManager = $this->prophesize('Sonata\AdminBundle\Model\LockInterface');
        $this->admin = $this->prophesize('Sonata\AdminBundle\Admin\AbstractAdmin');

        $this->eventDispatcher = new EventDispatcher();
        $this->request = new Request();
        $this->object = new \stdClass();
        $this->lockExtension = new LockExtension();
    }

    public function testConfigureFormFields()
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $this->modelManager->reveal());
        $event = new FormEvent($form->reveal(), []);

        $this->modelManager->getLockVersion([])->willReturn(1);

        $form->add(
            '_lock_version',
            // NEXT_MAJOR: remove the check and add the FQCN
            method_exists('Symfony\Component\Form\AbstractType', 'getBlockPrefix')
                ? 'Symfony\Component\Form\Extension\Core\Type\HiddenType'
                : 'hidden',
            ['mapped' => false, 'data' => 1]
        )->shouldBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch(FormEvents::PRE_SET_DATA, $event);
    }

    public function testConfigureFormFieldsWhenModelManagerIsNotImplementingLockerInterface()
    {
        $modelManager = $this->prophesize('Sonata\AdminBundle\Model\ModelManagerInterface');
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $modelManager->reveal());
        $event = new FormEvent($form->reveal(), []);

        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch(FormEvents::PRE_SET_DATA, $event);
    }

    public function testConfigureFormFieldsWhenFormEventHasNoData()
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $event = new FormEvent($form->reveal(), null);

        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch(FormEvents::PRE_SET_DATA, $event);
    }

    public function testConfigureFormFieldsWhenFormHasParent()
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $event = new FormEvent($form->reveal(), []);

        $form->getParent()->willReturn('parent');
        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch(FormEvents::PRE_SET_DATA, $event);
    }

    public function testConfigureFormFieldsWhenModelManagerHasNoLockedVersion()
    {
        $formMapper = $this->configureFormMapper();
        $form = $this->configureForm();
        $this->configureAdmin(null, null, $this->modelManager->reveal());
        $event = new FormEvent($form->reveal(), []);

        $this->modelManager->getLockVersion([])->willReturn(null);
        $form->add()->shouldNotBeCalled();

        $this->lockExtension->configureFormFields($formMapper);
        $this->eventDispatcher->dispatch(FormEvents::PRE_SET_DATA, $event);
    }

    public function testPreUpdateIfAdminHasNoRequest()
    {
        $this->modelManager->lock()->shouldNotBeCalled();

        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfObjectIsNotVersioned()
    {
        $this->configureAdmin();
        $this->modelManager->lock()->shouldNotBeCalled();

        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfRequestDoesNotHaveLockVersion()
    {
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request);

        $this->modelManager->lock()->shouldNotBeCalled();

        $this->request->request->set($uniqid, ['something']);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfModelManagerIsNotImplementingLockerInterface()
    {
        $modelManager = $this->prophesize('Sonata\AdminBundle\Model\ModelManagerInterface');
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request, $modelManager->reveal());

        $this->request->request->set($uniqid, ['_lock_version' => 1]);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    public function testPreUpdateIfObjectIsVersioned()
    {
        $uniqid = 'admin123';
        $this->configureAdmin($uniqid, $this->request, $this->modelManager->reveal());

        $this->modelManager->lock($this->object, 1)->shouldBeCalled();

        $this->request->request->set($uniqid, ['_lock_version' => 1]);
        $this->lockExtension->preUpdate($this->admin->reveal(), $this->object);
    }

    private function configureForm()
    {
        $form = $this->prophesize('Symfony\Component\Form\FormInterface');

        $form->getData()->willReturn($this->object);
        $form->getParent()->willReturn(null);

        return $form;
    }

    private function configureFormMapper()
    {
        $contractor = $this->prophesize('Sonata\AdminBundle\Builder\FormContractorInterface');
        $formFactory = $this->prophesize('Symfony\Component\Form\FormFactoryInterface');
        $formBuilder = new FormBuilder('form', null, $this->eventDispatcher, $formFactory->reveal());

        return new FormMapper($contractor->reveal(), $formBuilder, $this->admin->reveal());
    }

    private function configureAdmin($uniqid = null, $request = null, $modelManager = null)
    {
        $this->admin->getUniqid()->willReturn($uniqid);
        $this->admin->getRequest()->willReturn($request);
        $this->admin->hasRequest()->willReturn(null !== $request);
        $this->admin->getModelManager()->willReturn($modelManager);
    }
}
