<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Application\Config;
use Icinga\Module\Starlink\Forms\Config\BackendConfigForm;
use Icinga\Web\Controller\ModuleActionController;

class ConfigController extends ModuleActionController
{
    public function init()
    {
        $this->assertPermission('config/modules');
    }

    public function backendAction()
    {
        $form = (new BackendConfigForm())->setIniConfig(Config::module('starlink'));
        $form->handleRequest();

        $this->view->tabs = $this->Module()->getConfigTabs()->activate('backend');
        $this->view->form = $form;
    }
}
